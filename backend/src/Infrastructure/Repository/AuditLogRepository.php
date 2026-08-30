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
}
