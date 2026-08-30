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
}
