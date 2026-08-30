<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Attendance;

/**
 * A single-use challenge token (TABLES.md `qr_challenges`).
 *
 * Security: `nonce` is the challenge secret the mobile must echo back; it is
 * returned to the client exactly once (at issuance) and is never included in a
 * response again. {@see self::toArray()} omits it.
 */
final class QrChallenge
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $attendanceSessionId,
        public readonly int $studentId,
        public readonly string $nonce,
        public readonly string $qrNonce,
        public readonly string $expiresAt,
        public readonly ?string $usedAt,
        public readonly ?string $createdAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                  (int) $row['id'],
            uuid:                (string) $row['uuid'],
            attendanceSessionId: (int) $row['attendance_session_id'],
            studentId:           (int) $row['student_id'],
            nonce:               (string) $row['nonce'],
            qrNonce:             (string) $row['qr_nonce'],
            expiresAt:           (string) $row['expires_at'],
            usedAt:              $row['used_at'] ?? null,
            createdAt:           $row['created_at'] ?? null,
        );
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    /**
     * Public representation — WITHOUT the challenge nonce.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'challenge_id'          => $this->uuid,
            'attendance_session_id' => $this->attendanceSessionId,
            'student_id'            => $this->studentId,
            'expires_at'            => $this->expiresAt,
            'used_at'               => $this->usedAt,
            'created_at'            => $this->createdAt,
        ];
    }
}
