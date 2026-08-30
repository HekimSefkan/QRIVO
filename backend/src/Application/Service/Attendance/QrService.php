<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Attendance;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Attendance\QrPayload;
use QRIVO\Domain\Attendance\QrValidationResult;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\QrValidationReason;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;

/**
 * Dynamic QR system — ATTENDANCE_ALGORITHM.md §3 and SECURITY_RULES.md §5,
 * implemented exactly. The algorithm is NOT redesigned.
 *
 *   generation ...... {session UUID, unix timestamp, 16-byte hex nonce,
 *                      HMAC-SHA256 signature}; refreshes each TTL with a new nonce
 *   signing ......... HMAC-SHA256 keyed by `attendance_sessions.session_secret`
 *                     (DD-002) — the secret never leaves the backend
 *   expiry .......... server-side timestamp check against the configured TTL
 *   validation ...... shape → session ACTIVE → not expired → signature → not replayed
 *   replay .......... `qr_used_nonces` (nonce + expiration — §10 / §5)
 *
 * A QR does NOT create attendance; it is only validated here and (in Phase 12)
 * exchanged for a challenge.
 */
final class QrService extends BaseService
{
    private readonly int $ttl;
    private readonly int $refresh;
    private readonly int $skew;

    public function __construct(
        LoggerInterface $logger,
        private readonly AttendanceSessionRepository $sessions,
        private readonly QrNonceRepository $nonces,
        private readonly RelationshipRepository $relationships,
        private readonly SecurityLogService $securityLog,
        Config $config,
    ) {
        parent::__construct($logger);
        $this->ttl     = max(5, $config->getInt('attendance.qr.ttl_seconds', 30));
        $this->refresh = max(1, $config->getInt('attendance.qr.refresh_seconds', $this->ttl));
        $this->skew    = max(0, $config->getInt('attendance.qr.clock_skew_seconds', 5));
    }

    // ─── Generation (teacher, own ACTIVE session) ─────────────────────────────

    /**
     * @param array<string, mixed>   $actor
     * @return array<string, mixed>
     * @throws NotFoundException|ForbiddenException|ConflictException
     */
    public function currentQrForOwnedSession(array $actor, int $sessionId, ?\DateTimeImmutable $at = null): array
    {
        $session = $this->sessions->findRow($sessionId);
        if ($session === null) {
            throw new NotFoundException('Attendance session not found.');
        }

        $teacherId = $this->relationships->findTeacherIdForUser((int) $actor['user_id']);
        if ($teacherId === null || (int) $session['teacher_id'] !== $teacherId) {
            $this->securityLog->recordSecurityEvent(
                \QRIVO\Domain\Enum\SecurityEventType::IDOR_ATTEMPT,
                'HIGH',
                (int) $actor['user_id'],
                $this->ip($actor),
                $this->ua($actor),
                ['action' => 'generate attendance QR', 'attendance_session_id' => $sessionId],
            );
            throw new ForbiddenException('You are not authorized to generate a QR for this session.');
        }

        if (($session['status'] ?? null) !== 'ACTIVE') {
            throw new ConflictException('The attendance session is not active.');
        }

        return $this->generate($session, $at ?? new \DateTimeImmutable('now'));
    }

