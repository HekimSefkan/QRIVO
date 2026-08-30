<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Risk levels (PROJECT_SPECIFICATION.md §6.14, ATTENDANCE_ALGORITHM.md §9).
 */
enum RiskLevel: string
{
    case LOW     = 'LOW';
    case MEDIUM  = 'MEDIUM';
    case HIGH    = 'HIGH';
    case BLOCKED = 'BLOCKED';

    /**
     * Fixed level → outcome mapping from ATTENDANCE_ALGORITHM.md §9:
     *   LOW     → PRESENT
     *   MEDIUM  → PRESENT (the caller also records a SECURITY_EVENT)
     *   HIGH    → PENDING_REVIEW
     *   BLOCKED → BLOCKED (no attendance record)
     */
    public function toOutcome(): RiskOutcome
    {
        return match ($this) {
            self::LOW, self::MEDIUM => RiskOutcome::PRESENT,
            self::HIGH             => RiskOutcome::PENDING_REVIEW,
            self::BLOCKED          => RiskOutcome::BLOCKED,
        };
    }

    /** Does this level require a SECURITY_EVENT to be recorded? (§9) */
    public function requiresSecurityEvent(): bool
    {
        return $this === self::MEDIUM || $this === self::HIGH || $this === self::BLOCKED;
    }

    /**
     * The security event an elevated outcome must record (§9). NULL for LOW.
     * This is the single source of the level → event-type mapping.
     */
    public function securityEventType(): ?SecurityEventType
    {
        return match ($this) {
            self::LOW               => null,
            self::MEDIUM, self::HIGH => SecurityEventType::RISK_ESCALATION,
            self::BLOCKED           => SecurityEventType::BLOCKED_ATTENDANCE,
        };
    }

    /** Severity for {@see securityEventType()} — a valid `security_events.severity`. */
    public function securityEventSeverity(): string
    {
        return $this === self::BLOCKED ? 'CRITICAL' : $this->value; // MEDIUM | HIGH
    }
}
