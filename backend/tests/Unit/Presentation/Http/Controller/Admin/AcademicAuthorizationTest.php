<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller\Admin;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * End-to-end authorization for the admin academic endpoints, dispatched through
 * the real Router (route match → controller → exception→HTTP mapping).
 *
 * Proves the gate is enforced SERVER-SIDE: identity comes from the bearer token
 * (validated against device_sessions); the permission check decides access and
 * the client cannot influence it.
 */
final class AcademicAuthorizationTest extends TestCase
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

    private function dispatch(string $method, string $uri, array $body = [], ?string $token = null): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        $request = new Request($method, $uri, [], $body, $headers, ['REMOTE_ADDR' => '203.0.113.1']);

        return $this->router->dispatch($request, $this->db, $this->logger, $this->config);
    }

    public function test_unauthenticated_request_is_rejected_401(): void
    {
        $this->assertSame(401, $this->dispatch('GET', '/api/v1/admin/schools')->getStatusCode());
    }

    public function test_student_is_forbidden_403(): void
    {
        $token = $this->issueToken($this->makeUser('student@x.test', ['STUDENT']));
        $this->assertSame(403, $this->dispatch('GET', '/api/v1/admin/schools', [], $token)->getStatusCode());
    }

    public function test_teacher_is_forbidden_403(): void
    {
        $token = $this->issueToken($this->makeUser('teacher@x.test', ['TEACHER']));
        $this->assertSame(403, $this->dispatch('GET', '/api/v1/admin/courses', [], $token)->getStatusCode());
    }

    public function test_admin_is_allowed_and_can_create(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));

        $list = $this->dispatch('GET', '/api/v1/admin/schools', [], $token);
        $this->assertSame(200, $list->getStatusCode());

        $created = $this->dispatch('POST', '/api/v1/admin/schools', ['name' => 'Main', 'code' => 'main'], $token);
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('MAIN', $created->getData()['data']['code']);

        $id = $created->getData()['data']['id'];
        $this->assertSame(200, $this->dispatch('GET', "/api/v1/admin/schools/{$id}", [], $token)->getStatusCode());
        $this->assertSame(200, $this->dispatch('PATCH', "/api/v1/admin/schools/{$id}", ['name' => 'X'], $token)->getStatusCode());
        $this->assertSame(200, $this->dispatch('DELETE', "/api/v1/admin/schools/{$id}", [], $token)->getStatusCode());
    }

    public function test_super_admin_is_allowed(): void
    {
        $token = $this->issueToken($this->makeUser('root@x.test', ['SUPER_ADMIN']));
        $this->assertSame(200, $this->dispatch('GET', '/api/v1/admin/students', [], $token)->getStatusCode());
    }

    public function test_forbidden_response_does_not_leak_permission_name(): void
    {
        $token    = $this->issueToken($this->makeUser('student@x.test', ['STUDENT']));
        $response = $this->dispatch('GET', '/api/v1/admin/schools', [], $token);

        $this->assertStringNotContainsString('academic.school.manage', (string) json_encode($response->getData()));
    }

    public function test_revoked_token_is_rejected(): void
    {
        $uid   = $this->makeUser('admin@x.test', ['ADMIN']);
        $token = $this->issueToken($uid);
        $this->pdo->exec("UPDATE device_sessions SET revoked_at = '2026-01-01 00:00:00' WHERE user_id = {$uid}");

        $this->assertSame(401, $this->dispatch('GET', '/api/v1/admin/schools', [], $token)->getStatusCode());
    }

    public function test_validation_error_is_422(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));
        $this->assertSame(422, $this->dispatch('POST', '/api/v1/admin/schools', ['name' => 'x'], $token)->getStatusCode());
    }

    public function test_delete_with_children_is_409(): void
    {
        $token    = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN', 'SUPER_ADMIN']));
        $schoolId = $this->dispatch('POST', '/api/v1/admin/schools', ['name' => 'S', 'code' => 'S'], $token)->getData()['data']['id'];
        $this->dispatch('POST', '/api/v1/admin/faculties', ['school_id' => $schoolId, 'name' => 'F', 'code' => 'F'], $token);

        $this->assertSame(409, $this->dispatch('DELETE', "/api/v1/admin/schools/{$schoolId}", [], $token)->getStatusCode());
    }
}
