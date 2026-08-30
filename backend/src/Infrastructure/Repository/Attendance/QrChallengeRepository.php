<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Attendance;

use QRIVO\Infrastructure\Repository\BaseRepository;

/**
 * Data access for `qr_challenges`.
 *
 * Single-use is enforced atomically by `markUsed()` — an UPDATE guarded by
 * `used_at IS NULL` (DD-003). Reads inside the verification transaction take a
 * row lock on MySQL (`FOR UPDATE`).
 */
final class QrChallengeRepository extends BaseRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');

        return (int) $this->insert('qr_challenges', $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid, bool $lock = false): ?array
    {
        $suffix = ($lock && $this->db->driverName() === 'mysql') ? ' FOR UPDATE' : '';

        return $this->db->fetchOne(
            "SELECT * FROM `qr_challenges` WHERE `uuid` = :uuid LIMIT 1{$suffix}",
            ['uuid' => $uuid],
        );
    }

    /**
     * Atomically mark a challenge used. Returns true only if THIS call performed
     * the transition (used_at was NULL).
     */
    public function markUsed(int $id, string $usedAt): bool
    {
        return $this->db->execute(
            'UPDATE `qr_challenges` SET `used_at` = :used WHERE `id` = :id AND `used_at` IS NULL',
            ['used' => $usedAt, 'id' => $id],
        ) > 0;
    }

    /** Has this student already been issued a challenge for this QR nonce? (DD-004) */
    public function studentHasChallengeForQrNonce(int $studentId, string $qrNonce): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `qr_challenges` WHERE `student_id` = :sid AND `qr_nonce` = :qn LIMIT 1',
            ['sid' => $studentId, 'qn' => $qrNonce],
        ) !== null;
    }

    /** Challenge requests for (student, session) since $since ('Y-m-d H:i:s'). */
    public function countForStudentSessionSince(int $studentId, int $sessionId, string $since): int
    {
        return (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM `qr_challenges`
              WHERE `student_id` = :sid AND `attendance_session_id` = :ses AND `created_at` >= :since',
            ['sid' => $studentId, 'ses' => $sessionId, 'since' => $since],
        )['c'] ?? 0);
    }
}