    /**
     * Build the current QR for a session row.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function generate(array $session, \DateTimeImmutable $at): array
    {
        $timestamp = $at->getTimestamp();
        $nonce     = bin2hex(random_bytes(16));

        $unsigned  = new QrPayload((string) $session['uuid'], $timestamp, $nonce, '');
        $signature = $this->sign($unsigned->signedMessage(), (string) $session['session_secret']);
        $payload   = new QrPayload((string) $session['uuid'], $timestamp, $nonce, $signature);

        return [
            'qr_string'       => $payload->encode(),
            'payload'         => $payload->toArray(),
            'ttl_seconds'     => $this->ttl,
            'refresh_seconds' => $this->refresh,
            'generated_at'    => $at->format('c'),
            'expires_at'      => (clone $at)->modify("+{$this->ttl} seconds")->format('c'),
        ];
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    /**
     * Validate a scanned QR string (NON-consuming).
     */
    public function validate(string $qrString, ?string $expectedSessionUuid = null, ?\DateTimeImmutable $at = null): QrValidationResult
    {
        $at ??= new \DateTimeImmutable('now');

        $payload = QrPayload::decode($qrString);
        if ($payload === null) {
            return QrValidationResult::invalid(QrValidationReason::MALFORMED);
        }

        if ($expectedSessionUuid !== null && !hash_equals($expectedSessionUuid, $payload->sessionUuid)) {
            return QrValidationResult::invalid(QrValidationReason::WRONG_SESSION, $payload);
        }

        $session = $this->sessions->findByUuid($payload->sessionUuid);
        if ($session === null) {
            return QrValidationResult::invalid(QrValidationReason::SESSION_NOT_FOUND, $payload);
        }
        if (($session['status'] ?? null) !== 'ACTIVE') {
            return QrValidationResult::invalid(QrValidationReason::SESSION_NOT_ACTIVE, $payload);
        }

        // Expiry — server-side timestamp check (SECURITY_RULES.md §5).
        $age = $at->getTimestamp() - $payload->timestamp;
        if ($age > $this->ttl || $age < -$this->skew) {
            return QrValidationResult::invalid(QrValidationReason::EXPIRED, $payload);
        }

        // Signature — HMAC-SHA256, constant-time comparison.
        $expected = $this->sign($payload->signedMessage(), (string) $session['session_secret']);
        if (!hash_equals($expected, $payload->signature)) {
            return QrValidationResult::invalid(QrValidationReason::BAD_SIGNATURE, $payload);
        }

        // Replay — has this QR nonce already been consumed?
        if ($this->nonces->nonceExists($payload->nonce)) {
            return QrValidationResult::invalid(QrValidationReason::REPLAYED, $payload);
        }

        return QrValidationResult::valid($payload, (int) $session['id']);
    }

    /**
     * Validate + atomically consume the QR nonce. This is what the challenge
     * request (Phase 12) calls. A concurrent/repeat consumption yields REPLAYED.
     *
     * @param array<string, mixed> $actor
     */
    public function validateAndConsume(
        array $actor,
        string $qrString,
        ?string $expectedSessionUuid = null,
        ?\DateTimeImmutable $at = null,
    ): QrValidationResult {
        $at ??= new \DateTimeImmutable('now');

        $result = $this->validate($qrString, $expectedSessionUuid, $at);
        if (!$result->isValid()) {
            $this->recordOutcome($actor, $result, 'MEDIUM');
            return $result;
        }

        try {
            $this->nonces->consume((int) $result->sessionId, $result->payload->nonce, $at->format('Y-m-d H:i:s'));
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                $replay = QrValidationResult::invalid(QrValidationReason::REPLAYED, $result->payload);
                $this->recordOutcome($actor, $replay, 'MEDIUM');
                return $replay;
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Verify wrapper for the student preflight endpoint — validates and records a
     * low-severity security event for bad outcomes, without consuming the nonce.
     *
     * @param array<string, mixed> $actor
     */
    public function verify(
        array $actor,
        string $qrString,
        ?string $expectedSessionUuid = null,
        ?\DateTimeImmutable $at = null,
    ): QrValidationResult {
        $result = $this->validate($qrString, $expectedSessionUuid, $at);
        if (!$result->isValid()) {
            $this->recordOutcome($actor, $result, 'LOW');
        }

        return $result;
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    private function sign(string $message, string $secret): string
    {
        return hash_hmac('sha256', $message, $secret);
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE')
            || str_contains($e->getMessage(), 'Duplicate');
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function recordOutcome(array $actor, QrValidationResult $result, string $severity): void
    {
        $eventType = $result->reason->securityEventType();
        if ($eventType === null) {
            return;
        }

        $this->securityLog->recordSecurityEvent(
            $eventType,
            $severity,
            isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null,
            $this->ip($actor),
            $this->ua($actor),
            [
                'reason'       => $result->reason->value,
                'session_uuid' => $result->payload?->sessionUuid,
            ],
        );
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function ip(array $actor): ?string
    {
        return is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function ua(array $actor): ?string
    {
        return is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null;
    }
}
