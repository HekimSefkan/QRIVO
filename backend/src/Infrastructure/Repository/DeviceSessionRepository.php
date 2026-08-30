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

    // ─── Device session security (Phase 18) ──────────────────────────────────

    /**
     * Record activity on a session: refresh `last_active_at` and, when the
     * request came from a new IP, update `ip_address` so a change is only ever
     * flagged once.
     */
    public function touchActivity(int $sessionId, ?string $ipAddress): void
    {
        $now = date('Y-m-d H:i:s');

        if ($ipAddress === null || $ipAddress === '') {
            $this->db->execute(
                'UPDATE `device_sessions` SET `last_active_at` = :now, `updated_at` = :now2 WHERE `id` = :id',
                ['now' => $now, 'now2' => $now, 'id' => $sessionId],
            );
            return;
        }

        $this->db->execute(
            'UPDATE `device_sessions`
                SET `last_active_at` = :now, `ip_address` = :ip, `updated_at` = :now2
              WHERE `id` = :id',
            ['now' => $now, 'ip' => $ipAddress, 'now2' => $now, 'id' => $sessionId],
        );
    }

    /**
     * Number of currently usable sessions (not revoked, not expired) for a user.
     */
    public function countActiveForUser(int $userId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM `device_sessions`
              WHERE `user_id` = :uid AND `revoked_at` IS NULL AND `expires_at` > :now',
            ['uid' => $userId, 'now' => date('Y-m-d H:i:s')],
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Has this user ever authenticated from a session carrying this exact
     * fingerprint? Includes revoked / expired sessions — "have we seen this
     * device before".
     */
    public function userHasSeenFingerprint(int $userId, string $fingerprint): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `device_sessions`
              WHERE `user_id` = :uid AND `device_fingerprint` = :fp LIMIT 1',
            ['uid' => $userId, 'fp' => $fingerprint],
        ) !== null;
    }

    /**
     * Does the user have any prior session at all (used to distinguish a first
     * ever login from a genuinely new device)?
     */
    public function userHasAnySession(int $userId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `device_sessions` WHERE `user_id` = :uid LIMIT 1',
            ['uid' => $userId],
        ) !== null;
    }

    /**
     * Did the user have any session created before this one?
     */
    public function userHasSessionBefore(int $userId, int $sessionId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `device_sessions` WHERE `user_id` = :uid AND `id` < :sid LIMIT 1',
            ['uid' => $userId, 'sid' => $sessionId],
        ) !== null;
    }

    /**
     * Has this fingerprint appeared on an earlier session for this user?
     */
    public function userHasEarlierSessionWithFingerprint(int $userId, string $fingerprint, int $sessionId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `device_sessions`
              WHERE `user_id` = :uid AND `device_fingerprint` = :fp AND `id` < :sid LIMIT 1',
            ['uid' => $userId, 'fp' => $fingerprint, 'sid' => $sessionId],
        ) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRow(int $sessionId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `device_sessions` WHERE `id` = :id LIMIT 1',
            ['id' => $sessionId],
        );
    }
}
