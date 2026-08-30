<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Cross-table queries for course scheduling: the teacher-attendance
 * authorization lookup, schedule/room/teacher conflict detection, and the
 * `student_courses` derivation (DD-005).
 *
 * All identifiers are code constants; all values are bound.
 */
final class ScheduleRepository extends BaseRepository
{
    // ─── Attendance authorization lookup ───────────────────────────────────────

    /**
     * Find the teacher_class_assignment for (teacher-by-user, class, course).
     * When $academicTermId is null, the assignment is matched against the active
     * academic term.
     *
     * @return array<string, mixed>|null
     */
    public function findAssignmentForTeacherUser(
        int $userId,
        int $classId,
        int $courseId,
        ?int $academicTermId,
    ): ?array {
        $sql =
            'SELECT tca.*
               FROM `teacher_class_assignments` tca
               JOIN `teachers` t ON t.`id` = tca.`teacher_id` AND t.`deleted_at` IS NULL
               JOIN `academic_terms` term ON term.`id` = tca.`academic_term_id`
              WHERE t.`user_id` = :uid
                AND tca.`class_id` = :cid
                AND tca.`course_id` = :coid';
        $bindings = ['uid' => $userId, 'cid' => $classId, 'coid' => $courseId];

        if ($academicTermId !== null) {
            $sql .= ' AND tca.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        } else {
            $sql .= ' AND term.`is_active` = 1';
        }

        return $this->db->fetchOne($sql . ' ORDER BY tca.`id` DESC LIMIT 1', $bindings);
    }

