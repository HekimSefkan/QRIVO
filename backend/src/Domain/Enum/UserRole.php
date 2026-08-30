<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * User roles as defined in the project specification.
 *
 * Architecture (ARCHITECTURE_RULES.md §6):
 * - Four roles: SUPER_ADMIN, ADMIN, TEACHER, STUDENT
 * - Role alone is NOT sufficient for authorization
 * - Resource-level and relationship-based checks are also required
 */
enum UserRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ADMIN       = 'ADMIN';
    case TEACHER     = 'TEACHER';
    case STUDENT     = 'STUDENT';

    /**
     * Check if this role has at least as much privilege as the given role.
     * Order: SUPER_ADMIN > ADMIN > TEACHER > STUDENT
     */
    public function isAtLeast(self $role): bool
    {
        $hierarchy = [
            self::SUPER_ADMIN->value => 4,
            self::ADMIN->value       => 3,
            self::TEACHER->value     => 2,
            self::STUDENT->value     => 1,
        ];

        return $hierarchy[$this->value] >= $hierarchy[$role->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Administrator',
            self::ADMIN       => 'Administrator',
            self::TEACHER     => 'Teacher',
            self::STUDENT     => 'Student',
        };
    }
}
