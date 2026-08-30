<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Security\AuditQueryService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Read-only admin view of the audit trail (SECURITY_RULES.md §11): attendance
 * changes, administrative actions and authentication events.
 *
 *   GET /api/v1/admin/audit-logs
 *     ?event_type= &actor_user_id= &target_entity= &target_id= &from= &to=
 *     &page= &per_page=
 *
 * Requires `audit.log.view` (ADMIN / SUPER_ADMIN). Append-only elsewhere — there
 * is no write endpoint.
 */
final class AuditLogController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::AUDIT_LOG_VIEW, 'view audit logs');

        $result = $this->service()->auditLogs($request->getQuery());

        return JsonResponse::paginated($result['data'], $result['meta']);
    }

    private function service(): AuditQueryService
    {
        return new AuditQueryService(
            $this->logger,
            new SecurityEventRepository($this->db),
            new AuditLogRepository($this->db),
        );
    }
}
