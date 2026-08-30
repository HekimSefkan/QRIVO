<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Attendance;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Attendance\RiskAssessment;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Contract\RiskEvaluatorInterface;
use QRIVO\Domain\Entity\Attendance\QrChallenge;
use QRIVO\Domain\Enum\ChallengeFailureReason;
use QRIVO\Domain\Enum\QrValidationReason;
use QRIVO\Domain\Enum\RiskLevel;
use QRIVO\Domain\Enum\RiskOutcome;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository;
use QRIVO\Infrastructure\Repository\Attendance\RiskAssessmentRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;

/**
 * Challenge-response attendance — ATTENDANCE_ALGORITHM.md §4, implemented exactly
 * and in order. The server is authoritative; NO validation step is bypassed.
 *
 *   requestChallenge():  scan → QR validation (steps 2-4) → membership (8-9) →
 *                        per-student QR-nonce replay (DD-004) → rate limit (11) →
 *                        issue { challenge_id, nonce, expires_at }
 *
 *   verify():            load challenge → ownership (6) → single-use pre-check (7) →
 *                        expiry (5) → challenge-response nonce match → QR
 *                        re-validation / binding (3-4) → session still ACTIVE (2) →
 *                        membership re-check (8-9) → device/session (12, Phase 18 hook)
 *                        → TRANSACTION { atomic single-use (7) · duplicate check (10)
 *                        · risk evaluation (13) · attendance record }
 *
 * Failure handling (§4): the client gets a GENERIC message + coarse HTTP status;
 * the specific reason goes only to `security_events`.
 */
final class ChallengeService extends BaseService
{
    private readonly int $challengeTtl;
    private readonly int $rateMax;
    private readonly int $rateWindow;

    public function __construct(
        LoggerInterface $logger,
        private readonly Connection $db,
        private readonly QrService $qr,
        private readonly RiskEvaluatorInterface $risk,
        private readonly AttendanceSessionRepository $sessions,
        private readonly QrChallengeRepository $challenges,
        private readonly AttendanceRecordRepository $records,
        private readonly RiskAssessmentRepository $riskAssessments,
        private readonly RelationshipRepository $relationships,
        private readonly SecurityLogService $securityLog,
        Config $config,
    ) {
        parent::__construct($logger);
        $this->challengeTtl = max(15, $config->getInt('attendance.challenge.ttl_seconds', 120));
        $this->rateMax      = max(1, $config->getInt('attendance.challenge.max_per_window', 10));
        $this->rateWindow   = max(30, $config->getInt('attendance.challenge.window_seconds', 300));
    }

    // ─── Step: request a challenge ────────────────────────────────────────────

    /**
     * @param array<string, mixed>   $actor
     * @param array<string, mixed>   $input  { qr }
     * @return array{challenge_id: string, nonce: string, expires_at: string}
     */
    public function requestChallenge(array $actor, array $input, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable('now');

        (new Validator())->validate($input, ['qr' => 'required|string|max_length:512']);
        $qrString = (string) $input['qr'];

        $userId    = (int) $actor['user_id'];
        $studentId = $this->relationships->findStudentIdForUser($userId);
        if ($studentId === null) {
            $this->deny($actor, ChallengeFailureReason::NO_STUDENT_PROFILE, new ForbiddenException('You cannot request an attendance challenge.'));
        }

        // Steps 2-4: QR validity, session ACTIVE, not expired, HMAC-SHA256 signature.
        $qrResult = $this->qr->validate($qrString, null, $at);
        if (!$qrResult->isValid()) {
            $this->deny($actor, $this->mapQr($qrResult->reason), $this->qrException($qrResult->reason), [
                'session_uuid' => $qrResult->payload?->sessionUuid,
            ]);
        }

        $session = $this->sessions->findRow((int) $qrResult->sessionId);
        \assert($session !== null);

        // Steps 8-9: fail fast — never issue a challenge to a non-member.
        $this->assertMembership($actor, $userId, $session);

        // DD-004: this student must not already hold a challenge for this QR nonce.
        if ($this->challenges->studentHasChallengeForQrNonce($studentId, $qrResult->payload->nonce)) {
            $this->deny($actor, ChallengeFailureReason::QR_NONCE_REPLAYED, new ConflictException('This QR has already been used.'));
        }

        // Step 11: rate limiting.
        $since = $at->modify("-{$this->rateWindow} seconds")->format('Y-m-d H:i:s');
        if ($this->challenges->countForStudentSessionSince($studentId, (int) $session['id'], $since) >= $this->rateMax) {
            $this->deny($actor, ChallengeFailureReason::RATE_LIMITED, new TooManyRequestsException('Too many attempts. Please wait and try again.'));
        }

        // Generate: challenge_id, nonce, expires_at (exactly §4).
        $uuid      = $this->uuidV4();
        $nonce     = bin2hex(random_bytes(32));
        $expiresAt = $at->modify("+{$this->challengeTtl} seconds")->format('Y-m-d H:i:s');

        $this->challenges->create([
            'uuid'                  => $uuid,
            'attendance_session_id' => (int) $session['id'],
            'student_id'            => $studentId,
            'nonce'                 => $nonce,
            'qr_nonce'              => $qrResult->payload->nonce,
            'expires_at'            => $expiresAt,
        ]);

        return [
            'challenge_id' => $uuid,
            'nonce'        => $nonce,
            'expires_at'   => $expiresAt,
        ];
    }

