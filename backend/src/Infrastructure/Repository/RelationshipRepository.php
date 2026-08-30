<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Relationship repository — the data source for relationship-based authorization.
 *
 * Answers questions of the form "does this user stand in the required academic
 * relationship to this resource?", e.g.:
 * - is this teacher assigned to teach this course to this class?
 * - is this student enrolled in this course / class?
 *
 * Lookup paths follow database/docs/RELATIONSHIPS.md §8 (Cross-Domain Lookup
 * Path) and database/docs/TABLES.md Group 3 & 4.
 *
 * IMPORTANT (docs/ACCEPTED_DEVIATIONS.md AD-001): the underlying tables
 * (`teachers`, `students`, `teacher_class_assignments`, `teacher_courses`,
 * `student_courses`, `student_class_assignments`) are created in the Academic
 * Structure phase (Phase 8). Until then these queries raise on a missing table;
 * callers in {@see \QRIVO\Application\Service\AuthorizationService} treat any
 * failure as "relationship not established" — i.e. deny by default, never
 * fail-open.
 *
 * Security:
 * - All queries are parameterized.
 * - A relationship is proven only by a row in the assignment tables — never by
 *   anything the client sends.
 */
final class RelationshipRepository extends BaseRepository
{
    /** Resolve the internal teachers.id for a user, or null. */
    public function findTeacherIdForUser(int $userId): ?int
    {
        $row = $this->safeFetchOne(
            'SELECT `id` FROM `teachers` WHERE `user_id` = :uid AND `deleted_at` IS NULL LIMIT 1',
            ['uid' => $userId]
        );

        return $row !== null ? (int) $row['id'] : null;
    }

    /** Resolve the internal students.id for a user, or null. */
    public function findStudentIdForUser(int $userId): ?int
    {
        $row = $this->safeFetchOne(
            'SELECT `id` FROM `students` WHERE `user_id` = :uid AND `deleted_at` IS NULL LIMIT 1',
            ['uid' => $userId]
        );

        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Is the teacher (by user id) assigned to teach $courseId to $classId?
     * This is the authorization basis for attendance session creation
     * (RELATIONSHIPS.md §4, teacher_class_assignments).
     */
    public function teacherAssignedToClassCourse(
        int $userId,
        int $classId,
        int $courseId,
        ?int $academicTermId = null,
    ): bool {
        $sql =
            'SELECT 1
               FROM `teacher_class_assignments` tca
               JOIN `teachers` t ON t.`id` = tca.`teacher_id`
              WHERE t.`user_id` = :uid
                AND t.`deleted_at` IS NULL
                AND tca.`class_id` = :cid
                AND tca.`course_id` = :coid';
        $bindings = ['uid' => $userId, 'cid' => $classId, 'coid' => $courseId];

        if ($academicTermId !== null) {
            $sql .= ' AND tca.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    /** Is the teacher (by user id) assigned to any course for $classId? */
    public function teacherAssignedToClass(int $userId, int $classId, ?int $academicTermId = null): bool
    {
        $sql =
            'SELECT 1
               FROM `teacher_class_assignments` tca
               JOIN `teachers` t ON t.`id` = tca.`teacher_id`
              WHERE t.`user_id` = :uid
                AND t.`deleted_at` IS NULL
                AND tca.`class_id` = :cid';
        $bindings = ['uid' => $userId, 'cid' => $classId];

        if ($academicTermId !== null) {
            $sql .= ' AND tca.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    /** Is the teacher (by user id) responsible for $courseId (teacher_courses)? */
    public function teacherTeachesCourse(int $userId, int $courseId, ?int $academicTermId = null): bool
    {
        $sql =
            'SELECT 1
               FROM `teacher_courses` tc
               JOIN `teachers` t ON t.`id` = tc.`teacher_id`
              WHERE t.`user_id` = :uid
                AND t.`deleted_at` IS NULL
                AND tc.`course_id` = :coid';
        $bindings = ['uid' => $userId, 'coid' => $courseId];

        if ($academicTermId !== null) {
            $sql .= ' AND tc.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    /**
     * Is the student (by user id) enrolled in $courseId?
     * Challenge-response validation step 8 (RELATIONSHIPS.md §8, student_courses).
     */
    public function studentEnrolledInCourse(int $userId, int $courseId, ?int $academicTermId = null): bool
    {
        $sql =
            'SELECT 1
               FROM `student_courses` sc
               JOIN `students` s ON s.`id` = sc.`student_id`
              WHERE s.`user_id` = :uid
                AND s.`deleted_at` IS NULL
                AND sc.`course_id` = :coid';
        $bindings = ['uid' => $userId, 'coid' => $courseId];

        if ($academicTermId !== null) {
            $sql .= ' AND sc.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    /**
     * Is the student (by user id) enrolled in $classId?
     * Challenge-response validation step 9 (student_class_assignments).
     */
    public function studentEnrolledInClass(int $userId, int $classId, ?int $academicTermId = null): bool
    {
        $sql =
            'SELECT 1
               FROM `student_class_assignments` sca
               JOIN `students` s ON s.`id` = sca.`student_id`
              WHERE s.`user_id` = :uid
                AND s.`deleted_at` IS NULL
                AND sca.`class_id` = :cid';
        $bindings = ['uid' => $userId, 'cid' => $classId];

        if ($academicTermId !== null) {
            $sql .= ' AND sca.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    // ─── Fail-closed helpers ─────────────────────────────────────────────────────

    /**
     * Like Connection::fetchOne(), but returns null instead of throwing when the
     * relationship tables do not exist yet (pre-Phase 8). Deny by default.
     *
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    private function safeFetchOne(string $sql, array $bindings): ?array
    {
        try {
            return $this->db->fetchOne($sql, $bindings);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function safeExists(string $sql, array $bindings): bool
    {
        try {
            return $this->db->fetchOne($sql, $bindings) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
