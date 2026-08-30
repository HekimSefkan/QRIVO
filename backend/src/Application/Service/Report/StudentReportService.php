<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Report;

use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;

/**
 * Student attendance report (PROJECT_SPECIFICATION.md §6.16 —
 * "Students: only their own attendance history").
 *
 * The `students.id` is resolved server-side from the authenticated token; a
 * `student_id` can never be supplied by the client. Every row returned belongs
 * to the caller.
 */
final class StudentReportService extends AbstractReportService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AttendanceReportRepository $reports,
        private readonly RelationshipRepository $relationships,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function myAttendance(array $actor, array $query): array
    {
        $userId = $actor['user_id'] ?? null;
        if (!is_numeric($userId)) {
            throw new ForbiddenException('You cannot view this report.');
        }

        $studentId = $this->relationships->findStudentIdForUser((int) $userId);
        if ($studentId === null) {
            throw new ForbiddenException('You cannot view this report.');
        }

        $parsed  = $this->parseQuery($query, [
            'course_id', 'class_id', 'academic_term_id', 'status', 'source', 'from', 'to',
        ]);
        $filters = $parsed['filters'] + ['student_id' => $studentId];

        $records = $this->reports->paginatedRecords($filters, $parsed['page'], $parsed['per_page']);

        return [
            'report'  => 'student.attendance_history',
            'filters' => $parsed['filters'],
            'summary' => $this->reports->summary($filters),
            'records' => $records['data'],
            'meta'    => $this->meta($parsed['page'], $parsed['per_page'], $records['total']),
        ];
    }
}
