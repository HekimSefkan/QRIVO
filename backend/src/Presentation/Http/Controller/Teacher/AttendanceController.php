<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Teacher;

use QRIVO\Application\Service\Attendance\AttendanceSessionService;
use QRIVO\Application\Service\AttendanceEligibilityService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Teacher attendance-session endpoints.
 *
 *   POST /api/v1/teacher/attendance/start   — create a session (ATTENDANCE_ALGORITHM.md §2)
 *   GET  /api/v1/teacher/attendance/{id}     — view one of the caller's own sessions
 *
 * Close / cancel / manual attendance are later phases and are not implemented here.
 */
final class AttendanceController extends BaseController
{
    /**
     * POST /api/v1/teacher/attendance/start
     * Body: { class_id, course_id, [academic_term_id], [room_id] }
     */
    public function start(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::ATTENDANCE_SESSION_START, 'start an attendance session');

        $result = $this->sessionService()->start($actor, $request->getBody());

        return $this->created($result, 'Attendance session started.');
    }

    /**
     * GET /api/v1/teacher/attendance/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::ATTENDANCE_SESSION_START, 'view an attendance session');

        $id = $request->param('id');
        if (!is_numeric($id) || (int) $id < 1) {
            throw new NotFoundException('Attendance session not found.');
        }

        return $this->success($this->sessionService()->viewOwned($actor, (int) $id));
    }

    private function sessionService(): AttendanceSessionService
    {
        $securityLog = new SecurityLogService(
            $this->logger,
            new SecurityEventRepository($this->db),
            new AuditLogRepository($this->db),
        );

        $eligibility = new AttendanceEligibilityService(
            $this->logger,
            new ScheduleRepository($this->db),
            $securityLog,
        );

        return new AttendanceSessionService(
            $this->logger,
            $this->db,
            $eligibility,
            new AttendanceSessionRepository($this->db),
            new AttendanceRecordRepository($this->db),
            new ScheduleRepository($this->db),
            new RelationshipRepository($this->db),
            $securityLog,
        );
    }
}
