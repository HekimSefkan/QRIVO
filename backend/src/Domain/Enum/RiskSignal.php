<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * The canonical risk-signal catalogue.
 *
 * This is the EXACT set of signals defined by the specification —
 * PROJECT_SPECIFICATION.md §6.14 and ATTENDANCE_ALGORITHM.md §9. No signal may
 * be added here that is not in the spec, and none of the spec's signals is
 * omitted.
 *
 *   §6.14 / §9 signal            → enum case
 *   ─────────────────────────────────────────────────────
 *   Expired QR                   → EXPIRED_QR
 *   Replay attempt               → REPLAY_ATTEMPT
 *   Invalid challenge            → INVALID_CHALLENGE
 *   Excessive retry              → EXCESSIVE_RETRY
 *   Duplicate attendance         → DUPLICATE_ATTENDANCE
 *   New device                   → NEW_DEVICE
 *   Multiple device activity     → MULTIPLE_DEVICE_ACTIVITY
 *   Suspicious IP                → SUSPICIOUS_IP
 *   Location mismatch            → LOCATION_MISMATCH
 *   Unauthorized relationship    → UNAUTHORIZED_RELATIONSHIP
 *
 * Per-signal weights and the level thresholds are configuration, never
 * hard-coded (spec §6.14): resolved by {@see \QRIVO\Domain\Risk\RiskPolicy}
 * from `system_settings` → `config/risk.php` → the defaults below.
 */
enum RiskSignal: string
{
    case EXPIRED_QR                = 'EXPIRED_QR';
    case REPLAY_ATTEMPT            = 'REPLAY_ATTEMPT';
    case INVALID_CHALLENGE         = 'INVALID_CHALLENGE';
    case EXCESSIVE_RETRY           = 'EXCESSIVE_RETRY';
    case DUPLICATE_ATTENDANCE      = 'DUPLICATE_ATTENDANCE';
    case NEW_DEVICE                = 'NEW_DEVICE';
    case MULTIPLE_DEVICE_ACTIVITY  = 'MULTIPLE_DEVICE_ACTIVITY';
    case SUSPICIOUS_IP             = 'SUSPICIOUS_IP';
    case LOCATION_MISMATCH         = 'LOCATION_MISMATCH';
    case UNAUTHORIZED_RELATIONSHIP = 'UNAUTHORIZED_RELATIONSHIP';

    /** Config / `system_settings` key for this signal's weight. */
    public function weightKey(): string
    {
        return 'risk.weight.' . strtolower($this->value);
    }

    /**
     * Default weight when neither `system_settings` nor `config/risk.php`
     * overrides it. Ordering reflects the spec's own severity language —
     * an unauthorized relationship is disqualifying; a replay or a duplicate is
     * serious; an expired QR or a new device on its own is minor.
     */
    public function defaultWeight(): int
    {
        return match ($this) {
            self::UNAUTHORIZED_RELATIONSHIP => 100,
            self::REPLAY_ATTEMPT            => 60,
            self::DUPLICATE_ATTENDANCE      => 50,
            self::MULTIPLE_DEVICE_ACTIVITY  => 40,
            self::LOCATION_MISMATCH         => 40,
            self::EXCESSIVE_RETRY           => 30,
            self::INVALID_CHALLENGE         => 25,
            self::EXPIRED_QR                => 15,
            self::NEW_DEVICE                => 15,
            self::SUSPICIOUS_IP             => 15,
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
