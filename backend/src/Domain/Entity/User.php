<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity;

/**
 * User entity.
 *
 * Immutable value object representing a QRIVO system user.
 * Security: password_hash is NEVER exposed via toArray() or any public method.
 *
 * SECURITY_RULES.md §3: passwords must never be stored plaintext and never logged.
 */
final class User
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $uuid,
        public readonly string  $email,
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly bool    $isActive,
        public readonly bool    $isApproved,
        public readonly ?string $deletedAt,
        public readonly string  $createdAt,
        /** @var string[] $roles Role names assigned to this user */
        public readonly array   $roles = [],
    ) {}

    /**
     * Create a User from a database row.
     *
     * @param array<string, mixed> $row
     * @param string[]             $roles
     */
    public static function fromArray(array $row, array $roles = []): self
    {
        return new self(
            id:         (int)    $row['id'],
            uuid:                $row['uuid'],
            email:               $row['email'],
            firstName:           $row['first_name'],
            lastName:            $row['last_name'],
            isActive:   (bool)   $row['is_active'],
            isApproved: (bool)   $row['is_approved'],
            deletedAt:           $row['deleted_at'] ?? null,
            createdAt:           $row['created_at'],
            roles:               $roles,
        );
    }

    /**
     * Safe public representation — NEVER includes password_hash.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'uuid'       => $this->uuid,
            'email'      => $this->email,
            'first_name' => $this->firstName,
            'last_name'  => $this->lastName,
            'roles'      => $this->roles,
        ];
    }

    public function fullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }
}
