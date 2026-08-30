<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Attendance;

use QRIVO\Infrastructure\Repository\BaseRepository;

/**
 * The dynamic-QR nonce replay store (`qr_used_nonces`).
 *
 * `consume()` records a QR nonce the first time it is turned into a challenge.
 * `UNIQUE(nonce)` means a second `consume()` of the same nonce throws
 * (SQLSTATE 23000) — that is the atomic, race-safe replay guard.
 */
final class QrNonceRepository extends BaseRepository
{
    public function nonceExists(string $nonce): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM `qr_used_nonces` WHERE `nonce` = :n LIMIT 1',
            ['n' => $nonce],
        ) !== null;
    }

    /**
     * Record a consumed QR nonce. Throws \PDOException with SQLSTATE 23000 if the
     * nonce was already consumed.
     */
    public function consume(int $attendanceSessionId, string $nonce, string $consumedAt): void
    {
        $this->db->execute(
            'INSERT INTO `qr_used_nonces` (`attendance_session_id`, `nonce`, `consumed_at`, `created_at`)
             VALUES (:sid, :n, :at, :created)',
            ['sid' => $attendanceSessionId, 'n' => $nonce, 'at' => $consumedAt, 'created' => date('Y-m-d H:i:s')],
        );
    }
}
