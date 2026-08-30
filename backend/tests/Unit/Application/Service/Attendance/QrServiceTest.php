<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Domain\Attendance\QrPayload;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\QrValidationReason;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Dynamic QR — ATTENDANCE_ALGORITHM.md §3, SECURITY_RULES.md §5.
 * Security cases: valid, expired, modified, invalid signature, replay, wrong session.
 */
final class QrServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    /** @var array<string, int> */
    private array $ids;
    /** @var array{id:int, uuid:string, secret:string} */
    private array $session;

    protected function setUp(): void
    {
        $this->pdo     = $this->buildAcademicDb();
        $this->ids     = $this->seedSchedulingFixtures();
        $this->session = $this->insertSession('ACTIVE');
    }

    private function service(): QrService
    {
        $db = $this->buildConnection();

        return new QrService(
            $this->createMock(LoggerInterface::class),
            new AttendanceSessionRepository($db),
            new QrNonceRepository($db),
            new RelationshipRepository($db),
            $this->securityLogService($db),
            new Config(QRIVO_ROOT),
        );
    }

    private function actorStudent(): array
    {
        return $this->actor($this->ids['studentUserId'], ['STUDENT']);
    }

    private function generatedAt(\DateTimeImmutable $at): string
    {
        $row = (new AttendanceSessionRepository($this->buildConnection()))->findRow($this->session['id']);
        return $this->service()->generate($row, $at)['qr_string'];
    }

    // ─── Generation ──────────────────────────────────────────────────────────

    public function test_generated_qr_has_exactly_the_spec_fields_and_no_secret(): void
    {
        $row = (new AttendanceSessionRepository($this->buildConnection()))->findRow($this->session['id']);
        $out = $this->service()->generate($row, new \DateTimeImmutable('2026-03-02 10:00:00'));

        $this->assertSame(['version', 'session_id', 'timestamp', 'nonce', 'signature'], array_keys($out['payload']));
        $this->assertSame($this->session['uuid'], $out['payload']['session_id']); // uuid, not internal id
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $out['payload']['nonce']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $out['payload']['signature']);
        $this->assertStringNotContainsString($this->session['secret'], (string) json_encode($out));
        $this->assertSame(30, $out['ttl_seconds']);

        // signature really is HMAC-SHA256 over the canonical message with session_secret
        $expected = hash_hmac(
            'sha256',
            "qrivo.v1.{$this->session['uuid']}.{$out['payload']['timestamp']}.{$out['payload']['nonce']}",
            $this->session['secret'],
        );
        $this->assertSame($expected, $out['payload']['signature']);
    }

    public function test_each_generation_produces_a_new_nonce(): void
    {
        $row = (new AttendanceSessionRepository($this->buildConnection()))->findRow($this->session['id']);
        $a = $this->service()->generate($row, new \DateTimeImmutable('2026-03-02 10:00:00'));
        $b = $this->service()->generate($row, new \DateTimeImmutable('2026-03-02 10:00:00'));
        $this->assertNotSame($a['payload']['nonce'], $b['payload']['nonce']);
        $this->assertNotSame($a['qr_string'], $b['qr_string']);
    }

    // ─── Validation: valid ───────────────────────────────────────────────────

    public function test_valid_qr_passes(): void
    {
        $at  = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr  = $this->generatedAt($at);
        $res = $this->service()->validate($qr, null, $at->modify('+10 seconds'));

        $this->assertTrue($res->isValid());
        $this->assertSame(QrValidationReason::VALID, $res->reason);
        $this->assertSame($this->session['id'], $res->sessionId);
    }

    // ─── expired ─────────────────────────────────────────────────────────────

    public function test_expired_qr_is_rejected(): void
    {
        $at  = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr  = $this->generatedAt($at);
        $res = $this->service()->validate($qr, null, $at->modify('+31 seconds')); // TTL is 30

        $this->assertFalse($res->isValid());
        $this->assertSame(QrValidationReason::EXPIRED, $res->reason);
    }

    public function test_future_dated_qr_beyond_skew_is_rejected(): void
    {
        $at  = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr  = $this->generatedAt($at);
        $res = $this->service()->validate($qr, null, $at->modify('-30 seconds'));
        $this->assertSame(QrValidationReason::EXPIRED, $res->reason);
    }

    // ─── modified / invalid signature ────────────────────────────────────────

    public function test_modified_payload_fails_signature(): void
    {
        $at   = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr   = $this->generatedAt($at);
        $p    = QrPayload::decode($qr);

        // tamper with the timestamp but keep the (now stale) signature
        $tampered = "qrivo.v1.{$p->sessionUuid}." . ($p->timestamp + 5) . ".{$p->nonce}.{$p->signature}";
        $res = $this->service()->validate($tampered, null, $at->modify('+5 seconds'));

        $this->assertFalse($res->isValid());
        $this->assertSame(QrValidationReason::BAD_SIGNATURE, $res->reason);
    }

    public function test_forged_signature_is_rejected(): void
    {
        $at = new \DateTimeImmutable('2026-03-02 10:00:00');
        $p  = QrPayload::decode($this->generatedAt($at));
        $forged = "qrivo.v1.{$p->sessionUuid}.{$p->timestamp}.{$p->nonce}." . str_repeat('a', 64);

        $res = $this->service()->validate($forged, null, $at->modify('+5 seconds'));
        $this->assertSame(QrValidationReason::BAD_SIGNATURE, $res->reason);
    }

    public function test_signature_from_a_different_session_secret_is_rejected(): void
    {
        // Build a QR signed with the WRONG secret but pointing at our session.
        $at    = new \DateTimeImmutable('2026-03-02 10:00:00');
        $nonce = bin2hex(random_bytes(16));
        $msg   = "qrivo.v1.{$this->session['uuid']}.{$at->getTimestamp()}.{$nonce}";
        $sig   = hash_hmac('sha256', $msg, 'not-the-real-secret');

        $res = $this->service()->validate("{$msg}.{$sig}", null, $at->modify('+5 seconds'));
        $this->assertSame(QrValidationReason::BAD_SIGNATURE, $res->reason);
    }

    public function test_malformed_qr_is_rejected(): void
    {
        foreach (['', 'garbage', 'qrivo.v1.only.three', 'not-even-close://x'] as $bad) {
            $this->assertSame(QrValidationReason::MALFORMED, $this->service()->validate($bad)->reason);
        }
    }

    // ─── wrong session ───────────────────────────────────────────────────────

    public function test_wrong_session_expectation_is_rejected(): void
    {
        $at    = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr    = $this->generatedAt($at);
        $other = '11111111-1111-4111-8111-111111111111';

        $res = $this->service()->validate($qr, $other, $at->modify('+5 seconds'));
        $this->assertSame(QrValidationReason::WRONG_SESSION, $res->reason);
    }

    public function test_qr_for_unknown_session_is_rejected(): void
    {
        $at    = new \DateTimeImmutable('2026-03-02 10:00:00');
        $nonce = bin2hex(random_bytes(16));
        $uuid  = '22222222-2222-4222-8222-222222222222';
        $msg   = "qrivo.v1.{$uuid}.{$at->getTimestamp()}.{$nonce}";
        $sig   = hash_hmac('sha256', $msg, 'x');

        $this->assertSame(QrValidationReason::SESSION_NOT_FOUND, $this->service()->validate("{$msg}.{$sig}", null, $at)->reason);
    }

    public function test_qr_for_closed_session_is_rejected(): void
    {
        $closed = $this->insertSession('CLOSED');
        $at     = new \DateTimeImmutable('2026-03-02 10:00:00');
        $row    = (new AttendanceSessionRepository($this->buildConnection()))->findRow($closed['id']);
        $qr     = $this->service()->generate($row, $at)['qr_string'];

        $this->assertSame(QrValidationReason::SESSION_NOT_ACTIVE, $this->service()->validate($qr, null, $at->modify('+5 seconds'))->reason);
    }

    // ─── replay ──────────────────────────────────────────────────────────────

    public function test_replay_rejected_after_consumption(): void
    {
        $at = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr = $this->generatedAt($at);

        $first = $this->service()->validateAndConsume($this->actorStudent(), $qr, null, $at->modify('+5 seconds'));
        $this->assertTrue($first->isValid());

        $second = $this->service()->validateAndConsume($this->actorStudent(), $qr, null, $at->modify('+6 seconds'));
        $this->assertFalse($second->isValid());
        $this->assertSame(QrValidationReason::REPLAYED, $second->reason);

        // and a plain (non-consuming) validate also now reports REPLAYED
        $this->assertSame(QrValidationReason::REPLAYED, $this->service()->validate($qr, null, $at->modify('+7 seconds'))->reason);

        $this->assertSame(1, $this->securityEventCount(SecurityEventType::QR_REPLAY->value));
    }

    public function test_consume_records_the_nonce(): void
    {
        $at = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr = $this->generatedAt($at);
        $this->service()->validateAndConsume($this->actorStudent(), $qr, null, $at->modify('+5 seconds'));

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) c FROM qr_used_nonces')->fetch()['c']);
    }

    public function test_verify_logs_low_severity_event_for_bad_qr_but_does_not_consume(): void
    {
        $at  = new \DateTimeImmutable('2026-03-02 10:00:00');
        $qr  = $this->generatedAt($at);
        $res = $this->service()->verify($this->actorStudent(), $qr, null, $at->modify('+31 seconds')); // expired

        $this->assertSame(QrValidationReason::EXPIRED, $res->reason);
        $this->assertSame(1, $this->securityEventCount(SecurityEventType::QR_EXPIRED->value));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM qr_used_nonces')->fetch()['c']);
    }
}