    // ─── Step: submit the challenge response + verification ───────────────────

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input  { challenge_id, nonce, qr }
     * @return array<string, mixed>
     */
    public function verify(array $actor, array $input, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable('now');

        (new Validator())->validate($input, [
            'challenge_id' => 'required|uuid',
            'nonce'        => 'required|string|max_length:255',
            'qr'           => 'required|string|max_length:512',
        ]);

        $challengeId    = (string) $input['challenge_id'];
        $submittedNonce = (string) $input['nonce'];
        $qrString       = (string) $input['qr'];

        $userId    = (int) $actor['user_id'];
        $studentId = $this->relationships->findStudentIdForUser($userId);
        if ($studentId === null) {
            $this->deny($actor, ChallengeFailureReason::NO_STUDENT_PROFILE, new ForbiddenException('You cannot submit attendance.'));
        }

        $row = $this->challenges->findByUuid($challengeId);
        if ($row === null) {
            $this->deny($actor, ChallengeFailureReason::CHALLENGE_NOT_FOUND, new NotFoundException('Challenge not found.'));
        }
        $challenge = QrChallenge::fromRow($row);

        // Step 6: challenge ownership.
        if ($challenge->studentId !== $studentId) {
            $this->deny($actor, ChallengeFailureReason::CHALLENGE_NOT_OWNED, new ForbiddenException('This challenge is not yours.'), [
                'challenge_id' => $challengeId,
            ]);
        }

        // Step 7: single-use (pre-check; re-checked atomically in the transaction).
        if ($challenge->isUsed()) {
            $this->deny($actor, ChallengeFailureReason::CHALLENGE_ALREADY_USED, new ConflictException('This challenge has already been used.'));
        }

        // Step 5: challenge expiry.
        if (strtotime($challenge->expiresAt) <= $at->getTimestamp()) {
            $this->deny($actor, ChallengeFailureReason::CHALLENGE_EXPIRED, new ConflictException('This challenge has expired.'));
        }

        // Challenge response — the submitted nonce must match (constant time).
        if (!hash_equals($challenge->nonce, $submittedNonce)) {
            $this->deny($actor, ChallengeFailureReason::CHALLENGE_RESPONSE_MISMATCH, new UnauthorizedException('Challenge verification failed.'));
        }

        $session = $this->sessions->findRow($challenge->attendanceSessionId);
        \assert($session !== null);

        // Steps 3-4: re-validate the QR signature and its binding to this challenge.
        $sig = $this->qr->checkSignature($qrString, (string) $session['uuid']);
        if ($sig['reason'] !== QrValidationReason::VALID) {
            $this->deny($actor, $this->mapQr($sig['reason']), new UnauthorizedException('QR verification failed.'));
        }
        if ($sig['payload']->nonce !== $challenge->qrNonce) {
            $this->deny($actor, ChallengeFailureReason::CHALLENGE_QR_MISMATCH, new UnauthorizedException('QR verification failed.'));
        }

        // Step 2: the session must still be ACTIVE and not past its expiry.
        if (($session['status'] ?? null) !== 'ACTIVE' || strtotime((string) $session['expires_at']) <= $at->getTimestamp()) {
            $this->deny($actor, ChallengeFailureReason::QR_SESSION_NOT_ACTIVE, new ConflictException('The attendance session is no longer open.'));
        }

        // Steps 8-9: membership re-check.
        $this->assertMembership($actor, $userId, $session);

        // Step 12: device / session rules — the caller holds a valid, non-revoked
        // device session (enforced by AuthMiddleware / AuthService::validateToken).
        // The full device-fingerprint rules are Phase 18.

        // ─── Steps 7 (atomic) · 10 · 13 · attendance — one transaction ────────
        /** @var array{type: string, status?: string, risk: RiskAssessment} $result */
        $result = $this->db->transaction(function () use ($challenge, $session, $studentId, $at): array {
            $locked = $this->challenges->findByUuid($challenge->uuid, lock: true);
            if ($locked === null || $locked['used_at'] !== null) {
                return ['type' => 'concurrent_use', 'risk' => RiskAssessment::low()];
            }

            // Step 10: duplicate attendance.
            $record = $this->records->findForSessionStudent((int) $session['id'], $studentId, lock: true);
            if ($record !== null && ($record['status'] ?? 'WAITING') !== 'WAITING') {
                return ['type' => 'duplicate', 'risk' => RiskAssessment::low()];
            }

            // Step 7: atomic single-use.
            if (!$this->challenges->markUsed((int) $locked['id'], $at->format('Y-m-d H:i:s'))) {
                return ['type' => 'concurrent_use', 'risk' => RiskAssessment::low()];
            }

            // Step 13: risk evaluation — always runs, result always persisted.
            $risk = $this->risk->evaluate($studentId, (int) $session['id'], $at);
            $this->riskAssessments->create(array_merge($risk->toRow(), [
                'qr_challenge_id'       => (int) $locked['id'],
                'student_id'            => $studentId,
                'attendance_session_id' => (int) $session['id'],
            ]));

            if ($risk->outcome === RiskOutcome::BLOCKED) {
                return ['type' => 'blocked', 'risk' => $risk];
            }

            $status = $risk->outcome->attendanceStatus()->value; // PRESENT | PENDING_REVIEW
            $marked = $at->format('Y-m-d H:i:s');

            if ($record === null) {
                $this->records->insertViaQr((int) $session['id'], $studentId, $status, $marked);
            } elseif (!$this->records->markFromWaiting((int) $record['id'], $status, $marked)) {
                return ['type' => 'duplicate', 'risk' => $risk];
            }

            return ['type' => 'ok', 'status' => $status, 'risk' => $risk];
        });

        return $this->finish($actor, $challenge, $session, $studentId, $result, $at);
    }

