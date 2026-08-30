<?php

declare(strict_types=1);

namespace QRIVO\Domain\Contract;

/**
 * Generic repository contract.
 *
 * Defines the standard CRUD operations all domain repositories must support.
 * Concrete repository implementations live in the Infrastructure layer.
 */
interface RepositoryInterface
{
    /**
     * Find a record by its primary key.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int|string $id): ?array;

    /**
     * Persist a new record and return its generated ID.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): string;

    /**
     * Update an existing record by primary key.
     * Returns the number of affected rows.
     *
     * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): int;

    /**
     * Delete (or soft-delete) a record by primary key.
     * Returns the number of affected rows.
     */
    public function delete(int|string $id): int;
}
