<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Report;

use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;

/**
 * Administrator attendance reports (PROJECT_SPECIFICATION.md §6.16):
 *   - institution-level report
 *   - department-level report
 *   - course statistics
 *   - attendance statistics
 *
 * "Administrators: only according to their assigned permissions." The permission
 * gate (`report.institution.view`) is enforced by the controller; an admin who
 * holds it sees institution-wide data with no relationship scoping. There is no
 * per-admin institutional partition in the spec.
 */
final class AdminReportService extends AbstractReportService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AttendanceReportRepository $reports,
        private readonly ReferenceRepository $reference,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function institution(array $query): array
    {
        $parsed  = $this->parseQuery($query, [
            'school_id', 'faculty_id', 'department_id', 'program_id',
            'academic_term_id', 'from', 'to',
        ]);
        $filters = $parsed['filters'];

        return [
            'report'         => 'admin.institution',
            'filters'        => $filters,
            'summary'        => $this->reports->summary($filters),
            'by_department'  => $this->reports->groupedSummary($filters, 'department'),
            'by_status'      => $this->reports->groupedSummary($filters, 'status'),
            'by_source'      => $this->reports->groupedSummary($filters, 'source'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function department(int $departmentId, array $query): array
    {
        if (!$this->reference->activeExists('departments', $departmentId)) {
            throw new NotFoundException('Department not found.');
        }

        $parsed  = $this->parseQuery($query, ['program_id', 'course_id', 'academic_term_id', 'from', 'to']);
        $filters = $parsed['filters'] + ['department_id' => $departmentId];

        return [
            'report'        => 'admin.department',
            'department_id' => $departmentId,
            'filters'       => $parsed['filters'],
            'summary'       => $this->reports->summary($filters),
            'by_program'    => $this->reports->groupedSummary($filters, 'program'),
            'by_course'     => $this->reports->groupedSummary($filters, 'course'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function courseStatistics(int $courseId, array $query): array
    {
        if (!$this->reference->activeExists('courses', $courseId)) {
            throw new NotFoundException('Course not found.');
        }

        $parsed  = $this->parseQuery($query, ['class_id', 'academic_term_id', 'from', 'to']);
        $filters = $parsed['filters'] + ['course_id' => $courseId];

        return [
            'report'            => 'admin.course_statistics',
            'course_id'         => $courseId,
            'filters'           => $parsed['filters'],
            'summary'           => $this->reports->summary($filters),
            'by_class'          => $this->reports->groupedSummary($filters, 'class'),
            'by_session_status' => $this->reports->groupedSummary($filters, 'session_status'),
            'by_day'            => $this->reports->groupedSummary($filters, 'day'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function attendanceStatistics(array $query): array
    {
        $parsed  = $this->parseQuery($query, [
            'school_id', 'faculty_id', 'department_id', 'program_id',
            'course_id', 'class_id', 'academic_term_id', 'from', 'to',
        ]);
        $filters = $parsed['filters'];

        return [
            'report'        => 'admin.attendance_statistics',
            'filters'       => $filters,
            'summary'       => $this->reports->summary($filters),
            'by_status'     => $this->reports->groupedSummary($filters, 'status'),
            'by_source'     => $this->reports->groupedSummary($filters, 'source'),
            'by_day'        => $this->reports->groupedSummary($filters, 'day'),
            'by_department' => $this->reports->groupedSummary($filters, 'department'),
        ];
    }
}
