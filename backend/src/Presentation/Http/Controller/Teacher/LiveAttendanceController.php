<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Teacher;

use QRIVO\Application\Service\Attendance\LiveAttendanceService;
use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Teacher live-attendance dashboard (PROJECT_SPECIFICATION.md §6.8).
 *
 *   GET /api/v1/teacher/attendance/{id}/live           — full snapshot (session, QR, counters, students)
 *   GET /api/v1/teacher/attendance/{id}/live/counters  — lightweight poll (counters + change signal)
 *   GET /api/v1/teacher/attendance/{id}/live/students   — student list only (search / status / updated_since)
 *
 * `attendance.live.view` (TEACHER) is required, and session ownership is
 * re-verified on EVERY request (ARCHITECTURE_FREEZE.md §2.12).
 */
final class LiveAttendanceController extends BaseController
{
    public function snapshot(Request $request): JsonResponse
    {
        [$actor, $id] = $this->guard($request);

        return $this->success($this->service()->snapshot($actor, $id, $request->getQuery()));
    }

    public function counters(Request $request): JsonResponse
    {
        [$actor, $id] = $this->guard($request);

        return $this->success($this->service()->counters($actor, $id));
    }

    public function students(Request $request): JsonResponse
    {
        [$actor, $id] = $this->guard($request);

        return $this->success($this->service()->students($actor, $id, $request->getQuery()));
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function guard(Request $request): array
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::ATTENDANCE_LIVE_VIEW, 'view live attendance');

        $id = $request->param('id');
        if (!is_numeric($id) || (int) $id < 1) {
            throw new NotFoundException('Attendance session not found.');
        }

        return [$actor, (int) $id];
    }

    private function service(): LiveAttendanceService
    {
        $securityLog = new SecurityLogService(
            $this->logger,
            new SecurityEventRepository($this->db),
            new AuditLogRepository($this->db),
        );

        return new LiveAttendanceService(
            $this->logger,
            new AttendanceSessionRepository($this->db),
            new AttendanceRecordRepository($this->db),
            new RelationshipRepository($this->db),
            new QrService(
                $this->logger,
                new AttendanceSessionRepository($this->db),
                new QrNonceRepository($this->db),
                new RelationshipRepository($this->db),
                $securityLog,
                $this->config,
            ),
            $securityLog,
        );
    }
}
