<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Security event type categories.
 *
 * Defined in PROJECT_SPECIFICATION.md §6.15 and SECURITY_RULES.md §10.
 * All security-sensitive events must be logged.
 */
enum SecurityEventType: string
{
    // Authentication events
    case LOGIN_FAILURE        = 'LOGIN_FAILURE';
    case LOGIN_SUCCESS        = 'LOGIN_SUCCESS';
    case SUSPICIOUS_AUTH      = 'SUSPICIOUS_AUTH';
    case TOKEN_REUSE          = 'TOKEN_REUSE';

    // QR events (for future phases)
    case QR_REPLAY            = 'QR_REPLAY';
    case QR_INVALID           = 'QR_INVALID';
    case QR_EXPIRED           = 'QR_EXPIRED';

    // Challenge events (for future phases)
    case CHALLENGE_INVALID    = 'CHALLENGE_INVALID';
    case CHALLENGE_EXPIRED    = 'CHALLENGE_EXPIRED';

    // Attendance events (for future phases)
    case DUPLICATE_ATTENDANCE = 'DUPLICATE_ATTENDANCE';
    case UNAUTHORIZED_ATTENDANCE = 'UNAUTHORIZED_ATTENDANCE';

    // Authorization events
    case UNAUTHORIZED_ACCESS  = 'UNAUTHORIZED_ACCESS';
    case IDOR_ATTEMPT         = 'IDOR_ATTEMPT';

    // Device events (for future phases)
    case NEW_DEVICE           = 'NEW_DEVICE';
    case SUSPICIOUS_DEVICE    = 'SUSPICIOUS_DEVICE';

    // Risk events (for future phases)
    case RISK_ESCALATION      = 'RISK_ESCALATION';
    case BLOCKED_ATTENDANCE   = 'BLOCKED_ATTENDANCE';
}
