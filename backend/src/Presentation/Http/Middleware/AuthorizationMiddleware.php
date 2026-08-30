<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Middleware;

use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\UserRole;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Route-level authorization gate.
 *
 * Runs AFTER {@see AuthMiddleware} (which places the validated actor context on
 * the request as `auth_user`). Enforces a coarse role/permission requirement for
 * a route before the controller runs. Fine-grained ownership and relationship
 * checks still happen inside the controller/service via
 * {@see AuthorizationService}.
 *
 * Security (SECURITY_RULES.md §4):
 * - Fails closed: no `auth_user` -> 401; requirement not met -> 403.
 * - The requirement is defined server-side per route, never by the client.
 * - A 403 here is also recorded as a security event by AuthorizationService.
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    /**
     * @param array<UserRole|string>   $anyRole      actor must hold at least one of these roles (empty = no role gate)
     * @param array<Permission|string> $permissions  actor must hold all of these permissions (empty = no permission gate)
     */
    public function __construct(
        private readonly AuthorizationService $authz,
        private readonly array $anyRole = [],
        private readonly array $permissions = [],
    ) {}

    public function process(Request $request, callable $next): JsonResponse
    {
        $actor = $request->param('auth_user');

        if (!is_array($actor) || !isset($actor['user_id'])) {
            return JsonResponse::error('Authentication required.', 401);
        }

        try {
            if ($this->anyRole !== []) {
                $this->authz->requireAnyRole($actor, $this->anyRole, 'access this endpoint');
            }

            foreach ($this->permissions as $permission) {
                $this->authz->requirePermission($actor, $permission, 'access this endpoint');
            }
        } catch (ForbiddenException $e) {
            return JsonResponse::error($e->getMessage(), 403);
        }

        return $next($request);
    }
}
