<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Attendance;

use QRIVO\Infrastructure\Repository\BaseRepository;

/**
 * Data access for `attendance_records`.
 *
 * `initialiseForClassEnrollment()` performs the "all students in the class start
 * as WAITING" step of session creation (ATTENDANCE_ALGORITHM.md §2) as a single
 * INSERT … SELECT inside the creation transaction.
 */
final class AttendanceRecordRepository extends BaseRepository
{
    /**
     * Insert one WAITING/SYSTEM record per student enrolled in ($classId, $termId).
     * The UNIQUE(attendance_session_id, student_id) key (C-001) makes this safe to
     * re-run. Returns the number of rows created.
     */
    public function initialiseForClassEnrollment(int $sessionId, int $classId, int $termId): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->execute(
            "INSERT INTO `attendance_records`
                 (`attendance_session_id`, `student_id`, `status`, `source`, `created_at`, `updated_at`)
             SELECT :sid, sca.`student_id`, 'WAITING', 'SYSTEM', :c1, :c2
               FROM `student_class_assignments` sca
               JOIN `students` s ON s.`id` = sca.`student_id` AND s.`deleted_at` IS NULL
              WHERE sca.`class_id` = :cid AND sca.`academic_term_id` = :tid
                AND NOT EXISTS (
                    SELECT 1 FROM `attendance_records` ar
                     WHERE ar.`attendance_session_id` = :sid2 AND ar.`student_id` = sca.`student_id`
                )",
            ['sid' => $sessionId, 'sid2' => $sessionId, 'cid' => $classId, 'tid' => $termId, 'c1' => $now, 'c2' => $now],
        );
    }

    /**
     * Status → count for a session (drives the live counters later).
     *
     * @return array<string, int>
     */
    public function countByStatus(int $sessionId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `status`, COUNT(*) AS c FROM `attendance_records`
              WHERE `attendance_session_id` = :sid GROUP BY `status`',
            ['sid' => $sessionId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    public function countForSession(int $sessionId): int
    {
        return (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM `attendance_records` WHERE `attendance_session_id` = :sid',
            ['sid' => $sessionId],
        )['c'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forSession(int $sessionId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `attendance_records` WHERE `attendance_session_id` = :sid ORDER BY `id` ASC',
            ['sid' => $sessionId],
        );
    }
}
