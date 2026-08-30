<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Student;

use QRIVO\Application\Service\Report\StudentReportService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Student attendance report (PROJECT_SPECIFICATION.md §6.16 —
 * "Students: only their own attendance history"). `report.self.view` (STUDENT);
 * the student id is taken from the token, never the request.
 *
 *   GET /api/v1/student/reports/attendance
 *     ?course_id= &class_id= &academic_term_id= &status= &source= &from= &to=
 *     &page= &per_page=
 */
final class ReportController extends BaseController
{
    public function attendance(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::REPORT_SELF_VIEW, 'view your attendance report');

        return $this->success($this->service()->myAttendance($actor, $request->getQuery()));
    }

    private function service(): StudentReportService
    {
        return new StudentReportService(
            $this->logger,
            new AttendanceReportRepository($this->db),
            new RelationshipRepository($this->db),
        );
    }
}
