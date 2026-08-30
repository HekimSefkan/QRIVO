<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Teacher;

use QRIVO\Application\Service\Report\TeacherReportService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Teacher attendance reports (PROJECT_SPECIFICATION.md §6.16). `report.course.view`
 * (TEACHER); the service verifies the teacher→course/class/student relationship
 * before any data is read.
 *
 *   GET /api/v1/teacher/reports/course/{courseId}
 *   GET /api/v1/teacher/reports/class/{classId}
 *   GET /api/v1/teacher/reports/student/{studentId}
 */
final class ReportController extends BaseController
{
    public function course(Request $request): JsonResponse
    {
        [$actor, $id] = $this->guard($request);

        return $this->success($this->service()->courseReport($actor, $id, $request->getQuery()));
    }

    public function classReport(Request $request): JsonResponse
    {
        [$actor, $id] = $this->guard($request);

        return $this->success($this->service()->classReport($actor, $id, $request->getQuery()));
    }

    public function student(Request $request): JsonResponse
    {
        [$actor, $id] = $this->guard($request);

        return $this->success($this->service()->studentReport($actor, $id, $request->getQuery()));
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function guard(Request $request): array
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::REPORT_COURSE_VIEW, 'view attendance reports');

        $id = $request->param('id');
        if (!is_numeric($id) || (int) $id < 1) {
            throw new NotFoundException('Report target not found.');
        }

        return [$actor, (int) $id];
    }

    private function service(): TeacherReportService
    {
        return new TeacherReportService(
            $this->logger,
            new AttendanceReportRepository($this->db),
            new RelationshipRepository($this->db),
            new SecurityLogService(
                $this->logger,
                new SecurityEventRepository($this->db),
                new AuditLogRepository($this->db),
            ),
        );
    }
}
