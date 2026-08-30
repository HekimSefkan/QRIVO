<?php

declare(strict_types=1);

namespace QRIVO\Application\Policy;

use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Domain\Contract\PolicyInterface;
use QRIVO\Domain\Enum\UserRole;

/**
 * Resource-ownership policy (IDOR / BOLA defence).
 *
 * Grants access to a resource only when the actor owns it — i.e. the resource's
 * owning user id matches the authenticated actor's user id. A configurable set of
 * roles (default: none) may bypass ownership; callers pass ADMIN/SUPER_ADMIN
 * where the specification allows staff to view a student's data.
 *
 * This policy is deliberately generic: it works for profiles, attendance
 * history, self reports, device sessions — anything with an owning user.
 *
 * SECURITY_RULES.md §4: "Student cannot access other students' data."
 */
final class SelfOwnedResourcePolicy implements PolicyInterface
{
    /**
     * @param array<UserRole|string> $bypassRoles
     */
    public function __construct(
        private readonly AuthorizationService $authz,
        private readonly array $bypassRoles = [],
    ) {}

    /**
     * @param array<string, mixed>      $actor
     * @param array<string, mixed>|null $resource expects `owner_user_id` (or `user_id`)
     */
    public function authorize(array $actor, string $ability, ?array $resource = null): bool
    {
        if ($resource === null) {
            return false;
        }

        $ownerId = $resource['owner_user_id'] ?? $resource['user_id'] ?? null;
        $ownerId = is_numeric($ownerId) ? (int) $ownerId : null;

        if ($this->bypassRoles !== [] && $this->authz->hasAnyRole($actor, $this->bypassRoles)) {
            return true;
        }

        return $this->authz->ownsResource($actor, $ownerId);
    }
}
