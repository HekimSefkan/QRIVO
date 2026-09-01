<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Read access for a teacher's own data (web panel self-service —
 * PROJECT_SPECIFICATION.md §12 teacher dashboard).
 *
 * Mirrors {@see StudentSelfRepository}. Every method is keyed by `teachers.id`,
 * which the caller resolves from the authenticated token — a teacher can only
 * ever read their own assignments and their own sessions. This repository makes
 * no authorization decision; it is handed an already-resolved teacher id.
 *
 * Read-only: no INSERT/UPDATE/DELETE, no schema change (AD-018).
 */
final class TeacherSelfRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function profileForUser(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT t.`id` AS teacher_id, t.`employee_number`, t.`department_id`,
                    u.`uuid`, u.`email`, u.`first_name`, u.`last_name`
               FROM `teachers` t
               JOIN `users` u ON u.`id` = t.`user_id`
              WHERE t.`user_id` = :uid AND t.`deleted_at` IS NULL
              LIMIT 1',
            ['uid' => $userId],
        );
    }

    /**
     * The teacher's weekly schedule — every `course_schedules` slot belonging to
     * one of their `teacher_class_assignments` (RELATIONSHIPS.md §4), enriched
     * with the names the dashboard displays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scheduleForTeacher(int $teacherId): array
    {
        return $this->db->fetchAll(
            'SELECT cs.`id` AS course_schedule_id, cs.`day_of_week`, cs.`start_time`, cs.`end_time`,
                    cs.`room_id`, r.`name` AS room_name, r.`code` AS room_code,
                    tca.`id` AS teacher_class_assignment_id,
                    tca.`course_id`, c.`name` AS course_name, c.`code` AS course_code,
                    tca.`class_id`, cl.`name` AS class_name,
                    tca.`academic_term_id`, at.`name` AS academic_term_name, at.`is_active` AS term_is_active
               FROM `teacher_class_assignments` tca
               JOIN `course_schedules` cs ON cs.`teacher_class_assignment_id` = tca.`id`
               JOIN `courses` c           ON c.`id`  = tca.`course_id`
               JOIN `classes` cl          ON cl.`id` = tca.`class_id`
               JOIN `rooms` r             ON r.`id`  = cs.`room_id`
               JOIN `academic_terms` at   ON at.`id` = tca.`academic_term_id`
              WHERE tca.`teacher_id` = :tid
              ORDER BY cs.`day_of_week` ASC, cs.`start_time` ASC',
            ['tid' => $teacherId],
        );
    }

    /**
     * The teacher's attendance sessions, newest first, with live counts.
     *
     * @param list<string> $statuses  e.g. ['ACTIVE'] or ['CLOSED','CANCELLED']
     * @return array<int, array<string, mixed>>
     */
    public function sessionsForTeacher(int $teacherId, array $statuses, int $limit): array
    {
        $limit = max(1, min(50, $limit));

        $placeholders = [];
        $bindings     = ['tid' => $teacherId];
        foreach (array_values($statuses) as $i => $status) {
            $placeholders[]      = ":st{$i}";
            $bindings["st{$i}"]  = $status;
        }
        $in = implode(', ', $placeholders);

        return $this->db->fetchAll(
            "SELECT ses.`id`, ses.`uuid`, ses.`status`, ses.`start_time`, ses.`end_time`, ses.`expires_at`,
                    ses.`course_id`, c.`name` AS course_name, c.`code` AS course_code,
                    ses.`class_id`, cl.`name` AS class_name,
                    ses.`room_id`, r.`name` AS room_name,
                    ses.`academic_term_id`,
                    COUNT(ar.`id`) AS total,
                    SUM(CASE WHEN ar.`status` = 'PRESENT'        THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.`status` = 'ABSENT'         THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN ar.`status` = 'LATE'           THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.`status` = 'EXCUSED'        THEN 1 ELSE 0 END) AS excused,
                    SUM(CASE WHEN ar.`status` = 'WAITING'        THEN 1 ELSE 0 END) AS waiting,
                    SUM(CASE WHEN ar.`status` = 'PENDING_REVIEW' THEN 1 ELSE 0 END) AS pending_review
               FROM `attendance_sessions` ses
               JOIN `courses` c  ON c.`id`  = ses.`course_id`
               JOIN `classes` cl ON cl.`id` = ses.`class_id`
               JOIN `rooms` r    ON r.`id`  = ses.`room_id`
               LEFT JOIN `attendance_records` ar ON ar.`attendance_session_id` = ses.`id`
              WHERE ses.`teacher_id` = :tid AND ses.`status` IN ({$in})
              GROUP BY ses.`id`
              ORDER BY ses.`start_time` DESC
              LIMIT {$limit}",
            $bindings,
        );
    }

    /**
     * Overall attendance totals across every session this teacher has run
     * (spec §12 "Toplam katılım").
     *
     * @return array<string, int>
     */
    public function totalsForTeacher(int $teacherId): array
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT ses.`id`) AS sessions,
                    COUNT(ar.`id`) AS total,
                    SUM(CASE WHEN ar.`status` = 'PRESENT' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.`status` = 'ABSENT'  THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN ar.`status` = 'LATE'    THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.`status` = 'EXCUSED' THEN 1 ELSE 0 END) AS excused,
                    SUM(CASE WHEN ar.`status` = 'WAITING' THEN 1 ELSE 0 END) AS waiting
               FROM `attendance_sessions` ses
               LEFT JOIN `attendance_records` ar ON ar.`attendance_session_id` = ses.`id`
              WHERE ses.`teacher_id` = :tid",
            ['tid' => $teacherId],
        ) ?? [];

        return [
            'sessions' => (int) ($row['sessions'] ?? 0),
            'total'    => (int) ($row['total'] ?? 0),
            'present'  => (int) ($row['present'] ?? 0),
            'absent'   => (int) ($row['absent'] ?? 0),
            'late'     => (int) ($row['late'] ?? 0),
            'excused'  => (int) ($row['excused'] ?? 0),
            'waiting'  => (int) ($row['waiting'] ?? 0),
        ];
    }
}
