<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Outcome of validating a scanned dynamic-QR payload
 * (ATTENDANCE_ALGORITHM.md §3, SECURITY_RULES.md §5).
 */
enum QrValidationReason: string
{
    case VALID              = 'VALID';
    case MALFORMED          = 'MALFORMED';           // not a QRIVO QR payload / wrong shape
    case SESSION_NOT_FOUND  = 'SESSION_NOT_FOUND';
    case SESSION_NOT_ACTIVE = 'SESSION_NOT_ACTIVE';  // CLOSED / CANCELLED
    case WRONG_SESSION      = 'WRONG_SESSION';       // QR is for a different session than expected
    case EXPIRED            = 'EXPIRED';             // older than the QR TTL
    case BAD_SIGNATURE      = 'BAD_SIGNATURE';       // HMAC-SHA256 mismatch (tampered / forged)
    case REPLAYED           = 'REPLAYED';            // this QR nonce was already consumed

    public function isValid(): bool
    {
        return $this === self::VALID;
    }

    /** The security_events event_type this outcome maps to (null when VALID). */
    public function securityEventType(): ?SecurityEventType
    {
        return match ($this) {
            self::VALID    => null,
            self::EXPIRED  => SecurityEventType::QR_EXPIRED,
            self::REPLAYED => SecurityEventType::QR_REPLAY,
            default        => SecurityEventType::QR_INVALID,
        };
    }
}
