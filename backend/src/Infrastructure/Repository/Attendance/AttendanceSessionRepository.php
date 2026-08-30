<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Attendance;

use QRIVO\Infrastructure\Repository\BaseRepository;

/**
 * Data access for `attendance_sessions`.
 *
 * All reads/writes here are meant to run inside the session-creation
 * transaction. `lockClassRow()` serialises concurrent creators for the same
 * class (CONSTRAINTS.md §6: "SELECT FOR UPDATE on active session check").
 */
final class AttendanceSessionRepository extends BaseRepository
{
    /**
     * Take a row lock on the class so that two concurrent "start session"
     * requests for the same class cannot both pass the active-session check.
     * No-op on drivers without `FOR UPDATE` (SQLite in tests).
     */
    public function lockClassRow(int $classId): void
    {
        $suffix = $this->db->driverName() === 'mysql' ? ' FOR UPDATE' : '';
        $this->db->fetchOne("SELECT `id` FROM `classes` WHERE `id` = :id{$suffix}", ['id' => $classId]);
    }

    /**
     * The active session for (class, course, term), if any. Locked on MySQL.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForClassCourseTerm(int $classId, int $courseId, int $termId, bool $lock = false): ?array
    {
        $suffix = ($lock && $this->db->driverName() === 'mysql') ? ' FOR UPDATE' : '';

        return $this->db->fetchOne(
            "SELECT * FROM `attendance_sessions`
              WHERE `class_id` = :cid AND `course_id` = :coid AND `academic_term_id` = :tid
                AND `status` = 'ACTIVE'
              LIMIT 1{$suffix}",
            ['cid' => $classId, 'coid' => $courseId, 'tid' => $termId],
        );
    }

    /** Any active session for this teacher (informational — OQ-009). */
    public function activeSessionCountForTeacher(int $teacherId): int
    {
        return (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `attendance_sessions` WHERE `teacher_id` = :tid AND `status` = 'ACTIVE'",
            ['tid' => $teacherId],
        )['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return (int) $this->insert('attendance_sessions', $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRow(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `attendance_sessions` WHERE `id` = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `attendance_sessions` WHERE `uuid` = :uuid LIMIT 1', ['uuid' => $uuid]);
    }
}
