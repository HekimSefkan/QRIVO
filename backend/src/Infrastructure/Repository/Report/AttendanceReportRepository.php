<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Report;

use QRIVO\Infrastructure\Repository\BaseRepository;

/**
 * Aggregation queries behind the attendance reports (PROJECT_SPECIFICATION.md
 * §6.16). Read-only over `attendance_records` / `attendance_sessions`.
 *
 * Every method takes an already-authorized filter map — this repository does NOT
 * make authorization decisions; the report services do, then hand a scoped
 * filter set here. All filters are whitelisted and bound as parameters.
 *
 * `from` / `to` bound `attendance_sessions.start_time`.
 */
final class AttendanceReportRepository extends BaseRepository
{
    /** filter key => SQL column it constrains (equality). */
    private const SCALAR_FILTERS = [
        'school_id'        => 'f.school_id',
        'faculty_id'       => 'd.faculty_id',
        'department_id'    => 'c.department_id',
        'program_id'       => 'cl.program_id',
        'course_id'        => 'ses.course_id',
        'class_id'         => 'ses.class_id',
        'academic_term_id' => 'ses.academic_term_id',
        'teacher_id'       => 'ses.teacher_id',
        'student_id'       => 'ar.student_id',
        'status'           => 'ar.status',
        'source'           => 'ar.source',
        'session_status'   => 'ses.status',
    ];

    /** filter key => SQL column for `IN (...)` list constraints (teacher scoping). */
    private const LIST_FILTERS = [
        'course_ids' => 'ses.course_id',
        'class_ids'  => 'ses.class_id',
    ];

    private const BASE_FROM = <<<'SQL'
        FROM `attendance_records` ar
        JOIN `attendance_sessions` ses ON ses.`id` = ar.`attendance_session_id`
        JOIN `courses` c        ON c.`id`  = ses.`course_id`
        JOIN `classes` cl       ON cl.`id` = ses.`class_id`
        JOIN `departments` d    ON d.`id`  = c.`department_id`
        JOIN `faculties` f      ON f.`id`  = d.`faculty_id`
        JOIN `programs` p       ON p.`id`  = cl.`program_id`
        JOIN `students` s       ON s.`id`  = ar.`student_id`
        JOIN `users` u          ON u.`id`  = s.`user_id`
        SQL;

    private const STATUS_AGGREGATES = <<<'SQL'
        COUNT(*) AS total,
        COUNT(DISTINCT ses.`id`) AS sessions,
        SUM(CASE WHEN ar.`status` = 'PRESENT'        THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN ar.`status` = 'ABSENT'         THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN ar.`status` = 'LATE'           THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN ar.`status` = 'EXCUSED'        THEN 1 ELSE 0 END) AS excused,
        SUM(CASE WHEN ar.`status` = 'PENDING_REVIEW' THEN 1 ELSE 0 END) AS pending_review,
        SUM(CASE WHEN ar.`status` = 'WAITING'        THEN 1 ELSE 0 END) AS waiting
        SQL;

    /** dimension => [key expression, label expression, order-by]. */
    private const DIMENSIONS = [
        'course'         => ['ses.`course_id`',                 'c.`name`',                        'present DESC, label ASC'],
        'class'          => ['ses.`class_id`',                  'cl.`name`',                       'present DESC, label ASC'],
        'department'     => ['c.`department_id`',               'd.`name`',                        'present DESC, label ASC'],
        'program'        => ['cl.`program_id`',                 'p.`name`',                        'present DESC, label ASC'],
        'student'        => ['ar.`student_id`',                 "s.`student_number`",              'label ASC'],
        'day'            => ['SUBSTR(ses.`start_time`, 1, 10)', 'SUBSTR(ses.`start_time`, 1, 10)', 'group_key ASC'],
        'status'         => ['ar.`status`',                     'ar.`status`',                     'total DESC'],
        'source'         => ['ar.`source`',                     'ar.`source`',                     'total DESC'],
        'session_status' => ['ses.`status`',                    'ses.`status`',                    'total DESC'],
    ];

