<?php

declare(strict_types=1);

namespace QRIVO\Tests\Support;

use QRIVO\Domain\Authorization\RolePermissionMap;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;

/**
 * Shared helper for authorization tests.
 *
 * Builds an in-memory SQLite schema that mirrors the production RBAC and
 * relationship tables (from database/docs/TABLES.md and migrations 001/002), and
 * seeds the canonical role -> permission map from {@see RolePermissionMap} so the
 * tests never drift from the migration.
 */
trait RbacSchemaTrait
{
    private \PDO $pdo;

    private function buildRbacDb(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL DEFAULT 'x',
                first_name TEXT NOT NULL DEFAULT 'Test',
                last_name TEXT NOT NULL DEFAULT 'User',
                is_active INTEGER NOT NULL DEFAULT 1,
                is_approved INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                updated_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                deleted_at TEXT
            );
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            );
            CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                PRIMARY KEY (user_id, role_id)
            );
            CREATE TABLE security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                severity TEXT NOT NULL,
                user_id INTEGER,
                attendance_session_id INTEGER,
                ip_address TEXT,
                user_agent TEXT,
                details TEXT,
                created_at TEXT NOT NULL
            );
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                actor_user_id INTEGER,
                target_entity TEXT NOT NULL,
                target_id INTEGER,
                old_value TEXT,
                new_value TEXT,
                reason TEXT,
                ip_address TEXT,
                created_at TEXT NOT NULL
            );
            CREATE TABLE teachers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                department_id INTEGER NOT NULL DEFAULT 1,
                employee_number TEXT NOT NULL DEFAULT '',
                deleted_at TEXT
            );
            CREATE TABLE students (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                program_id INTEGER NOT NULL DEFAULT 1,
                student_number TEXT NOT NULL DEFAULT '',
                deleted_at TEXT
            );
            CREATE TABLE teacher_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                teacher_id INTEGER NOT NULL,
                course_id INTEGER NOT NULL,
                academic_term_id INTEGER NOT NULL
            );
            CREATE TABLE teacher_class_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                teacher_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL,
                course_id INTEGER NOT NULL,
                academic_term_id INTEGER NOT NULL
            );
            CREATE TABLE student_class_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL,
                academic_term_id INTEGER NOT NULL
            );
            CREATE TABLE student_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                course_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL DEFAULT 1,
                academic_term_id INTEGER NOT NULL
            );
        SQL);

        // Seed roles + permissions + role_permissions from the canonical map.
        foreach (array_keys(RolePermissionMap::all()) as $roleName) {
            $pdo->prepare('INSERT INTO roles (name, display_name) VALUES (?, ?)')
                ->execute([$roleName, $roleName]);
        }

        $allPermissions = [];
        foreach (RolePermissionMap::all() as $perms) {
            foreach ($perms as $p) {
                $allPermissions[$p] = true;
            }
        }
        foreach (array_keys($allPermissions) as $permName) {
            $pdo->prepare('INSERT INTO permissions (name, display_name) VALUES (?, ?)')
                ->execute([$permName, $permName]);
        }

        foreach (RolePermissionMap::all() as $roleName => $perms) {
            foreach ($perms as $permName) {
                $pdo->prepare(
                    'INSERT INTO role_permissions (role_id, permission_id)
                     SELECT (SELECT id FROM roles WHERE name = ?), (SELECT id FROM permissions WHERE name = ?)'
                )->execute([$roleName, $permName]);
            }
        }

        return $pdo;
    }

    /**
     * Create a user with the given role names and return the user id.
     *
     * @param string[] $roleNames
     */
    private function createUser(string $email, array $roleNames): int
    {
        $this->pdo->prepare('INSERT INTO users (uuid, email) VALUES (?, ?)')
            ->execute([bin2hex(random_bytes(8)) . '-uuid', $email]);
        $userId = (int) $this->pdo->lastInsertId();

        foreach ($roleNames as $roleName) {
            $this->pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id, created_at)
                 VALUES (?, (SELECT id FROM roles WHERE name = ?), ?)'
            )->execute([$userId, $roleName, '2026-01-01 00:00:00']);
        }

        return $userId;
    }

    /**
     * Build an actor context array like AuthService::validateToken() returns.
     *
     * @param string[] $roleNames
     * @return array<string, mixed>
     */
    private function actorContext(int $userId, array $roleNames, string $email = 'a@x.test'): array
    {
        return [
            'user_id'    => $userId,
            'uuid'       => 'uuid-' . $userId,
            'email'      => $email,
            'first_name' => 'Test',
            'last_name'  => 'User',
            'roles'      => $roleNames,
            'session_id' => 1,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'PHPUnit',
        ];
    }

    private function buildConnection(): Connection
    {
        $connection = new Connection(new Config(QRIVO_ROOT));
        $reflection = new \ReflectionClass($connection);
        $prop       = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($connection, $this->pdo);

        return $connection;
    }

    private function securityEventCount(?string $eventType = null): int
    {
        if ($eventType === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) AS c FROM security_events')->fetch()['c'];
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM security_events WHERE event_type = ?');
        $stmt->execute([$eventType]);
        return (int) $stmt->fetch()['c'];
    }
}
