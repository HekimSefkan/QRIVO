<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Read access for a student's own data (mobile self-service — PROJECT_SPECIFICATION.md §6.11).
 *
 * Every method is keyed by `students.id` — the caller resolves that from the
 * authenticated token; a student can only ever read their own rows.
 */
final class StudentSelfRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function profileForUser(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT s.`id` AS student_id, s.`student_number`, s.`program_id`, s.`enrollment_year`,
                    u.`uuid`, u.`email`, u.`first_name`, u.`last_name`
               FROM `students` s
               JOIN `users` u ON u.`id` = s.`user_id`
              WHERE s.`user_id` = :uid AND s.`deleted_at` IS NULL
              LIMIT 1',
            ['uid' => $userId],
        );
    }

    /**
     * The student's weekly schedule — every course_schedules slot for the classes
     * they are enrolled in (RELATIONSHIPS.md §8).
     *
     * @return array<int, array<string, mixed>>
     */
    public function scheduleForStudent(int $studentId): array
    {
        return $this->db->fetchAll(
            'SELECT DISTINCT cs.`id` AS course_schedule_id, cs.`day_of_week`, cs.`start_time`, cs.`end_time`,
                    cs.`room_id`, tca.`course_id`, tca.`class_id`, tca.`academic_term_id`
               FROM `student_class_assignments` sca
               JOIN `teacher_class_assignments` tca
                 ON tca.`class_id` = sca.`class_id` AND tca.`academic_term_id` = sca.`academic_term_id`
               JOIN `course_schedules` cs ON cs.`teacher_class_assignment_id` = tca.`id`
              WHERE sca.`student_id` = :sid
              ORDER BY cs.`day_of_week` ASC, cs.`start_time` ASC',
            ['sid' => $studentId],
        );
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function attendanceHistoryForStudent(int $studentId, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $total = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM `attendance_records` WHERE `student_id` = :sid',
            ['sid' => $studentId],
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT ar.`attendance_session_id`, ar.`status`, ar.`source`, ar.`marked_at`,
                    ses.`course_id`, ses.`class_id`, ses.`academic_term_id`,
                    ses.`start_time` AS session_start_time, ses.`status` AS session_status
               FROM `attendance_records` ar
               JOIN `attendance_sessions` ses ON ses.`id` = ar.`attendance_session_id`
              WHERE ar.`student_id` = :sid
              ORDER BY ses.`start_time` DESC, ar.`id` DESC
              LIMIT {$perPage} OFFSET {$offset}",
            ['sid' => $studentId],
        );

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * status => count across all of the student's attendance records.
     *
     * @return array<string, int>
     */
    public function attendanceSummaryForStudent(int $studentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `status`, COUNT(*) AS c FROM `attendance_records` WHERE `student_id` = :sid GROUP BY `status`',
            ['sid' => $studentId],
        );

        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row['status']] = (int) $row['c'];
        }

        return $summary;
    }
}
