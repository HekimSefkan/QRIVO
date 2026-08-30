<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * User repository.
 *
 * Provides data access for the `users` and `user_roles` tables.
 * Implements ONLY query methods — no business logic.
 *
 * Security:
 * - findByEmail() returns the full row INCLUDING password_hash.
 *   The caller (AuthService) verifies the hash then discards the raw value.
 * - Never log or expose password_hash outside the verification step.
 */
final class UserRepository extends BaseRepository
{
    /**
     * Find a user by email address (for login).
     * Returns null if not found OR if soft-deleted.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `users` WHERE `email` = :email AND `deleted_at` IS NULL LIMIT 1',
            ['email' => $email]
        );
    }

    /**
     * Find a user by UUID (safe external identifier).
     *
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `users` WHERE `uuid` = :uuid AND `deleted_at` IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * Find a user by internal ID.
     *
     * @return array<string, mixed>|null
     */
    public function findUserById(int|string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Get role names assigned to a user.
     *
     * @return string[]
     */
    public function getRoleNames(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.`name`
               FROM `roles` r
               JOIN `user_roles` ur ON ur.`role_id` = r.`id`
              WHERE ur.`user_id` = :user_id',
            ['user_id' => $userId]
        );

        return array_column($rows, 'name');
    }
}
