<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Report;

use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;

/**
 * Teacher attendance reports (PROJECT_SPECIFICATION.md §6.16):
 *   - course attendance   (per session, for a course the teacher teaches)
 *   - class attendance    (per student, for a class the teacher is assigned to)
 *   - student history     (a student's records, RESTRICTED to the teacher's
 *                          own classes/courses — never other teachers' data)
 *
 * "Teachers: only their authorized courses/classes." Every method verifies the
 * relationship server-side before any row is read; a request outside scope is a
 * 403 + `UNAUTHORIZED_ACCESS` security event.
 */
final class TeacherReportService extends AbstractReportService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AttendanceReportRepository $reports,
        private readonly RelationshipRepository $relationships,
        private readonly SecurityLogService $securityLog,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function courseReport(array $actor, int $courseId, array $query): array
    {
        $termId = $this->term($query);
        if (!$this->relationships->teacherTeachesCourse($this->userId($actor), $courseId, $termId)) {
            $this->denyScope($actor, 'course', $courseId);
        }

        $parsed  = $this->parseQuery($query, ['class_id', 'academic_term_id', 'session_status', 'from', 'to']);
        $filters = $parsed['filters'] + ['course_id' => $courseId];

        $sessions = $this->reports->paginatedSessions($filters, $parsed['page'], $parsed['per_page']);

        return [
            'report'   => 'teacher.course_attendance',
            'course_id' => $courseId,
            'filters'  => $parsed['filters'],
            'summary'  => $this->reports->summary($filters),
            'sessions' => $sessions['data'],
            'meta'     => $this->meta($parsed['page'], $parsed['per_page'], $sessions['total']),
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function classReport(array $actor, int $classId, array $query): array
    {
        $termId = $this->term($query);
        if (!$this->relationships->teacherAssignedToClass($this->userId($actor), $classId, $termId)) {
            $this->denyScope($actor, 'class', $classId);
        }

        $parsed  = $this->parseQuery($query, ['course_id', 'academic_term_id', 'from', 'to']);
        $filters = $parsed['filters'] + ['class_id' => $classId];

        $students = $this->reports->paginatedStudentBreakdown($filters, $parsed['page'], $parsed['per_page']);

        return [
            'report'   => 'teacher.class_attendance',
            'class_id' => $classId,
            'filters'  => $parsed['filters'],
            'summary'  => $this->reports->summary($filters),
            'students' => $students['data'],
            'meta'     => $this->meta($parsed['page'], $parsed['per_page'], $students['total']),
        ];
    }

    /**
     * A single student's attendance history, scoped to the classes/courses this
     * teacher is assigned to. Records from other teachers' courses are never
     * returned.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function studentReport(array $actor, int $studentId, array $query): array
    {
        $userId = $this->userId($actor);
        $termId = $this->term($query);

        if (!$this->relationships->teacherSharesClassWithStudent($userId, $studentId, $termId)) {
            $this->denyScope($actor, 'student', $studentId);
        }

        $classIds  = $this->relationships->teacherClassIds($userId, $termId);
        $courseIds = $this->relationships->teacherCourseIds($userId, $termId);
        if ($classIds === [] || $courseIds === []) {
            $this->denyScope($actor, 'student', $studentId);
        }

        $parsed  = $this->parseQuery($query, ['course_id', 'class_id', 'academic_term_id', 'status', 'source', 'from', 'to']);

        // A client-supplied course/class filter may only NARROW the teacher's scope.
        if (isset($parsed['filters']['class_id']) && !in_array($parsed['filters']['class_id'], $classIds, true)) {
            $this->denyScope($actor, 'class', $parsed['filters']['class_id']);
        }
        if (isset($parsed['filters']['course_id']) && !in_array($parsed['filters']['course_id'], $courseIds, true)) {
            $this->denyScope($actor, 'course', $parsed['filters']['course_id']);
        }

        $filters = $parsed['filters'] + [
            'student_id' => $studentId,
            'class_ids'  => $classIds,
            'course_ids' => $courseIds,
        ];

        $records = $this->reports->paginatedRecords($filters, $parsed['page'], $parsed['per_page']);

        return [
            'report'     => 'teacher.student_history',
            'student_id' => $studentId,
            'filters'    => $parsed['filters'],
            'summary'    => $this->reports->summary($filters),
            'records'    => $records['data'],
            'meta'       => $this->meta($parsed['page'], $parsed['per_page'], $records['total']),
        ];
    }

    // ─── internals ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $actor */
    private function userId(array $actor): int
    {
        $id = $actor['user_id'] ?? null;
        if (!is_numeric($id)) {
            throw new ForbiddenException('You cannot view reports.');
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $query */
    private function term(array $query): ?int
    {
        return isset($query['academic_term_id']) && is_numeric($query['academic_term_id'])
            ? (int) $query['academic_term_id']
            : null;
    }

    /** @param array<string, mixed> $actor */
    private function denyScope(array $actor, string $entity, int $id): never
    {
        $this->securityLog->recordSecurityEvent(
            SecurityEventType::UNAUTHORIZED_ACCESS,
            'MEDIUM',
            is_numeric($actor['user_id'] ?? null) ? (int) $actor['user_id'] : null,
            is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
            is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null,
            ['action' => 'teacher attendance report', 'scope' => $entity, 'requested_id' => $id],
        );

        throw new ForbiddenException('You are not authorized to view this report.');
    }
}
