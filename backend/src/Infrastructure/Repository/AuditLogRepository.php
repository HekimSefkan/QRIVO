<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * AuditLog repository.
 *
 * Writes audit trail records to `audit_logs`.
 * This table is append-only.
 *
 * Security (SECURITY_RULES.md §11):
 * - old_value and new_value JSON must NEVER contain secrets.
 * - The caller is responsible for sanitizing values before calling create().
 */
final class AuditLogRepository extends BaseRepository
{
    /**
     * Record an audit log entry. Returns the new id.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return (int) $this->insert('audit_logs', $data);
    }

    /**
     * Paginated, filtered read for the admin audit trail
     * (SECURITY_RULES.md §11). Newest first.
     *
     * @param array<string, mixed> $filters
     *        event_type?, actor_user_id?, target_entity?, target_id?, from?, to?
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $columns = [
            'event_type'    => 'event_type',
            'actor_user_id' => 'actor_user_id',
            'target_entity' => 'target_entity',
            'target_id'     => 'target_id',
        ];

        $clauses  = [];
        $bindings = [];
        foreach ($columns as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $clauses[]      = "`{$column}` = :{$key}";
                $bindings[$key] = $filters[$key];
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
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `audit_logs` {$where}",
            $bindings,
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT `id`, `event_type`, `actor_user_id`, `target_entity`, `target_id`,
                    `old_value`, `new_value`, `reason`, `ip_address`, `created_at`
               FROM `audit_logs` {$where}
              ORDER BY `created_at` DESC, `id` DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return ['data' => $rows, 'total' => $total];
    }
}
