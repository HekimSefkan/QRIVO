<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * DeviceSession repository.
 *
 * Manages authentication sessions in `device_sessions`.
 *
 * Security:
 * - Tokens are stored as SHA-256 hashes only. Raw tokens are NEVER stored.
 * - findByAccessTokenHash() and findByRefreshTokenHash() receive pre-hashed values.
 * - revoke() sets revoked_at; revoked sessions must always be rejected.
 */
final class DeviceSessionRepository extends BaseRepository
{
    /**
     * Find an active session by its hashed access token.
     *
     * @return array<string, mixed>|null
     */
    public function findByAccessTokenHash(string $hash): ?array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->fetchOne(
            'SELECT * FROM `device_sessions`
              WHERE `access_token_hash` = :hash
                AND `revoked_at` IS NULL
                AND `expires_at` > :now
              LIMIT 1',
            ['hash' => $hash, 'now' => $now]
        );
    }

    /**
     * Find a session by its hashed refresh token (including revoked, for reuse detection).
     *
     * @return array<string, mixed>|null
     */
    public function findByRefreshTokenHash(string $hash): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `device_sessions`
              WHERE `refresh_token_hash` = :hash
              LIMIT 1',
            ['hash' => $hash]
        );
    }

    /**
     * Create a new device session record.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        return $this->insert('device_sessions', $data);
    }

    /**
     * Revoke a session (logout). Sets revoked_at to NOW().
     */
    public function revoke(int $sessionId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE `device_sessions` SET `revoked_at` = :now, `updated_at` = :now2
              WHERE `id` = :id AND `revoked_at` IS NULL',
            ['now' => $now, 'now2' => $now, 'id' => $sessionId]
        );
    }

    /**
     * Update last_active_at timestamp for an active session.
     */
    public function updateLastActive(int $sessionId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE `device_sessions` SET `last_active_at` = :now, `updated_at` = :now2
              WHERE `id` = :id',
            ['now' => $now, 'now2' => $now, 'id' => $sessionId]
        );
    }
}
