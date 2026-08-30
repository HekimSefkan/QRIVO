<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * LoginAttempt repository.
 *
 * Tracks all authentication attempts for rate limiting and security monitoring.
 * Table: `login_attempts`
 */
final class LoginAttemptRepository extends BaseRepository
{
    /**
     * Count recent failed attempts from a given IP within a time window.
     */
    public function countRecentFailuresByIp(string $ip, int $windowSeconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM `login_attempts`
              WHERE `ip_address` = :ip
                AND `success` = 0
                AND `created_at` >= :since',
            ['ip' => $ip, 'since' => $since]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Count recent failed attempts for a given email within a time window.
     */
    public function countRecentFailuresByEmail(string $email, int $windowSeconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM `login_attempts`
              WHERE `email_attempted` = :email
                AND `success` = 0
                AND `created_at` >= :since',
            ['email' => $email, 'since' => $since]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Record a login attempt.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $this->insert('login_attempts', $data);
    }
}
