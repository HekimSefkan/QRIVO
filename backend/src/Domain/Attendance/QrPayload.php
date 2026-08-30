<?php

declare(strict_types=1);

namespace QRIVO\Domain\Attendance;

/**
 * A dynamic-QR payload — exactly the four fields required by
 * ATTENDANCE_ALGORITHM.md §3:
 *
 *   session_id  — the attendance session UUID (safe external identifier; an
 *                 internal numeric id is never placed in a QR)
 *   timestamp   — unix seconds when the QR was generated (drives expiry)
 *   nonce       — 16 random bytes, hex; unique per generation
 *   signature   — HMAC-SHA256(signed message, session_secret), hex
 *
 * No other data goes in a QR (SECURITY_RULES.md §5 — "Minimal payload only").
 *
 * Wire format (the string encoded into the QR image):
 *
 *   qrivo.v1.<session_uuid>.<timestamp>.<nonce>.<signature>
 *
 * The signature covers everything up to and including the nonce:
 *
 *   qrivo.v1.<session_uuid>.<timestamp>.<nonce>
 */
final class QrPayload
{
    public const VERSION = 'v1';
    private const PREFIX  = 'qrivo';

    public function __construct(
        public readonly string $sessionUuid,
        public readonly int $timestamp,
        public readonly string $nonce,
        public readonly string $signature,
        public readonly string $version = self::VERSION,
    ) {}

    /** The message the HMAC signature is computed over. */
    public function signedMessage(): string
    {
        return implode('.', [self::PREFIX, $this->version, $this->sessionUuid, (string) $this->timestamp, $this->nonce]);
    }

    /** The full QR string (signed message + signature). */
    public function encode(): string
    {
        return $this->signedMessage() . '.' . $this->signature;
    }

    /**
     * Parse a scanned QR string. Returns null when the shape is not a QRIVO QR
     * payload (no signature verification here — that needs the session secret).
     */
    public static function decode(string $qr): ?self
    {
        $parts = explode('.', trim($qr));
        if (count($parts) !== 6) {
            return null;
        }

        [$prefix, $version, $sessionUuid, $timestamp, $nonce, $signature] = $parts;

        if ($prefix !== self::PREFIX
            || $version === ''
            || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $sessionUuid)
            || !ctype_digit($timestamp)
            || !preg_match('/^[0-9a-f]{32}$/', $nonce)
            || !preg_match('/^[0-9a-f]{64}$/', $signature)
        ) {
            return null;
        }

        return new self($sessionUuid, (int) $timestamp, $nonce, $signature, $version);
    }

    /**
     * Structured representation for API responses — the spec's four fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version'    => $this->version,
            'session_id' => $this->sessionUuid,
            'timestamp'  => $this->timestamp,
            'nonce'      => $this->nonce,
            'signature'  => $this->signature,
        ];
    }
}
