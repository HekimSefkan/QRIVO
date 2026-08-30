<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository;

/**
 * Shared CRUD implementation for the academic-structure repositories.
 *
 * Concrete repositories declare their table, writable columns, whether the table
 * uses soft delete, and which columns are searchable. Everything else — paged
 * listing with filters, soft-delete-aware lookups, uniqueness checks, child-row
 * counting for delete guards — lives here.
 *
 * Security:
 * - Table and column identifiers are code-controlled constants (never user
 *   input). All values are bound as parameters.
 * - Soft-deleted rows are excluded from every read unless explicitly requested.
 */
abstract class AbstractCrudRepository extends BaseRepository
{
    /** Table name (code constant). */
    abstract protected function table(): string;

    /**
     * Columns that may be written by create()/update() (code constants).
     *
     * @return string[]
     */
    abstract protected function writableColumns(): array;

    protected function usesSoftDeletes(): bool
    {
        return true;
    }

    /** Column stamped with the creation time on create(), or null if the table has none. */
    protected function createdAtColumn(): ?string
    {
        return 'created_at';
    }

    /** Column stamped on every write, or null if the table has none. */
    protected function updatedAtColumn(): ?string
    {
        return 'updated_at';
    }

    /**
     * Columns matched by the `search` filter (LIKE). Empty = search disabled.
     *
     * @return string[]
     */
    protected function searchableColumns(): array
    {
        return [];
    }

    // ─── Reads ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table()}` WHERE `id` = :id" . $this->notDeleted() . ' LIMIT 1',
            ['id' => $id],
        );
    }

    /**
     * Paged listing.
     *
     * @param array<string, scalar> $equalsFilters column => value (exact match; columns are code constants)
     * @param string|null           $search        LIKE term applied to searchableColumns()
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(array $equalsFilters, ?string $search, int $page, int $perPage): array
    {
        [$whereSql, $bindings] = $this->buildWhere($equalsFilters, $search);

        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `{$this->table()}`{$whereSql}",
            $bindings,
        )['c'] ?? 0);

        // page/perPage are cast to safe integers here — they are interpolated,
        // not bound (LIMIT/OFFSET cannot be bound portably under emulation-off).
        $perPage = max(1, min(200, (int) $perPage));
        $page    = max(1, (int) $page);
        $offset  = ($page - 1) * $perPage;
        $rows   = $this->db->fetchAll(
            "SELECT * FROM `{$this->table()}`{$whereSql} ORDER BY `id` ASC LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return ['data' => $rows, 'total' => $total];
    }

    // ─── Writes ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $data = $this->onlyWritable($data);
        $now  = date('Y-m-d H:i:s');
        if ($c = $this->createdAtColumn()) {
            $data[$c] = $now;
        }
        if ($u = $this->updatedAtColumn()) {
            $data[$u] = $now;
        }

        return (int) $this->insert($this->table(), $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateActive(int $id, array $data): void
    {
        $data = $this->onlyWritable($data);
        if ($data === []) {
            return;
        }
        if ($u = $this->updatedAtColumn()) {
            $data[$u] = date('Y-m-d H:i:s');
        }

        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($data)));
        $this->db->execute(
            "UPDATE `{$this->table()}` SET {$sets} WHERE `id` = :__id" . $this->notDeleted(),
            array_merge($data, ['__id' => $id]),
        );
    }

    /** @return bool true when a row was soft-deleted */
    public function softDeleteActive(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE `{$this->table()}` SET `deleted_at` = :now WHERE `id` = :id AND `deleted_at` IS NULL",
            ['now' => date('Y-m-d H:i:s'), 'id' => $id],
        );

        return $affected > 0;
    }

    /** @return bool true when a row was deleted */
    public function hardDelete(int $id): bool
    {
        return $this->db->execute(
            "DELETE FROM `{$this->table()}` WHERE `id` = :id",
            ['id' => $id],
        ) > 0;
    }

    // ─── Constraint helpers ────────────────────────────────────────────────────

    /**
     * Is there another active row where all $conditions match, excluding $exceptId?
     * Used for application-level uniqueness messages (the DB unique key is the
     * real guarantee).
     *
     * @param array<string, scalar> $conditions column => value (columns are code constants)
     */
    public function existsActiveMatching(array $conditions, ?int $exceptId = null): bool
    {
        $clauses  = [];
        $bindings = [];
        foreach ($conditions as $col => $val) {
            $clauses[]      = "`{$col}` = :{$col}";
            $bindings[$col] = $val;
        }
        if ($exceptId !== null) {
            $clauses[]        = '`id` <> :__except';
            $bindings['__except'] = $exceptId;
        }

        $sql = "SELECT 1 FROM `{$this->table()}` WHERE " . implode(' AND ', $clauses)
            . $this->notDeleted() . ' LIMIT 1';

        return $this->db->fetchOne($sql, $bindings) !== null;
    }

    /**
     * Count active child rows referencing $id via $foreignKey in $childTable.
     * $childTable / $foreignKey are code constants supplied by the caller.
     */
    public function countChildren(string $childTable, string $foreignKey, int $id, bool $childUsesSoftDeletes = true): int
    {
        $sql = "SELECT COUNT(*) AS c FROM `{$childTable}` WHERE `{$foreignKey}` = :id";
        if ($childUsesSoftDeletes) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        return (int) ($this->db->fetchOne($sql, ['id' => $id])['c'] ?? 0);
    }

    // ─── Internals ─────────────────────────────────────────────────────────────

    private function notDeleted(): string
    {
        return $this->usesSoftDeletes() ? ' AND `deleted_at` IS NULL' : '';
    }

    /**
     * @param array<string, scalar> $equalsFilters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $equalsFilters, ?string $search): array
    {
        $clauses  = [];
        $bindings = [];

        if ($this->usesSoftDeletes()) {
            $clauses[] = '`deleted_at` IS NULL';
        }

        foreach ($equalsFilters as $col => $val) {
            $clauses[]      = "`{$col}` = :f_{$col}";
            $bindings["f_{$col}"] = $val;
        }

        $searchable = $this->searchableColumns();
        if ($search !== null && $search !== '' && $searchable !== []) {
            $ors = [];
            foreach ($searchable as $i => $col) {
                $ors[]            = "`{$col}` LIKE :s{$i}";
                $bindings["s{$i}"] = '%' . $search . '%';
            }
            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        $sql = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$sql, $bindings];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function onlyWritable(array $data): array
    {
        return array_intersect_key($data, array_flip($this->writableColumns()));
    }
}
