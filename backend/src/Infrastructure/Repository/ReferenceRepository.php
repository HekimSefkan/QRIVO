<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Generic existence checks used for application-level relationship enforcement.
 *
 * The database enforces referential integrity with `ON DELETE RESTRICT` foreign
 * keys (CONSTRAINTS.md FK-07..FK-19). This repository lets the service layer fail
 * fast with a friendly 422 *before* hitting the database, and — importantly —
 * also rejects references to rows that still exist physically but are
 * soft-deleted (which a raw FK cannot catch).
 *
 * Table names are code constants supplied by the caller; ids are bound.
 */
final class ReferenceRepository extends BaseRepository
{
    /**
     * Does an active (non-soft-deleted) row with this id exist in $table?
     */
    public function activeExists(string $table, int $id, bool $usesSoftDeletes = true): bool
    {
        $sql = "SELECT 1 FROM `{$table}` WHERE `id` = :id";
        if ($usesSoftDeletes) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', ['id' => $id]) !== null;
    }

    /**
     * A user that exists, is not soft-deleted, is active and approved — the only
     * kind that may be linked to a teacher/student profile.
     */
    public function userIsUsable(int $userId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `users`
              WHERE `id` = :id AND `deleted_at` IS NULL AND `is_active` = 1 AND `is_approved` = 1
              LIMIT 1',
            ['id' => $userId],
        ) !== null;
    }

    /** Is this user already linked to a row in $profileTable (teachers/students)? */
    public function userHasProfile(string $profileTable, int $userId, ?int $exceptId = null): bool
    {
        $sql      = "SELECT 1 FROM `{$profileTable}` WHERE `user_id` = :uid AND `deleted_at` IS NULL";
        $bindings = ['uid' => $userId];
        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :except';
            $bindings['except'] = $exceptId;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $bindings) !== null;
    }

    /**
     * Ensure a user holds a role (idempotent). Used when an admin creates a
     * teacher/student profile — the non-privileged TEACHER/STUDENT role is
     * attached so the account is coherent. See OQ-004.
     */
    public function ensureUserRole(int $userId, string $roleName): void
    {
        $this->db->execute(
            'INSERT INTO `user_roles` (`user_id`, `role_id`, `created_at`)
             SELECT :uid, r.`id`, :now FROM `roles` r
              WHERE r.`name` = :role
                AND NOT EXISTS (
                    SELECT 1 FROM `user_roles` ur
                     WHERE ur.`user_id` = :uid2 AND ur.`role_id` = r.`id`
                )',
            ['uid' => $userId, 'uid2' => $userId, 'role' => $roleName, 'now' => date('Y-m-d H:i:s')],
        );
    }
}
