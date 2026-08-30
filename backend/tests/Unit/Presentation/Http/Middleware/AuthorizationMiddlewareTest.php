<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Middleware;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\UserRole;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\PermissionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\Middleware\AuthorizationMiddleware;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Tests\Support\RbacSchemaTrait;

final class AuthorizationMiddlewareTest extends TestCase
{
    use RbacSchemaTrait;

    private AuthorizationService $authz;

    protected function setUp(): void
    {
        $this->pdo = $this->buildRbacDb();
        $db        = $this->buildConnection();
        $logger    = $this->createMock(LoggerInterface::class);

        $this->authz = new AuthorizationService(
            $logger,
            new PermissionRepository($db),
            new RelationshipRepository($db),
            new SecurityLogService($logger, new SecurityEventRepository($db), new AuditLogRepository($db)),
        );
    }

    private function requestWithActor(?array $actor): Request
    {
        $request = new Request('GET', '/api/v1/admin/thing');
        return $actor === null ? $request : $request->withParams(['auth_user' => $actor]);
    }

    private function passthrough(): callable
    {
        return static fn (Request $req): JsonResponse => JsonResponse::success(['ok' => true]);
    }

    public function test_missing_auth_user_returns_401(): void
    {
        $mw       = new AuthorizationMiddleware($this->authz, [UserRole::ADMIN]);
        $response = $mw->process($this->requestWithActor(null), $this->passthrough());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_role_gate_allows_matching_role(): void
    {
        $uid = $this->createUser('a@x.test', ['ADMIN']);
        $mw  = new AuthorizationMiddleware($this->authz, [UserRole::ADMIN]);

        $response = $mw->process(
            $this->requestWithActor($this->actorContext($uid, ['ADMIN'])),
            $this->passthrough(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_role_gate_blocks_other_role_with_403(): void
    {
        $uid = $this->createUser('s@x.test', ['STUDENT']);
        $mw  = new AuthorizationMiddleware($this->authz, [UserRole::ADMIN]);

        $response = $mw->process(
            $this->requestWithActor($this->actorContext($uid, ['STUDENT'])),
            $this->passthrough(),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(1, $this->securityEventCount());
    }

    public function test_permission_gate_blocks_actor_without_permission(): void
    {
        $uid = $this->createUser('t@x.test', ['TEACHER']);
        $mw  = new AuthorizationMiddleware($this->authz, [], [Permission::AUDIT_LOG_VIEW]);

        $response = $mw->process(
            $this->requestWithActor($this->actorContext($uid, ['TEACHER'])),
            $this->passthrough(),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_permission_gate_allows_actor_with_permission(): void
    {
        $uid = $this->createUser('a@x.test', ['ADMIN']);
        $mw  = new AuthorizationMiddleware($this->authz, [], [Permission::AUDIT_LOG_VIEW]);

        $response = $mw->process(
            $this->requestWithActor($this->actorContext($uid, ['ADMIN'])),
            $this->passthrough(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_super_admin_passes_permission_gate(): void
    {
        $uid = $this->createUser('root@x.test', ['SUPER_ADMIN']);
        $mw  = new AuthorizationMiddleware($this->authz, [], [Permission::AUDIT_LOG_VIEW, Permission::ATTENDANCE_SESSION_START]);

        $response = $mw->process(
            $this->requestWithActor($this->actorContext($uid, ['SUPER_ADMIN'])),
            $this->passthrough(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
