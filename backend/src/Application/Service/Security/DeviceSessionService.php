<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Security;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Security\DeviceContext;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;

/**
 * Device & session security — PROJECT_SPECIFICATION.md §6.13.
 *
 * Implements only mechanisms the frozen `device_sessions` schema supports:
 *   - device registration  → persist `device_fingerprint` / `device_name`
 *   - session identification → existing `uuid` + hashed tokens (AuthService)
 *   - session expiration    → existing `expires_at` + optional idle timeout
 *   - logout                → existing `revoked_at` (AuthService)
 *   - suspicious device detection → NEW_DEVICE / SUSPICIOUS_DEVICE events
 *   - multiple device rules → active-session ceiling
 *   - integration with security events + risk scoring
 *
 * The server is authoritative (SECURITY_RULES.md §1). The fingerprint is a
 * server-derived signal, never a client-supplied authorization input.
 *
 * All thresholds come from `config/security.php` (spec §6.14: configuration,
 * never hard-coded). A `system_settings` move is deferred — see AD-013.
 */
final class DeviceSessionService extends BaseService
{
    private readonly int $maxActiveSessions;
    private readonly bool $enforceBinding;
    private readonly int $idleTimeoutSeconds;

    public function __construct(
        LoggerInterface $logger,
        private readonly DeviceSessionRepository $sessions,
        private readonly SecurityLogService $securityLog,
        Config $config,
    ) {
        parent::__construct($logger);
        $this->maxActiveSessions   = max(1, $config->getInt('security.device.max_active_sessions', 5));
        $this->enforceBinding      = $config->getBool('security.device.enforce_fingerprint_binding', false);
        $this->idleTimeoutSeconds  = max(0, $config->getInt('security.device.idle_timeout_seconds', 0));
    }

    // ─── Registration (login / refresh) ──────────────────────────────────────

    /**
     * Resolve the device columns to persist for a new session and, as a side
     * effect, record NEW_DEVICE / SUSPICIOUS_DEVICE events. Never throws — a
     * login is not blocked here, only observed and fed to risk scoring.
     *
     * @return array{device_fingerprint: ?string, device_name: ?string}
     */
    public function registerSession(int $userId, DeviceContext $device, string $stage = 'login'): array
    {
        $fingerprint = $device->fingerprint;

        if (
            $fingerprint !== null
            && $this->sessions->userHasAnySession($userId)
            && !$this->sessions->userHasSeenFingerprint($userId, $fingerprint)
        ) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::NEW_DEVICE,
                'LOW',
                $userId,
                $device->ipAddress,
                $device->userAgent,
                ['stage' => $stage, 'reason' => 'new_device_fingerprint'],
            );
        }

        // Counted before the pending row is written.
        $activeNow = $this->sessions->countActiveForUser($userId);
        if ($activeNow >= $this->maxActiveSessions) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::SUSPICIOUS_DEVICE,
                'MEDIUM',
                $userId,
                $device->ipAddress,
                $device->userAgent,
                [
                    'stage'           => $stage,
                    'reason'          => 'multiple_active_sessions',
                    'active_sessions' => $activeNow + 1,
                    'ceiling'         => $this->maxActiveSessions,
                ],
            );
        }

        return [
            'device_fingerprint' => $fingerprint,
            'device_name'        => $device->deviceName,
        ];
    }

    // ─── Per-request enforcement (token validation) ──────────────────────────

    /**
     * Enforce session-level rules on an authenticated request:
     *   - idle timeout (in addition to the absolute `expires_at`)
     *   - fingerprint binding (log always; reject only when configured)
     *   - IP-change observation (logged once)
     *
     * Then record activity. Called by AuthService::validateToken.
     *
     * @param array<string, mixed> $session  a `device_sessions` row
     * @throws UnauthorizedException
     */
    public function assertSessionUsable(array $session, DeviceContext $current): void
    {
        $sessionId = (int) $session['id'];
        $userId    = (int) $session['user_id'];

        if ($this->idleTimeoutSeconds > 0) {
            $last = $session['last_active_at'] ?? ($session['created_at'] ?? null);
            if (is_string($last) && $last !== '') {
                $lastTs = strtotime($last);
                if ($lastTs !== false && (time() - $lastTs) > $this->idleTimeoutSeconds) {
                    throw new UnauthorizedException('Session expired due to inactivity.');
                }
            }
        }

        $stored = $session['device_fingerprint'] ?? null;
        if (
            is_string($stored) && $stored !== ''
            && $current->fingerprint !== null
            && !hash_equals($stored, $current->fingerprint)
        ) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::SUSPICIOUS_DEVICE,
                'HIGH',
                $userId,
                $current->ipAddress,
                $current->userAgent,
                ['reason' => 'fingerprint_mismatch', 'session_uuid' => (string) ($session['uuid'] ?? '')],
            );

            if ($this->enforceBinding) {
                throw new UnauthorizedException('This session is bound to a different device.');
            }
        }

        $storedIp = $session['ip_address'] ?? null;
        if (
            is_string($storedIp) && $storedIp !== ''
            && $current->ipAddress !== null
            && $storedIp !== $current->ipAddress
        ) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::SUSPICIOUS_DEVICE,
                'LOW',
                $userId,
                $current->ipAddress,
                $current->userAgent,
                ['reason' => 'ip_address_change', 'session_uuid' => (string) ($session['uuid'] ?? '')],
            );
        }

        $this->sessions->touchActivity($sessionId, $current->ipAddress);
    }

    // ─── Risk scoring integration (attendance step 13) ───────────────────────

    /**
     * Device risk signals for an in-flight attendance attempt. Never throws;
     * returns signal names for RiskEvaluatorInterface to weigh.
     *
     * @return string[]
     */
    public function attendanceRiskSignals(int $deviceSessionId, ?string $currentFingerprint): array
    {
        if ($deviceSessionId <= 0) {
            return [];
        }

        $session = $this->sessions->findRow($deviceSessionId);
        if ($session === null) {
            return [];
        }

        $signals = [];
        $userId  = (int) $session['user_id'];
        $stored  = $session['device_fingerprint'] ?? null;
        $storedKnown = is_string($stored) && $stored !== '';

        if ($storedKnown && $currentFingerprint !== null && !hash_equals($stored, $currentFingerprint)) {
            $signals[] = 'DEVICE_MISMATCH';
        }

        if ($this->sessions->countActiveForUser($userId) > $this->maxActiveSessions) {
            $signals[] = 'MULTIPLE_ACTIVE_DEVICES';
        }

        if (
            $storedKnown
            && $this->sessions->userHasSessionBefore($userId, (int) $session['id'])
            && !$this->sessions->userHasEarlierSessionWithFingerprint($userId, $stored, (int) $session['id'])
        ) {
            // This fingerprint first appears on the very session being used to
            // attend, yet the user already had earlier sessions — a new device.
            $signals[] = 'NEW_DEVICE';
        }

        return $signals;
    }
}
