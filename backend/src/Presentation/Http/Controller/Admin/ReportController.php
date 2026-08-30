<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Report\AdminReportService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Administrator attendance reports (PROJECT_SPECIFICATION.md §6.16).
 * `report.institution.view` (ADMIN / SUPER_ADMIN) — "only according to their
 * assigned permissions".
 *
 *   GET /api/v1/admin/reports/institution
 *   GET /api/v1/admin/reports/department/{id}
 *   GET /api/v1/admin/reports/course/{id}
 *   GET /api/v1/admin/reports/attendance-statistics
 */
final class ReportController extends BaseController
{
    public function institution(Request $request): JsonResponse
    {
        $actor = $this->guard($request);

        return $this->success($this->service()->institution($request->getQuery()));
    }

    public function department(Request $request): JsonResponse
    {
        $this->guard($request);

        return $this->success($this->service()->department($this->id($request), $request->getQuery()));
    }

    public function course(Request $request): JsonResponse
    {
        $this->guard($request);

        return $this->success($this->service()->courseStatistics($this->id($request), $request->getQuery()));
    }

    public function statistics(Request $request): JsonResponse
    {
        $this->guard($request);

        return $this->success($this->service()->attendanceStatistics($request->getQuery()));
    }

    /**
     * @return array<string, mixed>
     */
    private function guard(Request $request): array
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::REPORT_INSTITUTION_VIEW, 'view institution reports');

        return $actor;
    }

    private function id(Request $request): int
    {
        $id = $request->param('id');
        if (!is_numeric($id) || (int) $id < 1) {
            throw new NotFoundException('Report target not found.');
        }

        return (int) $id;
    }

    private function service(): AdminReportService
    {
        return new AdminReportService(
            $this->logger,
            new AttendanceReportRepository($this->db),
            new ReferenceRepository($this->db),
        );
    }
}