    // ─── scalar summary ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function summary(array $filters): array
    {
        [$where, $bindings] = $this->buildWhere($filters);

        $row = $this->db->fetchOne(
            'SELECT ' . self::STATUS_AGGREGATES . ' ' . self::BASE_FROM . ' ' . $where,
            $bindings,
        ) ?? [];

        return $this->normaliseCounts($row);
    }

    // ─── grouped summary ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>  each: {key, label, ...counts}
     */
    public function groupedSummary(array $filters, string $dimension, int $limit = 500): array
    {
        if (!isset(self::DIMENSIONS[$dimension])) {
            throw new \InvalidArgumentException("Unknown report dimension: {$dimension}");
        }
        [$keyExpr, $labelExpr, $orderBy] = self::DIMENSIONS[$dimension];
        [$where, $bindings] = $this->buildWhere($filters);
        $limit = max(1, min(2000, $limit));

        $rows = $this->db->fetchAll(
            "SELECT {$keyExpr} AS group_key, {$labelExpr} AS label, " . self::STATUS_AGGREGATES . ' '
            . self::BASE_FROM . ' ' . $where
            . " GROUP BY {$keyExpr} ORDER BY {$orderBy} LIMIT {$limit}",
            $bindings,
        );

        return array_map(function (array $row): array {
            $key   = $row['group_key'];
            $label = $row['label'];
            unset($row['group_key'], $row['label']);

            return ['key' => $key, 'label' => $label] + $this->normaliseCounts($row);
        }, $rows);
    }

    // ─── paginated: record level ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function paginatedRecords(array $filters, int $page, int $perPage): array
    {
        [$where, $bindings] = $this->buildWhere($filters);
        [$page, $perPage, $offset] = $this->page($page, $perPage);

        $total = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c ' . self::BASE_FROM . ' ' . $where,
            $bindings,
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT ar.`id`, ar.`status`, ar.`source`, ar.`marked_at`,
                    ses.`id` AS session_id, ses.`uuid` AS session_uuid,
                    ses.`start_time`, ses.`end_time`, ses.`status` AS session_status,
                    ses.`course_id`, c.`name` AS course_name, c.`code` AS course_code,
                    ses.`class_id`, cl.`name` AS class_name,
                    ses.`academic_term_id`, ses.`teacher_id`,
                    ar.`student_id`, s.`student_number`, u.`first_name`, u.`last_name`
             " . self::BASE_FROM . ' ' . $where . "
             ORDER BY ses.`start_time` DESC, ar.`id` DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return ['data' => $rows, 'total' => $total];
    }

    // ─── paginated: session level ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function paginatedSessions(array $filters, int $page, int $perPage): array
    {
        [$where, $bindings] = $this->buildWhere($filters);
        [$page, $perPage, $offset] = $this->page($page, $perPage);

        $total = (int) ($this->db->fetchOne(
            'SELECT COUNT(DISTINCT ses.`id`) AS c ' . self::BASE_FROM . ' ' . $where,
            $bindings,
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT ses.`id`, ses.`uuid`, ses.`start_time`, ses.`end_time`, ses.`status` AS session_status,
                    ses.`course_id`, c.`name` AS course_name, ses.`class_id`, cl.`name` AS class_name,
                    ses.`room_id`, ses.`academic_term_id`, ses.`teacher_id`,
                    " . self::STATUS_AGGREGATES . ' '
            . self::BASE_FROM . ' ' . $where . "
             GROUP BY ses.`id`
             ORDER BY ses.`start_time` DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'  => array_map(fn (array $r): array => $this->splitSessionRow($r), $rows),
            'total' => $total,
        ];
    }

    // ─── paginated: student breakdown ─────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function paginatedStudentBreakdown(array $filters, int $page, int $perPage): array
    {
        [$where, $bindings] = $this->buildWhere($filters);
        [$page, $perPage, $offset] = $this->page($page, $perPage);

        $total = (int) ($this->db->fetchOne(
            'SELECT COUNT(DISTINCT ar.`student_id`) AS c ' . self::BASE_FROM . ' ' . $where,
            $bindings,
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT ar.`student_id`, s.`student_number`, u.`first_name`, u.`last_name`,
                    " . self::STATUS_AGGREGATES . ' '
            . self::BASE_FROM . ' ' . $where . "
             GROUP BY ar.`student_id`
             ORDER BY s.`student_number` ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'  => array_map(function (array $r): array {
                $student = [
                    'student_id'     => (int) $r['student_id'],
                    'student_number' => $r['student_number'],
                    'first_name'     => $r['first_name'],
                    'last_name'      => $r['last_name'],
                ];
                unset($r['student_id'], $r['student_number'], $r['first_name'], $r['last_name']);

                return $student + $this->normaliseCounts($r);
            }, $rows),
            'total' => $total,
        ];
    }

