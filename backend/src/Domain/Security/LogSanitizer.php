<?php

declare(strict_types=1);

namespace QRIVO\Domain\Security;

/**
 * The single redaction pass applied to everything that becomes a persisted
 * security event, an audit-log value, or a line in the application log.
 *
 * SECURITY_RULES.md §9 / §10 / §11 and PROJECT_SPECIFICATION.md §6.15:
 * logs must NEVER contain passwords, raw authentication secrets (access /
 * refresh tokens, session secrets, challenge nonces), private keys, or
 * unnecessary sensitive data.
 *
 * The sweep is defensive and layered:
 *   1. keys whose name marks them sensitive           → value replaced
 *   2. string values that *look* like a secret        → value replaced
 *      (PEM private-key blocks, JWTs, long bare hex / base64 tokens)
 *   3. over-long strings                              → truncated
 *
 * It is intentionally conservative about false positives on structured log
 * payloads (known keys, short scalar values) while erring toward redaction for
 * anything that resembles credential material.
 */
final class LogSanitizer
{
    public const REDACTED = '[REDACTED]';

    /**
     * Substrings that mark a key as sensitive. Matched against the key with
     * every non-alphanumeric character stripped and lower-cased, so
     * `access_token`, `accessToken` and `access-token` all match `token`.
     */
    private const SENSITIVE_KEY_MARKERS = [
        'password', 'passwd', 'passphrase', 'pwd',
        'secret',
        'token',
        'apikey',
        'privatekey',
        'authorization',
        'credential',
        'bearer',
        'nonce',
        'signature',
        'fingerprint',
        'otp', 'totp', 'mfacode',
    ];

    private const MAX_DEPTH = 16;
    private const MAX_STRING_LENGTH = 2048;

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function sanitize(array $data): array
    {
        return $this->walk($data, 0);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function walk(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'max depth reached'];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }
            $out[$key] = $this->sanitizeValue($value, $depth);
        }

        return $out;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->walk($value, $depth + 1);
        }
        if (is_string($value)) {
            return self::sanitizeString($value);
        }

        // int / float / bool / null carry no secrets.
        return $value;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
        if ($normalized === '') {
            return false;
        }

        foreach (self::SENSITIVE_KEY_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function sanitizeString(string $value): string
    {
        $trimmed = trim($value);

        // PEM-encoded private keys.
        if (str_contains($value, '-----BEGIN') && stripos($value, 'PRIVATE KEY') !== false) {
            return self::REDACTED;
        }

        // JSON Web Tokens: header.payload.signature, all base64url.
        if (preg_match('/^eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}$/', $trimmed) === 1) {
            return self::REDACTED;
        }

        // A bare, separator-free hex or base64url blob long enough to be a token
        // or a hash of a secret. UUIDs (which contain '-') and free text (spaces,
        // slashes, dots) are deliberately excluded.
        if (preg_match('/^[A-Fa-f0-9]{40,}$/', $trimmed) === 1) {
            return self::REDACTED;
        }
        if (
            strlen($trimmed) >= 40
            && !str_contains($trimmed, '-')
            && preg_match('/^[A-Za-z0-9_]+={0,2}$/', $trimmed) === 1
        ) {
            return self::REDACTED;
        }

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            return substr($value, 0, self::MAX_STRING_LENGTH) . '…[truncated]';
        }

        return $value;
    }
}
