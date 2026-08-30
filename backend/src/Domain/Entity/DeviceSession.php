<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity;

/**
 * DeviceSession entity.
 *
 * Represents an authenticated session (device + tokens).
 *
 * Security:
 * - access_token_hash and refresh_token_hash are SHA-256 hashes of the raw tokens.
 * - Raw tokens are NEVER stored in the database or in this entity.
 * - revoked_at non-null = explicitly revoked (logout); these sessions MUST be rejected.
 */
final class DeviceSession
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $uuid,
        public readonly int     $userId,
        public readonly string  $expiresAt,
        public readonly ?string $revokedAt,
        public readonly ?string $lastActiveAt,
        public readonly string  $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id:           (int) $row['id'],
            uuid:               $row['uuid'],
            userId:       (int) $row['user_id'],
            expiresAt:          $row['expires_at'],
            revokedAt:          $row['revoked_at'] ?? null,
            lastActiveAt:       $row['last_active_at'] ?? null,
            createdAt:          $row['created_at'],
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    public function isValid(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }
}
