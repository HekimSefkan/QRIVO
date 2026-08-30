<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Teacher;

use QRIVO\Application\Service\AttendanceEligibilityService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Application\Service\SecurityLogService;

/**
 * GET /api/v1/teacher/attendance/eligibility
 *
 * Query: class_id, course_id, [academic_term_id], [at=ISO-8601]
 *
 * Tells the authenticated teacher whether they may open an attendance session
 * for that course/class right now (or at `at`), and in which room. This is the
 * server-side authorization determination required before Phase 10 —
 * it does NOT create anything and issues no QR.
 */
final class AttendanceEligibilityController extends BaseController
{
    public function check(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission(
            $actor,
            Permission::ATTENDANCE_SESSION_START,
            'check attendance eligibility',
        );

        (new Validator())->validate($request->getQuery(), [
            'class_id'         => 'required|integer',
            'course_id'        => 'required|integer',
            'academic_term_id' => 'integer',
            'at'               => 'string',
        ]);

        $termId = $request->query('academic_term_id');
        $atRaw  = $request->query('at');

        $at = null;
        if (is_string($atRaw) && $atRaw !== '') {
            try {
                $at = new \DateTimeImmutable($atRaw);
            } catch (\Exception) {
                return JsonResponse::validationError('Validation failed.', ['at' => ['The at field must be a valid date-time.']]);
            }
        } else {
            $at = new \DateTimeImmutable('now');
        }

        $service = new AttendanceEligibilityService(
            $this->logger,
            new ScheduleRepository($this->db),
            new SecurityLogService($this->logger, new SecurityEventRepository($this->db), new AuditLogRepository($this->db)),
        );

        $result = $service->forTeacher(
            $actor,
            (int) $request->query('class_id'),
            (int) $request->query('course_id'),
            is_numeric($termId) ? (int) $termId : null,
            $at,
        );

        return $this->success($result->toArray(), $result->isAuthorized() ? 'Authorized.' : 'Not authorized.');
    }
}