    // ─── internals ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses  = [];
        $bindings = [];

        foreach (self::SCALAR_FILTERS as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== null) {
                $clauses[]      = "{$column} = :{$key}";
                $bindings[$key] = $filters[$key];
            }
        }

        foreach (self::LIST_FILTERS as $key => $column) {
            if (isset($filters[$key]) && is_array($filters[$key]) && $filters[$key] !== []) {
                $names = [];
                foreach (array_values($filters[$key]) as $i => $value) {
                    $ph = ":{$key}_{$i}";
                    $names[]       = $ph;
                    $bindings["{$key}_{$i}"] = $value;
                }
                $clauses[] = "{$column} IN (" . implode(', ', $names) . ')';
            }
        }

        if (isset($filters['from']) && $filters['from'] !== '') {
            $clauses[]        = 'ses.`start_time` >= :from';
            $bindings['from'] = (string) $filters['from'];
        }
        if (isset($filters['to']) && $filters['to'] !== '') {
            $clauses[]      = 'ses.`start_time` <= :to';
            $bindings['to'] = (string) $filters['to'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function page(int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseCounts(array $row): array
    {
        $present  = (int) ($row['present'] ?? 0);
        $late     = (int) ($row['late'] ?? 0);
        $absent   = (int) ($row['absent'] ?? 0);
        $excused  = (int) ($row['excused'] ?? 0);
        $pending  = (int) ($row['pending_review'] ?? 0);
        $waiting  = (int) ($row['waiting'] ?? 0);
        $total    = (int) ($row['total'] ?? 0);
        $marked   = $total - $waiting;

        return [
            'counts' => [
                'present'        => $present,
                'late'           => $late,
                'absent'         => $absent,
                'excused'        => $excused,
                'pending_review' => $pending,
                'waiting'        => $waiting,
            ],
            'total_records'  => $total,
            'marked_records' => $marked,
            'sessions'       => (int) ($row['sessions'] ?? 0),
            'present_rate'   => $marked > 0 ? round($present / $marked, 4) : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function splitSessionRow(array $r): array
    {
        $session = [
            'session_id'     => (int) $r['id'],
            'session_uuid'   => $r['uuid'],
            'start_time'     => $r['start_time'],
            'end_time'       => $r['end_time'],
            'session_status' => $r['session_status'],
            'course_id'      => (int) $r['course_id'],
            'course_name'    => $r['course_name'],
            'class_id'       => (int) $r['class_id'],
            'class_name'     => $r['class_name'],
            'room_id'        => (int) $r['room_id'],
            'academic_term_id' => (int) $r['academic_term_id'],
            'teacher_id'     => (int) $r['teacher_id'],
        ];
        foreach (['id', 'uuid', 'start_time', 'end_time', 'session_status', 'course_id', 'course_name', 'class_id', 'class_name', 'room_id', 'academic_term_id', 'teacher_id'] as $k) {
            unset($r[$k]);
        }

        return $session + $this->normaliseCounts($r);
    }
}
