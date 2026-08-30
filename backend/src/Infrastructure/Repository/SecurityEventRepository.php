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
