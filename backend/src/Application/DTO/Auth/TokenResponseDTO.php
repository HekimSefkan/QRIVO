<?php

declare(strict_types=1);

namespace QRIVO\Application\DTO\Auth;

use QRIVO\Application\DTO\BaseDTO;

/**
 * Token response DTO.
 *
 * Carries the authentication tokens to send to the client.
 *
 * Security:
 * - access_token and refresh_token contain the RAW (plaintext) tokens.
 * - These are only ever sent to the client ONCE, immediately after generation.
 * - The database stores ONLY the SHA-256 hashes — never the raw tokens.
 * - Do NOT log this DTO or its contents.
 */
final class TokenResponseDTO extends BaseDTO
{
    public function __construct(
        /** Raw access token — send to client once; never store or log */
        public readonly string $accessToken,
        /** Raw refresh token — send to client once; never store or log */
        public readonly string $refreshToken,
        public readonly string $expiresAt,
        public readonly string $tokenType,
        /** @var array<string, mixed> Safe user subset (no password_hash) */
        public readonly array  $user,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token_type'    => $this->tokenType,
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at'    => $this->expiresAt,
            'user'          => $this->user,
        ];
    }
}
