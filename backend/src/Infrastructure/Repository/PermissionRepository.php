<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Permission repository.
 *
 * Read access for the RBAC tables: `permissions`, `role_permissions`, `roles`,
 * `user_roles`. No business logic — the authorization decision is made in
 * {@see \QRIVO\Application\Service\AuthorizationService}.
 *
 * Security:
 * - Permissions are resolved from the database on the server. A client-supplied
 *   role or permission list is never trusted.
 */
final class PermissionRepository extends BaseRepository
{
    /**
     * All permission names granted to a user, resolved through their roles.
     *
     * @return string[]
     */
    public function getPermissionNamesForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT p.`name`
               FROM `permissions` p
               JOIN `role_permissions` rp ON rp.`permission_id` = p.`id`
               JOIN `user_roles` ur       ON ur.`role_id` = rp.`role_id`
              WHERE ur.`user_id` = :user_id',
            ['user_id' => $userId]
        );

        return array_column($rows, 'name');
    }

    /**
     * All permission names granted to a set of role names.
     *
     * @param string[] $roleNames
     * @return string[]
     */
    public function getPermissionNamesForRoleNames(array $roleNames): array
    {
        $roleNames = array_values(array_unique(array_filter($roleNames, 'is_string')));
        if ($roleNames === []) {
            return [];
        }

        $placeholders = [];
        $bindings     = [];
        foreach ($roleNames as $i => $name) {
            $key            = "r{$i}";
            $placeholders[] = ":{$key}";
            $bindings[$key] = $name;
        }

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT p.`name`
               FROM `permissions` p
               JOIN `role_permissions` rp ON rp.`permission_id` = p.`id`
               JOIN `roles` r             ON r.`id` = rp.`role_id`
              WHERE r.`name` IN (' . implode(', ', $placeholders) . ')',
            $bindings
        );

        return array_column($rows, 'name');
    }

    /**
     * Role names currently assigned to a user (authoritative — from the database).
     *
     * @return string[]
     */
    public function getRoleNamesForUser(int $userId): array
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
