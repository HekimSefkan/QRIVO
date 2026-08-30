<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Every failure path of the challenge-response pipeline (ATTENDANCE_ALGORITHM.md §4).
 *
 * The reason is used server-side for the security_events log only. The mobile
 * client is given a GENERIC message and a coarse HTTP status — technical
 * security details are never exposed (§4 "Error Handling").
 */
enum ChallengeFailureReason: string
{
    // ─── QR (steps 2-4) ──────────────────────────────────────────────────────
    case QR_MALFORMED        = 'QR_MALFORMED';
    case QR_SESSION_NOT_FOUND = 'QR_SESSION_NOT_FOUND';
    case QR_SESSION_NOT_ACTIVE = 'QR_SESSION_NOT_ACTIVE';
    case QR_EXPIRED          = 'QR_EXPIRED';
    case QR_BAD_SIGNATURE    = 'QR_BAD_SIGNATURE';
    case QR_NONCE_REPLAYED   = 'QR_NONCE_REPLAYED';        // this student already got a challenge for this QR nonce (DD-004)

    // ─── Student identity / membership (steps 8-9) ───────────────────────────
    case NO_STUDENT_PROFILE  = 'NO_STUDENT_PROFILE';
    case NOT_ENROLLED_IN_COURSE = 'NOT_ENROLLED_IN_COURSE';
    case NOT_ENROLLED_IN_CLASS  = 'NOT_ENROLLED_IN_CLASS';

    // ─── Challenge (steps 5-7) ──────────────────────────────────────────────
    case CHALLENGE_NOT_FOUND = 'CHALLENGE_NOT_FOUND';
    case CHALLENGE_NOT_OWNED = 'CHALLENGE_NOT_OWNED';      // belongs to another student
    case CHALLENGE_EXPIRED   = 'CHALLENGE_EXPIRED';
    case CHALLENGE_ALREADY_USED = 'CHALLENGE_ALREADY_USED'; // single-use / replay
    case CHALLENGE_RESPONSE_MISMATCH = 'CHALLENGE_RESPONSE_MISMATCH'; // submitted nonce != challenge nonce
    case CHALLENGE_QR_MISMATCH = 'CHALLENGE_QR_MISMATCH';  // resubmitted QR is not the one the challenge was issued for

    // ─── Attendance (step 10) ──────────────────────────────────────────────
    case DUPLICATE_ATTENDANCE = 'DUPLICATE_ATTENDANCE';    // already PRESENT / already resolved

    // ─── Rate limit / risk (steps 11, 13) ─────────────────────────────────
    case RATE_LIMITED        = 'RATE_LIMITED';
    case RISK_BLOCKED        = 'RISK_BLOCKED';

    public function securityEventType(): SecurityEventType
    {
        return match ($this) {
            self::QR_EXPIRED                 => SecurityEventType::QR_EXPIRED,
            self::QR_MALFORMED,
            self::QR_BAD_SIGNATURE,
            self::QR_SESSION_NOT_FOUND,
            self::QR_SESSION_NOT_ACTIVE      => SecurityEventType::QR_INVALID,
            self::QR_NONCE_REPLAYED,
            self::CHALLENGE_ALREADY_USED     => SecurityEventType::QR_REPLAY,
            self::CHALLENGE_NOT_FOUND,
            self::CHALLENGE_EXPIRED          => SecurityEventType::CHALLENGE_EXPIRED,
            self::CHALLENGE_RESPONSE_MISMATCH,
            self::CHALLENGE_QR_MISMATCH      => SecurityEventType::CHALLENGE_INVALID,
            self::CHALLENGE_NOT_OWNED,
            self::NOT_ENROLLED_IN_COURSE,
            self::NOT_ENROLLED_IN_CLASS,
            self::NO_STUDENT_PROFILE         => SecurityEventType::UNAUTHORIZED_ATTENDANCE,
            self::DUPLICATE_ATTENDANCE       => SecurityEventType::DUPLICATE_ATTENDANCE,
            self::RATE_LIMITED               => SecurityEventType::SUSPICIOUS_AUTH,
            self::RISK_BLOCKED               => SecurityEventType::BLOCKED_ATTENDANCE,
        };
    }
}
