<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * SecurityEvent repository.
 *
 * Writes structured security events to `security_events`.
 * This table is append-only.
 *
 * Security (SECURITY_RULES.md §10, SECURITY_RULES.md §9):
 * - details JSON must NEVER contain passwords, tokens, or private keys.
 * - The caller is responsible for sanitizing details before calling create().
 */
final class SecurityEventRepository extends BaseRepository
{
    /**
     * Record a security event.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $this->insert('security_events', $data);
    }

    /**
     * Paginated, filtered read for the admin security-event trail
     * (PROJECT_SPECIFICATION.md §6.15). Newest first.
     *
     * @param array<string, mixed> $filters
     *        event_type?, severity?, user_id?, attendance_session_id?, from?, to?
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        [$where, $bindings] = $this->buildFilter($filters, [
            'event_type'            => 'event_type',
            'severity'              => 'severity',
            'user_id'               => 'user_id',
            'attendance_session_id' => 'attendance_session_id',
        ]);

        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `security_events` {$where}",
            $bindings,
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT `id`, `event_type`, `severity`, `user_id`, `attendance_session_id`,
                    `ip_address`, `user_agent`, `details`, `created_at`
               FROM `security_events` {$where}
              ORDER BY `created_at` DESC, `id` DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * @param array<string, mixed>  $filters
     * @param array<string, string> $columns  filter key => column
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilter(array $filters, array $columns): array
    {
        $clauses  = [];
        $bindings = [];

        foreach ($columns as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $clauses[]        = "`{$column}` = :{$key}";
                $bindings[$key]   = $filters[$key];
            }
        }
        if (isset($filters['from']) && $filters['from'] !== '') {
            $clauses[]        = '`created_at` >= :from';
            $bindings['from'] = (string) $filters['from'];
        }
        if (isset($filters['to']) && $filters['to'] !== '') {
            $clauses[]      = '`created_at` <= :to';
            $bindings['to'] = (string) $filters['to'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * Count this user's security events by type since a cutoff. Used by risk
     * scoring to weigh recent abuse (ATTENDANCE_ALGORITHM.md §9).
     *
     * @return array<string, int> event_type => count
     */
    public function countByUserSince(int $userId, string $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `event_type`, COUNT(*) AS c
               FROM `security_events`
              WHERE `user_id` = :uid AND `created_at` >= :since
           GROUP BY `event_type`',
            ['uid' => $userId, 'since' => $since],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['event_type']] = (int) $row['c'];
        }

        return $out;
    }
}
