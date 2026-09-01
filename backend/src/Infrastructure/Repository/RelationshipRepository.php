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

    /** Resolve the `users.id` behind a `students.id`, or null. */
    public function findUserIdForStudent(int $studentId): ?int
    {
        $row = $this->safeFetchOne(
            'SELECT `user_id` FROM `students` WHERE `id` = :sid LIMIT 1',
            ['sid' => $studentId]
        );

        return $row !== null ? (int) $row['user_id'] : null;
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

    /**
     * Is the student (by `students.id`) enrolled in $classId?
     * Manual-attendance step 4 (student membership verification).
     */
    public function studentIdEnrolledInClass(int $studentId, int $classId, ?int $academicTermId = null): bool
    {
        $sql =
            'SELECT 1
               FROM `student_class_assignments` sca
               JOIN `students` s ON s.`id` = sca.`student_id`
              WHERE sca.`student_id` = :sid
                AND s.`deleted_at` IS NULL
                AND sca.`class_id` = :cid';
        $bindings = ['sid' => $studentId, 'cid' => $classId];

        if ($academicTermId !== null) {
            $sql .= ' AND sca.`academic_term_id` = :tid';
            $bindings['tid'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    /**
     * The class ids a teacher (by user id) is assigned to teach. Used to scope
     * teacher reports (PROJECT_SPECIFICATION.md §6.16 — "only their authorized
     * courses/classes").
     *
     * @return list<int>
     */
    public function teacherClassIds(int $userId, ?int $academicTermId = null): array
    {
        return $this->idList(
            'SELECT DISTINCT tca.`class_id` AS v
               FROM `teacher_class_assignments` tca
               JOIN `teachers` t ON t.`id` = tca.`teacher_id`
              WHERE t.`user_id` = :uid AND t.`deleted_at` IS NULL',
            'tca.`academic_term_id`',
            $userId,
            $academicTermId,
        );
    }

    /**
     * The course ids a teacher (by user id) is responsible for.
     *
     * @return list<int>
     */
    public function teacherCourseIds(int $userId, ?int $academicTermId = null): array
    {
        return $this->idList(
            'SELECT DISTINCT tc.`course_id` AS v
               FROM `teacher_courses` tc
               JOIN `teachers` t ON t.`id` = tc.`teacher_id`
              WHERE t.`user_id` = :uid AND t.`deleted_at` IS NULL',
            'tc.`academic_term_id`',
            $userId,
            $academicTermId,
        );
    }

    /**
     * Does this teacher (by user id) share at least one class with this student
     * (by `students.id`)? The authorization basis for a teacher viewing an
     * individual student's attendance report.
     */
    public function teacherSharesClassWithStudent(int $userId, int $studentId, ?int $academicTermId = null): bool
    {
        $sql =
            'SELECT 1
               FROM `teacher_class_assignments` tca
               JOIN `teachers` t ON t.`id` = tca.`teacher_id`
               JOIN `student_class_assignments` sca ON sca.`class_id` = tca.`class_id`
              WHERE t.`user_id` = :uid
                AND t.`deleted_at` IS NULL
                AND sca.`student_id` = :sid';
        $bindings = ['uid' => $userId, 'sid' => $studentId];

        if ($academicTermId !== null) {
            // Distinct placeholders per occurrence — MySQL rejects a reused named
            // parameter with SQLSTATE[HY093] when prepares are not emulated.
            // Reuse here was silently swallowed by safeExists(), denying a
            // legitimate teacher instead of crashing.
            $sql .= ' AND tca.`academic_term_id` = :tid1 AND sca.`academic_term_id` = :tid2';
            $bindings['tid1'] = $academicTermId;
            $bindings['tid2'] = $academicTermId;
        }

        return $this->safeExists($sql . ' LIMIT 1', $bindings);
    }

    /**
     * @return list<int>
     */
    private function idList(string $sql, string $termColumn, int $userId, ?int $academicTermId): array
    {
        $bindings = ['uid' => $userId];
        if ($academicTermId !== null) {
            $sql .= " AND {$termColumn} = :tid";
            $bindings['tid'] = $academicTermId;
        }

        try {
            $rows = $this->db->fetchAll($sql, $bindings);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $r): int => (int) $r['v'], $rows);
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