    /**
     * The course_schedules row for an assignment that covers ($dayOfWeek, $time),
     * i.e. start_time <= time <= end_time. $time is 'HH:MM' or 'HH:MM:SS'.
     *
     * @return array<string, mixed>|null
     */
    public function findCoveringSchedule(int $teacherClassAssignmentId, int $dayOfWeek, string $time): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `course_schedules`
              WHERE `teacher_class_assignment_id` = :tca
                AND `day_of_week` = :dow
                AND `start_time` <= :t1
                AND `end_time`   >= :t2
              ORDER BY `start_time` ASC
              LIMIT 1',
            ['tca' => $teacherClassAssignmentId, 'dow' => $dayOfWeek, 't1' => $time, 't2' => $time],
        );
    }

    /** Is there at least one active academic term? */
    public function activeTermExists(): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM `academic_terms` WHERE `is_active` = 1 LIMIT 1') !== null;
    }

    public function assignmentHasScheduleOnDay(int $teacherClassAssignmentId, int $dayOfWeek): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `course_schedules`
              WHERE `teacher_class_assignment_id` = :tca AND `day_of_week` = :dow LIMIT 1',
            ['tca' => $teacherClassAssignmentId, 'dow' => $dayOfWeek],
        ) !== null;
    }

    // ─── Conflict detection for course_schedules writes ────────────────────────

    /**
     * Does another schedule use $roomId on $dayOfWeek with a time range that
     * overlaps [$start, $end)? Overlap: existing.start < new.end AND existing.end > new.start.
     */
    public function roomHasConflict(int $roomId, int $dayOfWeek, string $start, string $end, ?int $exceptId = null): bool
    {
        $sql =
            'SELECT 1 FROM `course_schedules`
              WHERE `room_id` = :room AND `day_of_week` = :dow
                AND `start_time` < :end AND `end_time` > :start';
        $bindings = ['room' => $roomId, 'dow' => $dayOfWeek, 'start' => $start, 'end' => $end];
        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :except';
            $bindings['except'] = $exceptId;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $bindings) !== null;
    }

    /**
     * Does the teacher behind $teacherClassAssignmentId already have an
     * overlapping schedule on $dayOfWeek (any of their assignments)?
     */
    public function teacherHasConflict(
        int $teacherClassAssignmentId,
        int $dayOfWeek,
        string $start,
        string $end,
        ?int $exceptId = null,
    ): bool {
        $sql =
            'SELECT 1
               FROM `course_schedules` cs
               JOIN `teacher_class_assignments` tca  ON tca.`id` = cs.`teacher_class_assignment_id`
               JOIN `teacher_class_assignments` self ON self.`id` = :tca
              WHERE tca.`teacher_id` = self.`teacher_id`
                AND cs.`day_of_week` = :dow
                AND cs.`start_time` < :end AND cs.`end_time` > :start';
        $bindings = ['tca' => $teacherClassAssignmentId, 'dow' => $dayOfWeek, 'start' => $start, 'end' => $end];
        if ($exceptId !== null) {
            $sql .= ' AND cs.`id` <> :except';
            $bindings['except'] = $exceptId;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $bindings) !== null;
    }

    // ─── Relationship-consistency checks for assignment writes ─────────────────

    /** Is $courseId offered to $classId in $termId (class_courses)? */
    public function courseOfferedToClass(int $classId, int $courseId, int $termId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `class_courses`
              WHERE `class_id` = :cid AND `course_id` = :coid AND `academic_term_id` = :tid LIMIT 1',
            ['cid' => $classId, 'coid' => $courseId, 'tid' => $termId],
        ) !== null;
    }

    /** Is $teacherId responsible for $courseId in $termId (teacher_courses)? */
    public function teacherResponsibleForCourse(int $teacherId, int $courseId, int $termId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `teacher_courses`
              WHERE `teacher_id` = :tid AND `course_id` = :coid AND `academic_term_id` = :term LIMIT 1',
            ['tid' => $teacherId, 'coid' => $courseId, 'term' => $termId],
        ) !== null;
    }

    // ─── student_courses derivation (DD-005) ──────────────────────────────────

    /**
     * Materialise `student_courses` rows for a (student, class, term) enrollment
     * from `class_courses`. Single atomic INSERT … SELECT; idempotent.
     */
    public function syncStudentCourses(int $studentId, int $classId, int $termId): int
    {
        return $this->db->execute(
            'INSERT INTO `student_courses` (`student_id`, `course_id`, `class_id`, `academic_term_id`, `created_at`)
             SELECT :sid, cc.`course_id`, cc.`class_id`, cc.`academic_term_id`, :now
               FROM `class_courses` cc
              WHERE cc.`class_id` = :cid AND cc.`academic_term_id` = :tid
                AND NOT EXISTS (
                    SELECT 1 FROM `student_courses` sc
                     WHERE sc.`student_id` = :sid2 AND sc.`course_id` = cc.`course_id`
                       AND sc.`academic_term_id` = cc.`academic_term_id`
                )',
            ['sid' => $studentId, 'sid2' => $studentId, 'cid' => $classId, 'tid' => $termId, 'now' => date('Y-m-d H:i:s')],
        );
    }

    /** Remove the derived `student_courses` rows for a (student, class, term) enrollment. */
    public function unsyncStudentCourses(int $studentId, int $classId, int $termId): int
    {
        return $this->db->execute(
            'DELETE FROM `student_courses`
              WHERE `student_id` = :sid AND `class_id` = :cid AND `academic_term_id` = :tid',
            ['sid' => $studentId, 'cid' => $classId, 'tid' => $termId],
        );
    }

    /**
     * When a course is added to / removed from a class, refresh every enrolled
     * student's derived rows for that (class, term).
     */
    public function resyncClassCourses(int $classId, int $termId): void
    {
        $this->db->execute(
            'INSERT INTO `student_courses` (`student_id`, `course_id`, `class_id`, `academic_term_id`, `created_at`)
             SELECT sca.`student_id`, cc.`course_id`, cc.`class_id`, cc.`academic_term_id`, :now
               FROM `student_class_assignments` sca
               JOIN `class_courses` cc
                 ON cc.`class_id` = sca.`class_id` AND cc.`academic_term_id` = sca.`academic_term_id`
              WHERE sca.`class_id` = :cid AND sca.`academic_term_id` = :tid
                AND NOT EXISTS (
                    SELECT 1 FROM `student_courses` sc
                     WHERE sc.`student_id` = sca.`student_id` AND sc.`course_id` = cc.`course_id`
                       AND sc.`academic_term_id` = cc.`academic_term_id`
                )',
            ['cid' => $classId, 'tid' => $termId, 'now' => date('Y-m-d H:i:s')],
        );
    }

    /** Drop derived rows for a course no longer offered to a class in a term. */
    public function pruneStudentCoursesForClassCourse(int $classId, int $courseId, int $termId): int
    {
        return $this->db->execute(
            'DELETE FROM `student_courses`
              WHERE `class_id` = :cid AND `course_id` = :coid AND `academic_term_id` = :tid',
            ['cid' => $classId, 'coid' => $courseId, 'tid' => $termId],
        );
    }
}
