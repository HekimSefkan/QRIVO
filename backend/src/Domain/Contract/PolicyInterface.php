<?php

declare(strict_types=1);

namespace QRIVO\Domain\Contract;

/**
 * Authorization policy contract.
 *
 * All resource-level authorization policies implement this interface.
 * Policies enforce: role-based + resource ownership + relationship-based rules.
 *
 * Security (SECURITY_RULES.md §4):
 * - Role alone is NOT sufficient. Resource ownership MUST be checked.
 * - Relationship-based access required for Teachers and Students.
 * - All policy decisions are server-side — never trusted from the client.
 */
interface PolicyInterface
{
    /**
     * Determine whether the given actor (user context) may perform
     * the named $ability on the given $resource.
     *
     * @param array<string, mixed>      $actor    The authenticated user (id, roles, etc.)
     * @param string                    $ability  The action name (e.g. 'view', 'update', 'delete')
     * @param array<string, mixed>|null $resource The target resource (may be null for collection-level)
     */
    public function authorize(array $actor, string $ability, ?array $resource = null): bool;
}
