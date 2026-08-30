<?php

declare(strict_types=1);

namespace QRIVO\Application\Service;

use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;

/**
 * Security event and audit logging service.
 *
 * Centralizes all security event recording and audit trail creation.
 *
 * Security (SECURITY_RULES.md §9, §10, §11):
 * - details/old_value/new_value must NEVER contain passwords, tokens, or private keys.
 * - The Logger's own sanitize() method provides an additional safety net.
 * - This service is the single point of security event creation.
 */
final class SecurityLogService extends BaseService
{
    public function __construct(
        LoggerInterface                  $logger,
        private readonly SecurityEventRepository $securityEventRepo,
        private readonly AuditLogRepository      $auditLogRepo,
    ) {
        parent::__construct($logger);
    }

    /**
     * Record a security event in `security_events`.
     *
     * @param array<string, mixed> $details  Must NOT contain passwords, tokens, or private keys.
     */
    public function recordSecurityEvent(
        SecurityEventType $type,
        string            $severity,
        ?int              $userId     = null,
        ?string           $ipAddress  = null,
        ?string           $userAgent  = null,
        array             $details    = [],
    ): void {
        try {
            $this->securityEventRepo->create([
                'event_type'  => $type->value,
                'severity'    => $severity,
                'user_id'     => $userId,
                'ip_address'  => $ipAddress,
                'user_agent'  => $userAgent,
                'details'     => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Security event recording must NEVER crash the main flow.
            // Log locally and continue.
            $this->logger->error('Failed to record security event', [
                'event_type' => $type->value,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an audit log entry in `audit_logs`.
     *
     * @param array<string, mixed>|null $oldValue  Must NOT contain secrets.
     * @param array<string, mixed>|null $newValue  Must NOT contain secrets.
     */
    public function recordAuditLog(
        string  $eventType,
        ?int    $actorUserId  = null,
        string  $targetEntity = '',
        ?int    $targetId     = null,
        ?array  $oldValue     = null,
        ?array  $newValue     = null,
        ?string $reason       = null,
        ?string $ipAddress    = null,
    ): void {
        try {
            $this->auditLogRepo->create([
                'event_type'    => $eventType,
                'actor_user_id' => $actorUserId,
                'target_entity' => $targetEntity,
                'target_id'     => $targetId,
                'old_value'     => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                'new_value'     => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                'reason'        => $reason,
                'ip_address'    => $ipAddress,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record audit log', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write an audit log entry that MUST succeed — used inside a transaction
     * where the audited change and its record must commit or roll back together
     * (e.g. manual attendance, ATTENDANCE_ALGORITHM.md §6 step 8). Returns the
     * new `audit_logs` id and re-throws on failure.
     *
     * @param array<string, mixed>|null $oldValue Must NOT contain secrets.
     * @param array<string, mixed>|null $newValue Must NOT contain secrets.
     */
    public function writeAuditLog(
        string  $eventType,
        ?int    $actorUserId,
        string  $targetEntity,
        ?int    $targetId,
        ?array  $oldValue,
        ?array  $newValue,
        ?string $reason,
        ?string $ipAddress,
    ): int {
        return $this->auditLogRepo->create([
            'event_type'    => $eventType,
            'actor_user_id' => $actorUserId,
            'target_entity' => $targetEntity,
            'target_id'     => $targetId,
            'old_value'     => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            'new_value'     => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            'reason'        => $reason,
            'ip_address'    => $ipAddress,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
