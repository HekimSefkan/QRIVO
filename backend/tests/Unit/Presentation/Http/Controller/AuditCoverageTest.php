<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * End-to-end spec-compliance check for the audit / security-event trails
 * (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §10 / §11), dispatched
 * through the real Router.
 *
 * Categories:
 *   - administrative actions  → audit_logs
 *   - authentication events    → audit_logs
 *   - security events          → security_events
 *   - (attendance changes are covered by ManualAttendanceRoutesTest)
 *
 * Plus: no password / raw secret ever lands in either table.
 */
final class AuditCoverageTest extends TestCase
{
    use AcademicSchemaTrait;

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private Config $config;

    protected function setUp(): void
    {
        $this->pdo    = $this->buildAcademicDb();
        $this->db     = $this->buildConnection();
        $this->config = new Config(QRIVO_ROOT);
        $this->logger = new Logger($this->config);
        $this->router = new Router(QRIVO_ROOT);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, string $uri, array $body = [], ?string $token = null): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        return $this->router->dispatch(
            new Request($method, $uri, [], $body, $headers, ['REMOTE_ADDR' => '203.0.113.20']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    private function insertPasswordUser(string $email, string $role, string $password): int
    {
        $uid = $this->makeUser($email, [$role]);
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), $uid]);

        return $uid;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        return $this->pdo->query("SELECT * FROM {$table}")->fetchAll();
    }

    // ─── administrative actions ─────────────────────────────────────────────

    public function test_administrative_action_is_audited(): void
    {
        $adminId = $this->makeUser('admin@x.test', ['ADMIN']);
        $token   = $this->issueToken($adminId);

        $res = $this->dispatch('POST', '/api/v1/admin/schools', ['name' => 'Engineering', 'code' => 'ENG'], $token);
        $this->assertSame(201, $res->getStatusCode());

        $row = $this->pdo->query("SELECT * FROM audit_logs WHERE event_type = 'SCHOOL_CREATED' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame((string) $adminId, (string) $row['actor_user_id']);   // actor
        $this->assertSame('school', $row['target_entity']);                      // target entity
        $this->assertSame('203.0.113.20', $row['ip_address']);                   // ip
        $this->assertNotNull($row['created_at']);                                // timestamp
        $this->assertStringContainsString('Engineering', $row['new_value']);     // new state
    }

    // ─── security events ────────────────────────────────────────────────────

    public function test_unauthorized_access_is_recorded_as_a_security_event(): void
    {
        $studentId = $this->makeUser('student@x.test', ['STUDENT']);
        $token     = $this->issueToken($studentId);

        $res = $this->dispatch('GET', '/api/v1/admin/schools', [], $token);
        $this->assertSame(403, $res->getStatusCode());

        $row = $this->pdo->query("SELECT * FROM security_events WHERE event_type = 'UNAUTHORIZED_ACCESS' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame((string) $studentId, (string) $row['user_id']);        // actor
        $this->assertSame('203.0.113.20', $row['ip_address']);                   // ip
        $this->assertNotNull($row['created_at']);                                // timestamp
        $this->assertNotNull($row['details']);                                   // context
    }

    // ─── authentication events ──────────────────────────────────────────────

    public function test_login_failure_is_a_security_event_without_the_password(): void
    {
        $this->insertPasswordUser('u@x.test', 'STUDENT', 'CorrectHorse1!');

        $res = $this->dispatch('POST', '/api/v1/auth/login', ['email' => 'u@x.test', 'password' => 'WRONG-Secret-99']);
        $this->assertSame(401, $res->getStatusCode());

        $row = $this->pdo->query("SELECT * FROM security_events WHERE event_type = 'LOGIN_FAILURE' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertStringContainsString('u@x.test', (string) $row['details']);       // targeted account kept
        $this->assertStringNotContainsString('WRONG-Secret-99', (string) $row['details']); // password never
    }

    public function test_successful_login_is_audited(): void
    {
        $uid = $this->insertPasswordUser('ok@x.test', 'STUDENT', 'CorrectHorse1!');

        $res = $this->dispatch('POST', '/api/v1/auth/login', ['email' => 'ok@x.test', 'password' => 'CorrectHorse1!']);
        $this->assertSame(200, $res->getStatusCode());

        $row = $this->pdo->query("SELECT * FROM audit_logs WHERE event_type = 'LOGIN_SUCCESS' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame((string) $uid, (string) $row['actor_user_id']);
        $this->assertSame('user', $row['target_entity']);
    }

    // ─── global no-secrets sweep ────────────────────────────────────────────

    public function test_no_password_or_raw_token_lands_in_either_table(): void
    {
        $admin = $this->insertPasswordUser('admin2@x.test', 'ADMIN', 'AdminPass1!');
        $token = $this->issueToken($admin);

        $this->dispatch('POST', '/api/v1/auth/login', ['email' => 'admin2@x.test', 'password' => 'AdminPass1!']);
        $this->dispatch('POST', '/api/v1/auth/login', ['email' => 'admin2@x.test', 'password' => 'bad-pass-123']);
        $this->dispatch('POST', '/api/v1/admin/schools', ['name' => 'S', 'code' => 'S1'], $token);
        $this->dispatch('GET', '/api/v1/admin/schools', [], $this->issueToken($this->makeUser('s2@x.test', ['STUDENT'])));

        $haystack = '';
        foreach (['security_events', 'audit_logs'] as $table) {
            foreach ($this->rows($table) as $row) {
                $haystack .= json_encode($row);
            }
        }

        foreach (['AdminPass1!', 'bad-pass-123', 'password_hash', '$argon2id'] as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, "leaked: {$needle}");
        }
    }
}
