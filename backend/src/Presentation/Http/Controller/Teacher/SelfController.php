<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Teacher;

use QRIVO\Application\Service\Teacher\TeacherSelfService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\TeacherSelfRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Teacher self-service reads for the web panel (PROJECT_SPECIFICATION.md §12).
 *
 *   GET /api/v1/teacher/dashboard   — profile + today's lessons + active/recent sessions + totals
 *   GET /api/v1/teacher/schedule    — the teacher's full weekly schedule
 *
 * Mirrors {@see \QRIVO\Presentation\Http\Controller\Student\SelfController}.
 * Both routes authenticate the bearer token and require a self-scoped
 * permission the TEACHER role ALREADY holds (`profile.self.view` /
 * `schedule.self.view`) — no permission or RBAC change was made. The service
 * returns only the caller's own data. See docs/ACCEPTED_DEVIATIONS.md AD-018.
 */
final class SelfController extends BaseController
{
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $this->guard($request, Permission::PROFILE_SELF_VIEW);

        return $this->success($this->service()->dashboard($actor));
    }

    public function schedule(Request $request): JsonResponse
    {
        $actor = $this->guard($request, Permission::SCHEDULE_SELF_VIEW);

        return $this->success($this->service()->schedule($actor));
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

    private function service(): TeacherSelfService
    {
        return new TeacherSelfService(
            $this->logger,
            new TeacherSelfRepository($this->db),
            new RelationshipRepository($this->db),
        );
    }
}
