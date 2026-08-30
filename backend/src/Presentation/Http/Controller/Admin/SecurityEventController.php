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
 * Read-only admin view of the security-event trail
 * (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §10).
 *
 *   GET /api/v1/admin/security-events
 *     ?event_type= &severity= &user_id= &attendance_session_id= &from= &to=
 *     &page= &per_page=
 *
 * Requires `security.event.view` (ADMIN / SUPER_ADMIN). Append-only elsewhere —
 * there is no write endpoint.
 */
final class SecurityEventController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::SECURITY_EVENT_VIEW, 'view security events');

        $result = $this->service()->securityEvents($request->getQuery());

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
