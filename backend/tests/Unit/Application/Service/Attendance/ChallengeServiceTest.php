<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\ChallengeService;
use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\Attendance\RiskAssessmentRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * ATTENDANCE_ALGORITHM.md §4 — challenge-response attendance. Every failure path
 * is exercised; no validation step may be skipped.
 */
final class ChallengeServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    private Connection $db;
    /** @var array<string, int> */
    private array $ids;
    /** @var array{id:int, uuid:string, secret:string} */
    private array $session;

    protected function setUp(): void
    {
        $this->pdo     = $this->buildAcademicDb();
        $this->db      = $this->buildConnection();
        $this->ids     = $this->seedSchedulingFixtures();
        $this->session = $this->insertSession('ACTIVE');
        $this->enrolFixtureStudent();
        $this->waitingRecord($this->session['id']);
    }

    private function service(?\QRIVO\Domain\Contract\RiskEvaluatorInterface $risk = null): ChallengeService
    {
        $log = $this->securityLogService($this->db);
        $qr  = new QrService(
            $this->createMock(LoggerInterface::class),
            new AttendanceSessionRepository($this->db),
            new QrNonceRepository($this->db),
            new RelationshipRepository($this->db),
            $log,
            new Config(QRIVO_ROOT),
        );
        $challengeRepo = new QrChallengeRepository($this->db);

        return new ChallengeService(
            $this->createMock(LoggerInterface::class),
            $this->db,
            $qr,
            $risk ?? $this->riskScoringService($this->db),
            new AttendanceSessionRepository($this->db),
            $challengeRepo,
            new AttendanceRecordRepository($this->db),
            new RiskAssessmentRepository($this->db),
            new RelationshipRepository($this->db),
            $log,
            new \QRIVO\Application\Service\Security\DeviceSessionService(
                $this->createMock(LoggerInterface::class),
                new \QRIVO\Infrastructure\Repository\DeviceSessionRepository($this->db),
                $log,
                new Config(QRIVO_ROOT),
            ),
            new Config(QRIVO_ROOT),
        );
    }

    private function student(?int $userId = null): array
    {
        return $this->actor($userId ?? $this->ids['studentUserId'], ['STUDENT']);
    }

    private function qr(?\DateTimeImmutable $at = null): string
    {
        return $this->qrStringFor($this->session, $at);
    }

    /** Full happy request → returns [challenge_id, nonce, qr string used]. */
    private function issued(?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable('now');
        $qr  = $this->qr($at);
        $c   = $this->service()->requestChallenge($this->student(), ['qr' => $qr], $at);

        return [$c['challenge_id'], $c['nonce'], $qr];
    }

    private function events(string $type): int
    {
        return $this->securityEventCount($type);
    }

    // ══════════════════════ requestChallenge ══════════════════════

    public function test_request_challenge_happy_path(): void
    {
        $at = new \DateTimeImmutable('now');
        $c  = $this->service()->requestChallenge($this->student(), ['qr' => $this->qr($at)], $at);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $c['challenge_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $c['nonce']);
        $this->assertArrayHasKey('expires_at', $c);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) c FROM qr_challenges')->fetch()['c']);
    }

    public function test_request_rejects_non_student(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->service()->requestChallenge($this->actor(1, ['ADMIN']), ['qr' => $this->qr()]);
    }

    public function test_request_rejects_missing_qr(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->requestChallenge($this->student(), []);
    }

    public function test_request_rejects_malformed_qr(): void
    {
        try {
            $this->service()->requestChallenge($this->student(), ['qr' => 'not-a-qr']);
            $this->fail('expected');
        } catch (UnauthorizedException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::QR_INVALID->value));
    }

    public function test_request_rejects_expired_qr(): void
    {
        $old = (new \DateTimeImmutable('now'))->modify('-5 minutes');
        try {
            $this->service()->requestChallenge($this->student(), ['qr' => $this->qr($old)], new \DateTimeImmutable('now'));
            $this->fail('expected');
        } catch (ConflictException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::QR_EXPIRED->value));
    }

    public function test_request_rejects_tampered_qr_signature(): void
    {
        $qr = $this->qr();
        $parts = explode('.', $qr);
        $parts[3] = (string) ((int) $parts[3] + 1); // bump timestamp, keep old signature
        $this->expectException(UnauthorizedException::class);
        $this->service()->requestChallenge($this->student(), ['qr' => implode('.', $parts)]);
    }

    public function test_request_rejects_closed_session(): void
    {
        $closed = $this->insertSession('CLOSED');
        $this->expectException(ConflictException::class);
        $this->service()->requestChallenge($this->student(), ['qr' => $this->qrStringFor($closed)]);
    }

    public function test_request_rejects_student_not_enrolled_in_course(): void
    {
        $this->pdo->exec('DELETE FROM student_courses');
        try {
            $this->service()->requestChallenge($this->student(), ['qr' => $this->qr()]);
            $this->fail('expected');
        } catch (ForbiddenException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::UNAUTHORIZED_ATTENDANCE->value));
    }

    public function test_request_rejects_student_not_enrolled_in_class(): void
    {
        $this->pdo->exec('DELETE FROM student_class_assignments');
        $this->expectException(ForbiddenException::class);
        $this->service()->requestChallenge($this->student(), ['qr' => $this->qr()]);
    }

    public function test_request_rejects_qr_nonce_replay_for_same_student(): void
    {
        $at = new \DateTimeImmutable('now');
        $qr = $this->qr($at);
        $this->service()->requestChallenge($this->student(), ['qr' => $qr], $at);

        try {
            $this->service()->requestChallenge($this->student(), ['qr' => $qr], $at->modify('+1 second'));
            $this->fail('expected');
        } catch (ConflictException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::QR_REPLAY->value));
    }

    public function test_request_is_rate_limited(): void
    {
        $at = new \DateTimeImmutable('now');
        // config default max_per_window = 10
        for ($i = 0; $i < 10; $i++) {
            $this->pdo->prepare('INSERT INTO qr_challenges (uuid, attendance_session_id, student_id, nonce, qr_nonce, expires_at, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute(["u{$i}-x-x-x-x", $this->session['id'], $this->ids['studentId'], "n{$i}", "q{$i}", '2030-01-01 00:00:00', $at->format('Y-m-d H:i:s')]);
        }

        try {
            $this->service()->requestChallenge($this->student(), ['qr' => $this->qr($at)], $at);
            $this->fail('expected');
        } catch (TooManyRequestsException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::SUSPICIOUS_AUTH->value));
    }

    // ══════════════════════ verify ══════════════════════

    public function test_verify_happy_path_marks_present(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);

        $out = $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+10 seconds'));

        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('QR', $out['source']);

        $rec = $this->pdo->query("SELECT * FROM attendance_records WHERE student_id={$this->ids['studentId']}")->fetch();
        $this->assertSame('PRESENT', $rec['status']);
        $this->assertSame('QR', $rec['source']);
        $this->assertNotNull($rec['marked_at']);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) c FROM risk_assessments')->fetch()['c']);
        $this->assertNotNull($this->pdo->query("SELECT used_at FROM qr_challenges WHERE uuid='{$cid}'")->fetch()['used_at']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM audit_logs WHERE event_type='ATTENDANCE_RECORDED'")->fetch()['c']);
    }

    public function test_verify_rejects_missing_fields(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->verify($this->student(), ['challenge_id' => 'x']);
    }

    public function test_verify_rejects_unknown_challenge(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->verify($this->student(), [
            'challenge_id' => '00000000-0000-4000-8000-000000000000', 'nonce' => 'x', 'qr' => $this->qr(),
        ]);
    }

    public function test_verify_rejects_challenge_owned_by_another_student(): void
    {
        [$cid, $nonce, $qr] = $this->issued();

        $otherUser = $this->makeUser('student2@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$otherUser, $this->ids['programId'], 'S-2', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        try {
            $this->service()->verify($this->student($otherUser), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr]);
            $this->fail('expected');
        } catch (ForbiddenException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::UNAUTHORIZED_ATTENDANCE->value));
    }

    public function test_verify_rejects_wrong_challenge_response_nonce(): void
    {
        [$cid, , $qr] = $this->issued();
        try {
            $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => str_repeat('0', 64), 'qr' => $qr]);
            $this->fail('expected');
        } catch (UnauthorizedException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::CHALLENGE_INVALID->value));
    }

    public function test_verify_rejects_expired_challenge(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);

        // config challenge ttl = 120s
        $this->expectException(ConflictException::class);
        $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+200 seconds'));
    }

    public function test_verify_rejects_mismatched_qr(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce] = $this->issued($at);
        $differentQr = $this->qr($at->modify('+1 second')); // different nonce

        try {
            $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $differentQr], $at->modify('+5 seconds'));
            $this->fail('expected');
        } catch (UnauthorizedException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::CHALLENGE_INVALID->value));
    }

    public function test_verify_rejects_forged_qr_signature(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);
        $parts = explode('.', $qr);
        $parts[5] = str_repeat('a', 64);

        $this->expectException(UnauthorizedException::class);
        $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => implode('.', $parts)], $at->modify('+5 seconds'));
    }

    public function test_verify_rejects_when_session_closed_after_challenge(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);
        $this->pdo->exec("UPDATE attendance_sessions SET status='CLOSED' WHERE id={$this->session['id']}");

        $this->expectException(ConflictException::class);
        $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));
    }

    public function test_verify_rejects_when_student_unenrolled_after_challenge(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);
        $this->pdo->exec('DELETE FROM student_courses');

        $this->expectException(ForbiddenException::class);
        $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));
    }

    // ─── single-use / replay / duplicate ─────────────────────────────────────

    public function test_challenge_is_single_use_replay_rejected(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);

        $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        try {
            $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+10 seconds'));
            $this->fail('expected');
        } catch (ConflictException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::QR_REPLAY->value));
        // still exactly one PRESENT
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM attendance_records WHERE status='PRESENT'")->fetch()['c']);
    }

    public function test_duplicate_attendance_rejected_when_already_present(): void
    {
        $at = new \DateTimeImmutable('now');
        // Student already marked PRESENT by a teacher/earlier flow.
        $this->pdo->exec("UPDATE attendance_records SET status='PRESENT' WHERE student_id={$this->ids['studentId']}");
        [$cid, $nonce, $qr] = $this->issued($at);

        try {
            $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));
            $this->fail('expected');
        } catch (ConflictException) {
        }
        $this->assertSame(1, $this->events(SecurityEventType::DUPLICATE_ATTENDANCE->value));
        // the challenge was NOT consumed (validation failed at step 10)
        $this->assertNull($this->pdo->query("SELECT used_at FROM qr_challenges WHERE uuid='{$cid}'")->fetch()['used_at']);
    }

    // ─── risk outcomes ──────────────────────────────────────────────────────

    public function test_medium_risk_records_present_plus_security_event(): void
    {
        $at = new \DateTimeImmutable('now');
        // Seed 6 prior challenge rows in the window (soft threshold = 6).
        for ($i = 0; $i < 6; $i++) {
            $this->pdo->prepare('INSERT INTO qr_challenges (uuid, attendance_session_id, student_id, nonce, qr_nonce, expires_at, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute(["m{$i}-x-x-x-x", $this->session['id'], $this->ids['studentId'], "mn{$i}", "mq{$i}", '2030-01-01 00:00:00', $at->format('Y-m-d H:i:s')]);
        }
        [$cid, $nonce, $qr] = $this->issued($at);

        $out = $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('MEDIUM', $out['risk']['level']);
        $this->assertSame(1, $this->events(SecurityEventType::RISK_ESCALATION->value));
        $this->assertSame('MEDIUM', $this->pdo->query('SELECT risk_level FROM risk_assessments')->fetch()['risk_level']);
    }

    /** Seed a prior security event attributed to a user (for the history look-back). */
    private function seedSecurityEvent(string $type, int $userId, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable('now');
        $this->pdo->prepare(
            'INSERT INTO security_events (event_type, severity, user_id, created_at) VALUES (?,?,?,?)'
        )->execute([$type, 'HIGH', $userId, $at->format('Y-m-d H:i:s')]);
    }

    public function test_high_risk_records_pending_review(): void
    {
        $at = new \DateTimeImmutable('now');
        // A recent REPLAY_ATTEMPT (QR_REPLAY event, weight 60) is on its own a
        // HIGH signal → PENDING_REVIEW (§9).
        $this->seedSecurityEvent('QR_REPLAY', $this->ids['studentUserId'], $at);
        [$cid, $nonce, $qr] = $this->issued($at);

        $out = $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        $this->assertSame('PENDING_REVIEW', $out['status']);
        $this->assertSame('HIGH', $out['risk']['level']);
        $this->assertSame(1, $this->events(SecurityEventType::RISK_ESCALATION->value));
        $this->assertSame('PENDING_REVIEW', $this->pdo->query("SELECT status FROM attendance_records WHERE student_id={$this->ids['studentId']}")->fetch()['status']);
        $this->assertStringContainsString('REPLAY_ATTEMPT', (string) $this->pdo->query('SELECT signals FROM risk_assessments')->fetch()['signals']);
    }

    public function test_recent_expired_qr_alone_stays_low(): void
    {
        $at = new \DateTimeImmutable('now');
        // One honest fumble (expired QR, weight 15) must NOT elevate the outcome.
        $this->seedSecurityEvent('QR_EXPIRED', $this->ids['studentUserId'], $at);
        [$cid, $nonce, $qr] = $this->issued($at);

        $out = $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('LOW', $out['risk']['level']);
        $this->assertSame(0, $this->events(SecurityEventType::RISK_ESCALATION->value));
    }

    public function test_unauthorized_relationship_history_blocks(): void
    {
        $at = new \DateTimeImmutable('now');
        // A recent UNAUTHORIZED_ATTENDANCE event (weight 100) → BLOCKED.
        $this->seedSecurityEvent('UNAUTHORIZED_ATTENDANCE', $this->ids['studentUserId'], $at);
        [$cid, $nonce, $qr] = $this->issued($at);

        try {
            $this->service()->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }

        $this->assertSame('BLOCKED', $this->pdo->query('SELECT risk_level FROM risk_assessments')->fetch()['risk_level']);
        $this->assertSame('WAITING', $this->pdo->query("SELECT status FROM attendance_records WHERE student_id={$this->ids['studentId']}")->fetch()['status']);
        $this->assertSame(1, $this->events(SecurityEventType::BLOCKED_ATTENDANCE->value));
    }

    public function test_risk_blocked_consumes_challenge_but_records_no_attendance(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);

        $blockingEvaluator = new class implements \QRIVO\Domain\Contract\RiskEvaluatorInterface {
            /** @param array<string, mixed> $context */
            public function evaluate(int $studentId, int $sessionId, \DateTimeImmutable $at, array $context = []): \QRIVO\Domain\Attendance\RiskAssessment
            {
                return new \QRIVO\Domain\Attendance\RiskAssessment(
                    \QRIVO\Domain\Enum\RiskLevel::BLOCKED,
                    100,
                    \QRIVO\Domain\Enum\RiskOutcome::BLOCKED,
                    ['TEST_BLOCK'],
                );
            }
        };

        try {
            $this->service($blockingEvaluator)->verify($this->student(), ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }

        $this->assertSame(1, $this->events(SecurityEventType::BLOCKED_ATTENDANCE->value));
        // challenge consumed (prevents grinding); risk row written; no PRESENT record
        $this->assertNotNull($this->pdo->query("SELECT used_at FROM qr_challenges WHERE uuid='{$cid}'")->fetch()['used_at']);
        $this->assertSame('BLOCKED', $this->pdo->query('SELECT risk_level FROM risk_assessments')->fetch()['risk_level']);
        $this->assertSame('WAITING', $this->pdo->query("SELECT status FROM attendance_records WHERE student_id={$this->ids['studentId']}")->fetch()['status']);
    }

    // ─── device / session risk signals (Phase 18) ──────────────────────────

    /** Attach a device session row and return an actor bound to it. */
    private function actorOnDevice(string $sessionFingerprint, ?string $requestFingerprint): array
    {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare(
            'INSERT INTO device_sessions (uuid, user_id, device_fingerprint, expires_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?)'
        )->execute([bin2hex(random_bytes(6)), $this->ids['studentUserId'], $sessionFingerprint, '2099-01-01 00:00:00', $now, $now]);
        $dsId = (int) $this->pdo->lastInsertId();

        return ['session_id' => $dsId, 'device_fingerprint' => $requestFingerprint] + $this->student();
    }

    public function test_device_fingerprint_mismatch_elevates_risk(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);
        $actor = $this->actorOnDevice('bound-device-fp', 'different-device-fp');

        $out = $this->service()->verify($actor, ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        // DEVICE_MISMATCH maps to the canonical MULTIPLE_DEVICE_ACTIVITY signal
        // (weight 40) → MEDIUM → PRESENT + SECURITY_EVENT (§9).
        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('MEDIUM', $out['risk']['level']);
        $this->assertSame(1, $this->events(SecurityEventType::RISK_ESCALATION->value));
        $signals = (string) $this->pdo->query('SELECT signals FROM risk_assessments')->fetch()['signals'];
        $this->assertStringContainsString('MULTIPLE_DEVICE_ACTIVITY', $signals);
    }

    public function test_device_mismatch_plus_retry_pressure_reaches_high(): void
    {
        $at = new \DateTimeImmutable('now');
        // 6 challenge rows → EXCESSIVE_RETRY (30) + MULTIPLE_DEVICE_ACTIVITY (40) = 70 → HIGH.
        for ($i = 0; $i < 6; $i++) {
            $this->pdo->prepare('INSERT INTO qr_challenges (uuid, attendance_session_id, student_id, nonce, qr_nonce, expires_at, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute(["r{$i}-x-x-x-x", $this->session['id'], $this->ids['studentId'], "rn{$i}", "rq{$i}", '2030-01-01 00:00:00', $at->format('Y-m-d H:i:s')]);
        }
        [$cid, $nonce, $qr] = $this->issued($at);
        $actor = $this->actorOnDevice('bound-device-fp', 'different-device-fp');

        $out = $this->service()->verify($actor, ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        $this->assertSame('PENDING_REVIEW', $out['status']);
        $this->assertSame('HIGH', $out['risk']['level']);
        $signals = (string) $this->pdo->query('SELECT signals FROM risk_assessments')->fetch()['signals'];
        $this->assertStringContainsString('EXCESSIVE_RETRY', $signals);
        $this->assertStringContainsString('MULTIPLE_DEVICE_ACTIVITY', $signals);
    }

    public function test_multiple_active_devices_elevates_risk(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);
        $actor = $this->actorOnDevice('bound-fp', 'bound-fp');

        // Push the student well over the default ceiling (5) of active sessions.
        $now = '2026-01-01 00:00:00';
        for ($i = 0; $i < 6; $i++) {
            $this->pdo->prepare(
                'INSERT INTO device_sessions (uuid, user_id, device_fingerprint, expires_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?)'
            )->execute([bin2hex(random_bytes(6)), $this->ids['studentUserId'], "extra-{$i}", '2099-01-01 00:00:00', $now, $now]);
        }

        $out = $this->service()->verify($actor, ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('MEDIUM', $out['risk']['level']);
        $this->assertSame(1, $this->events(SecurityEventType::RISK_ESCALATION->value));
        $this->assertStringContainsString('MULTIPLE_DEVICE_ACTIVITY', (string) $this->pdo->query('SELECT signals FROM risk_assessments')->fetch()['signals']);
    }

    public function test_matching_device_stays_low_risk(): void
    {
        $at = new \DateTimeImmutable('now');
        [$cid, $nonce, $qr] = $this->issued($at);
        $actor = $this->actorOnDevice('same-fp', 'same-fp');

        $out = $this->service()->verify($actor, ['challenge_id' => $cid, 'nonce' => $nonce, 'qr' => $qr], $at->modify('+5 seconds'));

        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('LOW', $out['risk']['level']);
    }

    public function test_no_technical_detail_leaks_in_failure_message(): void
    {
        try {
            $this->service()->requestChallenge($this->student(), ['qr' => 'not-a-qr']);
            $this->fail('expected');
        } catch (UnauthorizedException $e) {
            foreach (['nonce', 'signature', 'secret', 'HMAC', 'MALFORMED'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $e->getMessage());
            }
        }
    }
}
