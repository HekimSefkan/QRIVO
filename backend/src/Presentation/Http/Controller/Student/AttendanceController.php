<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Student;

use QRIVO\Application\Service\Attendance\ChallengeService;
use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Application\Service\Security\DeviceSessionService;
use QRIVO\Application\Service\Security\RiskScoringService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\Attendance\RiskAssessmentRepository;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\SystemSettingRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Student-facing attendance endpoints.
 *
 *   POST /api/v1/student/attendance/qr/verify  — preflight QR check (non-consuming)
 *   POST /api/v1/student/attendance/challenge  — request a challenge for a scanned QR (§4)
 *   POST /api/v1/student/attendance/verify     — submit the challenge response → record attendance (§4)
 *
 * All three require the STUDENT `attendance.qr.submit` permission. The server is
 * authoritative; failure responses are generic (technical detail goes only to
 * `security_events`).
 */
final class AttendanceController extends BaseController
{
    /**
     * POST /api/v1/student/attendance/qr/verify
     * Body: { "qr": "<scanned QR string>", "session_id"?: "<expected session uuid>" }
     */
    public function verifyQr(Request $request): JsonResponse
    {
        $actor = $this->guard($request);

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

    /**
     * POST /api/v1/student/attendance/challenge
     * Body: { "qr": "<scanned QR string>" }
     * Response: { challenge_id, nonce, expires_at }
     */
    public function challenge(Request $request): JsonResponse
    {
        $actor  = $this->guard($request);
        $result = $this->challengeService()->requestChallenge($actor, $request->getBody());

        return $this->created($result, 'Challenge issued.');
    }

    /**
     * POST /api/v1/student/attendance/verify
     * Body: { "challenge_id": "<uuid>", "nonce": "<challenge nonce>", "qr": "<scanned QR string>" }
     */
    public function verify(Request $request): JsonResponse
    {
        $actor  = $this->guard($request);
        $result = $this->challengeService()->verify($actor, $request->getBody());

        return $this->success($result, 'Attendance recorded.');
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function guard(Request $request): array
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, Permission::ATTENDANCE_QR_SUBMIT, 'submit attendance');

        return $actor;
    }

    private function securityLog(): SecurityLogService
    {
        return new SecurityLogService(
            $this->logger,
            new SecurityEventRepository($this->db),
            new AuditLogRepository($this->db),
        );
    }

    private function qrService(): QrService
    {
        return new QrService(
            $this->logger,
            new AttendanceSessionRepository($this->db),
            new QrNonceRepository($this->db),
            new RelationshipRepository($this->db),
            $this->securityLog(),
            $this->config,
        );
    }

    private function challengeService(): ChallengeService
    {
        $challengeRepo = new QrChallengeRepository($this->db);

        return new ChallengeService(
            $this->logger,
            $this->db,
            $this->qrService(),
            new RiskScoringService(
                $this->logger,
                $challengeRepo,
                new SecurityEventRepository($this->db),
                new SystemSettingRepository($this->db),
                $this->securityLog(),
                $this->config,
            ),
            new AttendanceSessionRepository($this->db),
            $challengeRepo,
            new AttendanceRecordRepository($this->db),
            new RiskAssessmentRepository($this->db),
            new RelationshipRepository($this->db),
            $this->securityLog(),
            new DeviceSessionService(
                $this->logger,
                new DeviceSessionRepository($this->db),
                $this->securityLog(),
                $this->config,
            ),
            $this->config,
        );
    }
}