    // ─── Post-transaction handling ───────────────────────────────────────────

    /**
     * @param array<string, mixed>                                            $actor
     * @param array<string, mixed>                                            $session
     * @param array{type:string,status?:string,risk:RiskAssessment} $result
     * @return array<string, mixed>
     */
    private function finish(array $actor, QrChallenge $challenge, array $session, int $studentId, array $result, \DateTimeImmutable $at): array
    {
        match ($result['type']) {
            'concurrent_use' => $this->deny($actor, ChallengeFailureReason::CHALLENGE_ALREADY_USED, new ConflictException('This challenge has already been used.')),
            'duplicate'      => $this->deny($actor, ChallengeFailureReason::DUPLICATE_ATTENDANCE, new ConflictException('Attendance has already been recorded for this session.'), ['attendance_session_id' => (int) $session['id']]),
            'blocked'        => $this->deny($actor, ChallengeFailureReason::RISK_BLOCKED, new ForbiddenException('Attendance could not be recorded.'), ['risk' => $result['risk']->toArray()]),
            default          => null,
        };

        $risk = $result['risk'];

        // MEDIUM → "PRESENT + SECURITY_EVENT" (ATTENDANCE_ALGORITHM.md §9).
        if ($risk->level === RiskLevel::MEDIUM) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::RISK_ESCALATION,
                'MEDIUM',
                (int) $actor['user_id'],
                $this->ip($actor),
                $this->ua($actor),
                ['attendance_session_id' => (int) $session['id'], 'signals' => $risk->signals],
            );
        }

        $this->securityLog->recordAuditLog(
            'ATTENDANCE_RECORDED',
            (int) $actor['user_id'],
            'attendance_record',
            (int) $session['id'],
            null,
            ['status' => $result['status'], 'source' => 'QR', 'student_id' => $studentId, 'challenge_id' => $challenge->uuid],
            null,
            $this->ip($actor),
        );

        return [
            'status'      => $result['status'],
            'source'      => 'QR',
            'session_uuid' => (string) $session['uuid'],
            'marked_at'   => $at->format('c'),
            'risk'        => ['level' => $risk->level->value, 'outcome' => $risk->outcome->value],
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $session
     */
    private function assertMembership(array $actor, int $userId, array $session): void
    {
        $termId = (int) $session['academic_term_id'];

        if (!$this->relationships->studentEnrolledInCourse($userId, (int) $session['course_id'], $termId)) {
            $this->deny($actor, ChallengeFailureReason::NOT_ENROLLED_IN_COURSE, new ForbiddenException('You are not enrolled in this course.'), [
                'attendance_session_id' => (int) $session['id'],
            ]);
        }
        if (!$this->relationships->studentEnrolledInClass($userId, (int) $session['class_id'], $termId)) {
            $this->deny($actor, ChallengeFailureReason::NOT_ENROLLED_IN_CLASS, new ForbiddenException('You are not enrolled in this class.'), [
                'attendance_session_id' => (int) $session['id'],
            ]);
        }
    }

    private function mapQr(QrValidationReason $r): ChallengeFailureReason
    {
        return match ($r) {
            QrValidationReason::MALFORMED          => ChallengeFailureReason::QR_MALFORMED,
            QrValidationReason::SESSION_NOT_FOUND  => ChallengeFailureReason::QR_SESSION_NOT_FOUND,
            QrValidationReason::SESSION_NOT_ACTIVE => ChallengeFailureReason::QR_SESSION_NOT_ACTIVE,
            QrValidationReason::WRONG_SESSION      => ChallengeFailureReason::CHALLENGE_QR_MISMATCH,
            QrValidationReason::EXPIRED            => ChallengeFailureReason::QR_EXPIRED,
            QrValidationReason::BAD_SIGNATURE      => ChallengeFailureReason::QR_BAD_SIGNATURE,
            QrValidationReason::REPLAYED           => ChallengeFailureReason::QR_NONCE_REPLAYED,
            QrValidationReason::VALID              => ChallengeFailureReason::QR_MALFORMED, // unreachable
        };
    }

    private function qrException(QrValidationReason $r): \Throwable
    {
        return match ($r) {
            QrValidationReason::EXPIRED            => new ConflictException('This QR code has expired.'),
            QrValidationReason::SESSION_NOT_ACTIVE => new ConflictException('The attendance session is not open.'),
            QrValidationReason::SESSION_NOT_FOUND  => new NotFoundException('Attendance session not found.'),
            default                               => new UnauthorizedException('QR verification failed.'),
        };
    }

    /**
     * Record the specific failure reason as a security event, then throw a
     * generic exception. No technical detail reaches the client (§4).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $context
     */
    private function deny(
        array $actor,
        ChallengeFailureReason $reason,
        \Throwable $exception,
        array $context = [],
    ): never {
        $severity = match ($reason) {
            ChallengeFailureReason::RISK_BLOCKED,
            ChallengeFailureReason::CHALLENGE_NOT_OWNED,
            ChallengeFailureReason::QR_NONCE_REPLAYED,
            ChallengeFailureReason::CHALLENGE_ALREADY_USED => 'HIGH',
            default                                        => 'MEDIUM',
        };

        $this->securityLog->recordSecurityEvent(
            $reason->securityEventType(),
            $severity,
            isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null,
            $this->ip($actor),
            $this->ua($actor),
            array_merge(['stage' => 'challenge_response', 'reason' => $reason->value], $context),
        );

        throw $exception;
    }

    /** @param array<string, mixed> $actor */
    private function ip(array $actor): ?string
    {
        return is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null;
    }

    /** @param array<string, mixed> $actor */
    private function ua(array $actor): ?string
    {
        return is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null;
    }

    private function uuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
