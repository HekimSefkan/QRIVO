<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Student;

use QRIVO\Application\Service\Student\StudentSelfService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\StudentSelfRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Student self-service reads for the mobile app (PROJECT_SPECIFICATION.md §6.11).
 *
 *   GET /api/v1/student/dashboard
 *   GET /api/v1/student/profile
 *   GET /api/v1/student/schedule
 *   GET /api/v1/student/attendance/history
 *
 * Each authenticates the bearer token and requires the matching self-scoped
 * STUDENT permission. The service returns only the caller's own data.
 */
final class SelfController extends BaseController
{
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $this->guard($request, Permission::PROFILE_SELF_VIEW);

        return $this->success($this->service()->dashboard($actor));
    }

    public function profile(Request $request): JsonResponse
    {
        $actor = $this->guard($request, Permission::PROFILE_SELF_VIEW);

        return $this->success($this->service()->profile($actor));
    }

    public function schedule(Request $request): JsonResponse
    {
        $actor = $this->guard($request, Permission::SCHEDULE_SELF_VIEW);

        return $this->success($this->service()->schedule($actor));
    }

    public function attendanceHistory(Request $request): JsonResponse
    {
        $actor  = $this->guard($request, Permission::ATTENDANCE_HISTORY_SELF_VIEW);
        $result = $this->service()->attendanceHistory($actor, $request->getQuery());

        return JsonResponse::paginated($result['history'], $result['meta']);
    }

    /**
     * @return array<string, mixed>
     */
    private function guard(Request $request, Permission $permission): array
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, $permission, 'view your own data');

        return $actor;
    }

    private function service(): StudentSelfService
    {
        return new StudentSelfService(
            $this->logger,
            new StudentSelfRepository($this->db),
            new RelationshipRepository($this->db),
        );
    }
}
