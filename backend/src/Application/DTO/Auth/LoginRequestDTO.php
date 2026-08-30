<?php

declare(strict_types=1);

namespace QRIVO\Application\DTO\Auth;

use QRIVO\Application\DTO\BaseDTO;

/**
 * Login request DTO.
 *
 * Carries validated login credentials and request metadata.
 * The raw password is held temporarily only for verification — it is NEVER stored.
 *
 * Security (SECURITY_RULES.md §3):
 * - password must be verified then discarded immediately
 * - never log or persist the raw password
 */
final class LoginRequestDTO extends BaseDTO
{
    public function __construct(
        public readonly string $email,
        /** Raw password — verify and discard immediately, never store or log */
        public readonly string $password,
        public readonly string $ipAddress,
        public readonly string $userAgent,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        // Security: password is intentionally excluded from serialization
        return [
            'email'      => $this->email,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];
    }
}
