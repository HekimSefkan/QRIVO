<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Read access to `system_settings` (TABLES.md Group 8).
 *
 * Configurable system parameters — risk-scoring thresholds and attendance
 * behaviour. Keys are dot-notation strings (e.g. `risk.weight.replay_attempt`);
 * `value` is a string-encoded scalar interpreted per `type`.
 *
 * Reads are fail-safe: a missing table (a database migrated only up to an
 * earlier phase) yields an empty map rather than an error, so callers fall back
 * to `config/*.php`.
 */
final class SystemSettingRepository extends BaseRepository
{
    /**
     * @return array<string, string> key => raw string value
     */
    public function allWithPrefix(string $prefix): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT `key`, `value` FROM `system_settings` WHERE `key` LIKE :p',
                ['p' => $prefix . '%'],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * A single setting value, or $default when the key (or the table) is absent.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT `value` FROM `system_settings` WHERE `key` = :k LIMIT 1',
                ['k' => $key],
            );
        } catch (\Throwable) {
            return $default;
        }

        return $row === null ? $default : (string) $row['value'];
    }
}
