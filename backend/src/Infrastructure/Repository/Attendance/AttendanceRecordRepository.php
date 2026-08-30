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

    /**
     * The record for (session, student). Row-locked on MySQL for the
     * challenge-response transaction (step 10 — duplicate check).
     *
     * @return array<string, mixed>|null
     */
    public function findForSessionStudent(int $sessionId, int $studentId, bool $lock = false): ?array
    {
        $suffix = ($lock && $this->db->driverName() === 'mysql') ? ' FOR UPDATE' : '';

        return $this->db->fetchOne(
            "SELECT * FROM `attendance_records`
              WHERE `attendance_session_id` = :sid AND `student_id` = :stu LIMIT 1{$suffix}",
            ['sid' => $sessionId, 'stu' => $studentId],
        );
    }

    /**
     * Transition a WAITING record to $status via QR. Returns true only if the row
     * was WAITING (guards against a concurrent double-mark).
     */
    public function markFromWaiting(int $id, string $status, string $markedAt): bool
    {
        return $this->db->execute(
            "UPDATE `attendance_records`
                SET `status` = :status, `source` = 'QR', `marked_at` = :marked, `updated_at` = :upd
              WHERE `id` = :id AND `status` = 'WAITING'",
            ['status' => $status, 'marked' => $markedAt, 'upd' => $markedAt, 'id' => $id],
        ) > 0;
    }

    /**
     * Live roster for a session — one row per student, with name/number/status/
     * source/time. Optional server-side search + status + updated-since filters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liveRoster(
        int $sessionId,
        ?string $search = null,
        ?string $status = null,
        ?string $updatedSince = null,
    ): array {
        $sql =
            'SELECT ar.`student_id`, ar.`status`, ar.`source`, ar.`marked_at`, ar.`updated_at`,
                    s.`student_number`, u.`first_name`, u.`last_name`
               FROM `attendance_records` ar
               JOIN `students` s ON s.`id` = ar.`student_id`
               JOIN `users`    u ON u.`id` = s.`user_id`
              WHERE ar.`attendance_session_id` = :sid';
        $bindings = ['sid' => $sessionId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND ar.`status` = :status';
            $bindings['status'] = $status;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (s.`student_number` LIKE :q OR u.`first_name` LIKE :q OR u.`last_name` LIKE :q)';
            $bindings['q'] = '%' . $search . '%';
        }
        if ($updatedSince !== null && $updatedSince !== '') {
            $sql .= ' AND ar.`updated_at` >= :since';
            $bindings['since'] = $updatedSince;
        }

        $sql .= ' ORDER BY u.`last_name` ASC, u.`first_name` ASC, ar.`student_id` ASC';

        return $this->db->fetchAll($sql, $bindings);
    }

    /**
     * A cheap change signal for delta polling: "{total}:{marked}:{maxUpdatedAt}".
     * Changes whenever any record's status/time changes or a record is added.
     */
    public function rosterVersion(int $sessionId): string
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(MAX(`updated_at`), '') AS max_updated,
                    COALESCE(SUM(CASE WHEN `status` <> 'WAITING' THEN 1 ELSE 0 END), 0) AS marked
               FROM `attendance_records` WHERE `attendance_session_id` = :sid",
            ['sid' => $sessionId],
        );

        return sprintf('%d:%d:%s', (int) ($row['total'] ?? 0), (int) ($row['marked'] ?? 0), (string) ($row['max_updated'] ?? ''));
    }

    /**
     * Insert a QR record for a student who has no row yet (enrolled after session
     * start). UNIQUE(session, student) (C-001) is the backstop.
     */
    public function insertViaQr(int $sessionId, int $studentId, string $status, string $markedAt): int
    {
        return (int) $this->insert('attendance_records', [
            'attendance_session_id' => $sessionId,
            'student_id'            => $studentId,
            'status'                => $status,
            'source'                => 'QR',
            'marked_at'             => $markedAt,
            'created_at'            => $markedAt,
            'updated_at'            => $markedAt,
        ]);
    }

    /**
     * Teacher manual override — set the status, mark the source MANUAL, stamp the time
     * (ATTENDANCE_ALGORITHM.md §6 step 7). Runs inside the manual-attendance transaction.
     */
    public function setStatusManual(int $id, string $status, string $markedAt): int
    {
        return $this->db->execute(
            "UPDATE `attendance_records`
                SET `status` = :status, `source` = 'MANUAL', `marked_at` = :marked, `updated_at` = :upd
              WHERE `id` = :id",
            ['status' => $status, 'marked' => $markedAt, 'upd' => $markedAt, 'id' => $id],
        );
    }

    /**
     * Insert a MANUAL record for a student enrolled after session start.
     */
    public function insertManual(int $sessionId, int $studentId, string $status, string $markedAt): int
    {
        return (int) $this->insert('attendance_records', [
            'attendance_session_id' => $sessionId,
            'student_id'            => $studentId,
            'status'                => $status,
            'source'                => 'MANUAL',
            'marked_at'             => $markedAt,
            'created_at'            => $markedAt,
            'updated_at'            => $markedAt,
        ]);
    }
}
