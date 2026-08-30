<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Repository\AbstractCrudRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;

/**
 * Shared business logic for the academic-structure resources.
 *
 * Every concrete service is a thin declaration of:
 *   - entity name (audit target + messages)
 *   - create/update validation rules
 *   - input → column mapping
 *   - row → API resource mapping
 *   - cross-entity consistency checks (parent must exist and be live; uniqueness)
 *   - which child rows block deletion
 *
 * Responsibilities kept here: validation orchestration, 404/409 semantics,
 * pagination, and audit logging of every write.
 *
 * Authorization is enforced one layer up (the controller calls
 * AuthorizationService::requirePermission before delegating here). The actor is
 * passed through only for the audit trail.
 */
abstract class AbstractAcademicService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        protected readonly AbstractCrudRepository $repo,
        protected readonly SecurityLogService $audit,
        protected readonly ReferenceRepository $reference,
    ) {
        parent::__construct($logger);
    }

    /**
     * Assert that a referenced parent row exists and is live; otherwise 422.
     * This is the application-layer half of relationship enforcement (the FK is
     * the database half, and it also blocks references to soft-deleted parents).
     */
    protected function requireReference(string $field, string $table, mixed $id, bool $usesSoftDeletes = true): void
    {
        if ($id === null) {
            return;
        }
        if (!is_numeric($id) || !$this->reference->activeExists($table, (int) $id, $usesSoftDeletes)) {
            $this->fail($field, "The {$field} field does not reference an existing, active record.");
        }
    }

    // ─── Subclass contract ─────────────────────────────────────────────────────

    /** Singular snake identifier, e.g. 'school', 'academic_term'. */
    abstract protected function entityName(): string;

    /** @return array<string, string> Validator rules for create */
    abstract protected function createRules(): array;

    /** @return array<string, string> Validator rules for update (defaults to create rules made optional) */
    protected function updateRules(): array
    {
        return self::optional($this->createRules());
    }

    /**
     * Map validated request input to persistable columns.
     * On update, return only the keys the caller actually supplied.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    abstract protected function fromInput(array $input, bool $isCreate): array;

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function toResource(array $row): array
    {
        return $row;
    }

    /**
     * Exact-match list filters: query-param name => column name.
     *
     * @return array<string, string>
     */
    protected function filterMap(): array
    {
        return [];
    }

    /**
     * Enforce cross-entity consistency: referenced parents exist and are live,
     * and application-level uniqueness. Throw ValidationException / ConflictException.
     *
     * @param array<string, mixed>      $data     the would-be persisted state (merged, on update)
     * @param array<string, mixed>|null $existing current row (null on create)
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
    }

    /**
     * Child relations that block deletion: [ [childTable, fkColumn, childUsesSoftDeletes], ... ].
     *
     * @return array<int, array{0:string,1:string,2:bool}>
     */
    protected function blockingChildren(): array
    {
        return [];
    }

    protected function usesSoftDelete(): bool
    {
        return true;
    }

    /** Hook run inside create(), after the row exists. */
    protected function afterCreate(int $id, array $data, array $actor): void
    {
    }

    /**
     * Hook run inside delete(), after the row is removed.
     *
     * @param array<string, mixed> $existing the row as it was before deletion
     * @param array<string, mixed> $actor
     */
    protected function afterDelete(int $id, array $existing, array $actor): void
    {
    }

    // ─── Public API ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $actor
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $query, array $actor): array
    {
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($query['per_page'] ?? 25)));
        $search  = isset($query['search']) && is_string($query['search']) && $query['search'] !== ''
            ? $query['search']
            : null;

        $filters = [];
        foreach ($this->filterMap() as $param => $column) {
            if (array_key_exists($param, $query) && $query[$param] !== '' && $query[$param] !== null) {
                $filters[$column] = is_numeric($query[$param]) ? (int) $query[$param] : (string) $query[$param];
            }
        }

        $result = $this->repo->paginate($filters, $search, $page, $perPage);
        $total  = $result['total'];

        return [
            'data' => array_map(fn (array $row): array => $this->toResource($row), $result['data']),
            'meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function get(int $id, array $actor): array
    {
        $row = $this->repo->findActive($id);
        if ($row === null) {
            throw new NotFoundException(ucfirst(str_replace('_', ' ', $this->entityName())) . ' not found.');
        }

        return $this->toResource($row);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function create(array $input, array $actor): array
    {
        (new Validator())->validate($input, $this->createRules());

        $data = $this->fromInput($input, true);
        $this->assertConsistent($data, null);

        $id = $this->guardUnique(fn (): int => $this->repo->create($data));
        $this->afterCreate($id, $data, $actor);

        $resource = $this->get($id, $actor);

        $this->audit->recordAuditLog(
            strtoupper($this->entityName()) . '_CREATED',
            $this->actorId($actor),
            $this->entityName(),
            $id,
            null,
            $resource,
            null,
            $this->actorIp($actor),
        );

        return $resource;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, array $actor): array
    {
        $existing = $this->repo->findActive($id);
        if ($existing === null) {
            throw new NotFoundException(ucfirst(str_replace('_', ' ', $this->entityName())) . ' not found.');
        }

        (new Validator())->validate($input, $this->updateRules());

        $data = $this->fromInput($input, false);
        if ($data === []) {
            throw new ValidationException('Validation failed.', ['_' => ['No updatable fields were provided.']]);
        }

        $this->assertConsistent(array_merge($existing, $data), $existing);

        $this->guardUnique(function () use ($id, $data): int {
            $this->repo->updateActive($id, $data);
            return $id;
        });

        $resource = $this->get($id, $actor);

        $this->audit->recordAuditLog(
            strtoupper($this->entityName()) . '_UPDATED',
            $this->actorId($actor),
            $this->entityName(),
            $id,
            $this->toResource($existing),
            $resource,
            null,
            $this->actorIp($actor),
        );

        return $resource;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function delete(int $id, array $actor): void
    {
        $existing = $this->repo->findActive($id);
        if ($existing === null) {
            throw new NotFoundException(ucfirst(str_replace('_', ' ', $this->entityName())) . ' not found.');
        }

        foreach ($this->blockingChildren() as [$childTable, $fk, $childSoft]) {
            if ($this->repo->countChildren($childTable, $fk, $id, $childSoft) > 0) {
                throw new ConflictException(
                    'Cannot delete this ' . str_replace('_', ' ', $this->entityName())
                    . ' while it still has related ' . str_replace('_', ' ', $childTable) . '.'
                );
            }
        }

        if ($this->usesSoftDelete()) {
            $this->repo->softDeleteActive($id);
        } else {
            $this->repo->hardDelete($id);
        }

        $this->afterDelete($id, $existing, $actor);

        $this->audit->recordAuditLog(
            strtoupper($this->entityName()) . '_DELETED',
            $this->actorId($actor),
            $this->entityName(),
            $id,
            $this->toResource($existing),
            null,
            null,
            $this->actorIp($actor),
        );
    }

    // ─── Helpers for subclasses ────────────────────────────────────────────────

    /**
     * Remove `required` from every rule string (for PATCH-style updates).
     *
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    protected static function optional(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $ruleString) {
            $kept = array_values(array_filter(
                explode('|', $ruleString),
                static fn (string $r): bool => $r !== 'required',
            ));
            if ($kept !== []) {
                $out[$field] = implode('|', $kept);
            }
        }

        return $out;
    }

    protected function fail(string $field, string $message): never
    {
        throw new ValidationException('Validation failed.', [$field => [$message]]);
    }

    /**
     * Run a write and translate a database unique/constraint violation (SQLSTATE
     * 23000) into a 409 — the DB unique keys are the real guarantee behind the
     * friendly app-level checks in assertConsistent().
     *
     * @param callable(): int $write
     */
    protected function guardUnique(callable $write): int
    {
        try {
            return $write();
        } catch (\PDOException $e) {
            if (($e->getCode() === '23000') || str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                throw new ConflictException('A ' . str_replace('_', ' ', $this->entityName()) . ' with the same unique identifier already exists.');
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    protected function actorId(array $actor): ?int
    {
        return isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null;
    }

    /**
     * @param array<string, mixed> $actor
     */
    protected function actorIp(array $actor): ?string
    {
        return isset($actor['ip_address']) && is_string($actor['ip_address']) ? $actor['ip_address'] : null;
    }

    protected function toBool(mixed $value): int
    {
        return in_array($value, [true, 1, '1', 'true'], true) ? 1 : 0;
    }
}
