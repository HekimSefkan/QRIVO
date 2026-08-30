<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Attendance;

use QRIVO\Infrastructure\Repository\BaseRepository;

/**
 * Append-only writes to `risk_assessments`. One row per attendance attempt,
 * written inside the verification transaction (CONSTRAINTS.md §6).
 */
final class RiskAssessmentRepository extends BaseRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');

        return (int) $this->insert('risk_assessments', $data);
    }
}
