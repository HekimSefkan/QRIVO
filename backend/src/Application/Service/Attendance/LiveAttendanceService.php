<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Attendance;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\AttendanceStatus;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;

/**
 * Teacher live-attendance view (ATTENDANCE_ALGORITHM.md §8, PROJECT_SPECIFICATION.md §6.8).
 *
 * Realtime architecture: AJAX polling (the spec's documented fallback — the
 * frozen stack has no WebSocket server). Callers poll `counters()` every 2-5 s
 * and re-fetch `students()` when `students_version` changes.
 *
 * Security boundary (ARCHITECTURE_FREEZE.md §2.12):
 * - The caller must be the TEACHER who owns THIS session (checked on EVERY call).
 * - Only students in this session are ever returned; no cross-session data.
 * - `session_secret` is never exposed.
 */
final class LiveAttendanceService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AttendanceSessionRepository $sessions,
        private readonly AttendanceRecordRepository $records,
        private readonly RelationshipRepository $relationships,
        private readonly QrService $qr,
        private readonly SecurityLogService $securityLog,
    ) {
        parent::__construct($logger);
    }

    /**
     * Full snapshot for the initial dashboard render: session info, current QR
     * (when ACTIVE), live counters, and the filtered student list.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters { search?, status? }
     * @return array<string, mixed>
     */
    public function snapshot(array $actor, int $sessionId, array $filters = [], ?\DateTimeImmutable $at = null): array
    {
        $at      ??= new \DateTimeImmutable('now');
        $session   = $this->requireOwnedSession($actor, $sessionId);

        $data = [
            'session'          => $this->sessionInfo($session, $at),
            'counters'         => $this->counterBlock($sessionId),
            'students_version' => $this->records->rosterVersion($sessionId),
            'students'         => $this->roster($sessionId, $filters),
            'server_time'      => $at->format('c'),
            'poll_interval_ms' => 3000,
        ];

        if (($session['status'] ?? null) === 'ACTIVE') {
            $data['qr'] = $this->qr->generate($session, $at);
        }

        return $data;
    }

    /**
     * Lightweight poll payload — counters + session lifecycle + a change signal.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function counters(array $actor, int $sessionId, ?\DateTimeImmutable $at = null): array
    {
        $at    ??= new \DateTimeImmutable('now');
        $session = $this->requireOwnedSession($actor, $sessionId);

        return [
            'session_status'    => (string) $session['status'],
            'remaining_seconds' => $this->remainingSeconds($session, $at),
            'counters'          => $this->counterBlock($sessionId),
            'students_version'  => $this->records->rosterVersion($sessionId),
            'server_time'       => $at->format('c'),
        ];
    }

    /**
     * The student list only (for a delta refresh). Supports search / status /
     * updated_since filters.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters { search?, status?, updated_since? }
     * @return array<string, mixed>
     */
    public function students(array $actor, int $sessionId, array $filters = [], ?\DateTimeImmutable $at = null): array
    {
        $at    ??= new \DateTimeImmutable('now');
        $this->requireOwnedSession($actor, $sessionId);

        return [
            'students'         => $this->roster($sessionId, $filters),
            'students_version' => $this->records->rosterVersion($sessionId),
            'server_time'      => $at->format('c'),
        ];
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed> the session row (includes session_secret — internal only)
     */
    private function requireOwnedSession(array $actor, int $sessionId): array
    {
        $session = $this->sessions->findRow($sessionId);
        if ($session === null) {
            throw new NotFoundException('Attendance session not found.');
        }

        $teacherId = $this->relationships->findTeacherIdForUser((int) $actor['user_id']);
        if ($teacherId === null || (int) $session['teacher_id'] !== $teacherId) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::IDOR_ATTEMPT,
                'HIGH',
                isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null,
                is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
                is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null,
                ['action' => 'view live attendance', 'attendance_session_id' => $sessionId],
            );
            throw new ForbiddenException('You are not authorized to view this attendance session.');
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function sessionInfo(array $session, \DateTimeImmutable $at): array
    {
        return [
            'id'                => (int) $session['id'],
            'uuid'              => (string) $session['uuid'],
            'status'            => (string) $session['status'],
            'course_id'         => (int) $session['course_id'],
            'class_id'          => (int) $session['class_id'],
            'room_id'           => (int) $session['room_id'],
            'academic_term_id'  => (int) $session['academic_term_id'],
            'start_time'        => (string) $session['start_time'],
            'end_time'          => $session['end_time'] ?? null,
            'expires_at'        => (string) $session['expires_at'],
            'remaining_seconds' => $this->remainingSeconds($session, $at),
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function remainingSeconds(array $session, \DateTimeImmutable $at): int
    {
        return max(0, strtotime((string) $session['expires_at']) - $at->getTimestamp());
    }

    /**
     * Live counters — TOTAL + one per attendance state (ATTENDANCE_ALGORITHM.md §8).
     *
     * @return array<string, int>
     */
    private function counterBlock(int $sessionId): array
    {
        $byStatus = $this->records->countByStatus($sessionId);

        $counters = ['TOTAL' => array_sum($byStatus)];
        foreach (AttendanceStatus::cases() as $status) {
            $counters[$status->value] = $byStatus[$status->value] ?? 0;
        }

        return $counters;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function roster(int $sessionId, array $filters): array
    {
        $search = isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '' ? $filters['search'] : null;
        $status = isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '' ? strtoupper($filters['status']) : null;
        $since  = isset($filters['updated_since']) && is_string($filters['updated_since']) && $filters['updated_since'] !== '' ? $filters['updated_since'] : null;

        if ($status !== null && AttendanceStatus::tryFrom($status) === null) {
            $status = null; // ignore an unknown status filter rather than error
        }

        $rows = $this->records->liveRoster($sessionId, $search, $status, $since);

        return array_map(static fn (array $r): array => [
            'student_id'     => (int) $r['student_id'],
            'student_number' => (string) $r['student_number'],
            'first_name'     => (string) $r['first_name'],
            'last_name'      => (string) $r['last_name'],
            'status'         => (string) $r['status'],
            'source'         => (string) $r['source'],
            'marked_at'      => $r['marked_at'] ?? null,
        ], $rows);
    }
}
