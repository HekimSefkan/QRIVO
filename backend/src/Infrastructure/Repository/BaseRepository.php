<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

use QRIVO\Infrastructure\Database\Connection;

/**
 * Base repository providing shared database access for all concrete repositories.
 *
 * Responsibilities:
 * - Hold the database connection
 * - Provide common query helpers
 *
 * All concrete repositories extend this class and implement
 * their domain-specific queries.
 */
abstract class BaseRepository
{
    public function __construct(protected readonly Connection $db) {}

    /**
     * Check whether a record exists matching given column conditions.
     *
     * @param array<string, mixed> $conditions
     */
    protected function exists(string $table, array $conditions): bool
    {
        $where    = implode(' AND ', array_map(fn($col) => "`$col` = :$col", array_keys($conditions)));
        $sql      = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        $stmt     = $this->db->getPdo()->prepare($sql);
        $stmt->execute($conditions);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Find a single record by primary key.
     *
     * @return array<string, mixed>|null
     */
    protected function findById(string $table, int|string $id, string $pk = 'id'): ?array
    {
        $sql = "SELECT * FROM `{$table}` WHERE `{$pk}` = :id LIMIT 1";
        return $this->db->fetchOne($sql, ['id' => $id]);
    }

    /**
     * Insert a row and return the new ID.
     *
     * @param array<string, mixed> $data
     */
    protected function insert(string $table, array $data): string
    {
        $columns     = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
        $placeholders = implode(', ', array_map(fn($c) => ":$c", array_keys($data)));
        $sql         = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $this->db->execute($sql, $data);
        return $this->db->lastInsertId();
    }

    /**
     * Update a record by primary key.
     *
     * @param array<string, mixed> $data
     */
    protected function update(string $table, int|string $id, array $data, string $pk = 'id'): int
    {
        $sets = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
        $sql  = "UPDATE `{$table}` SET {$sets} WHERE `{$pk}` = :__pk";
        return $this->db->execute($sql, array_merge($data, ['__pk' => $id]));
    }

    /**
     * Soft-delete a record (sets deleted_at).
     */
    protected function softDelete(string $table, int|string $id, string $pk = 'id'): int
    {
        $sql = "UPDATE `{$table}` SET `deleted_at` = NOW() WHERE `{$pk}` = :id AND `deleted_at` IS NULL";
        return $this->db->execute($sql, ['id' => $id]);
    }
}
