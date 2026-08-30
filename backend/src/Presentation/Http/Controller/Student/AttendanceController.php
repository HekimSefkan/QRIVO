<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Student;

use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Student-facing attendance endpoints.
 *
 *   POST /api/v1/student/attendance/qr/verify — preflight: is this scanned QR
 *        currently valid? (non-consuming). The challenge-response flow that
 *        actually records attendance is Phase 12.
 */
final class AttendanceController extends BaseController
{
    /**
     * POST /api/v1/student/attendance/qr/verify
     * Body: { "qr": "<scanned QR string>", "session_id"?: "<expected session uuid>" }
     */
    public function verifyQr(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::ATTENDANCE_QR_SUBMIT, 'verify an attendance QR');

        (new Validator())->validate($request->getBody(), [
            'qr'         => 'required|string|max_length:512',
            'session_id' => 'uuid',
        ]);

        $expected = $request->input('session_id');
        $result   = $this->qrService()->verify(
            $actor,
            (string) $request->input('qr'),
            is_string($expected) && $expected !== '' ? $expected : null,
        );

        return $this->success($result->toArray(), $result->isValid() ? 'QR is valid.' : 'QR is not valid.');
    }

    private function qrService(): QrService
    {
        return new QrService(
            $this->logger,
            new AttendanceSessionRepository($this->db),
            new QrNonceRepository($this->db),
            new RelationshipRepository($this->db),
            new SecurityLogService($this->logger, new SecurityEventRepository($this->db), new AuditLogRepository($this->db)),
            $this->config,
        );
    }
}
