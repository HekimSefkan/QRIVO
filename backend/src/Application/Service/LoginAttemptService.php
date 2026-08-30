<?php

declare(strict_types=1);

namespace QRIVO\Application\Service;

use QRIVO\Infrastructure\Config\Config;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Infrastructure\Repository\LoginAttemptRepository;

/**
 * Login attempt tracking and rate limiting service.
 *
 * Enforces rate limiting ENTIRELY server-side.
 * Security (SECURITY_RULES.md §3):
 * - Login rate limiting is required
 * - Login attempt tracking is required
 * - Failed login logging is required as a security event
 *
 * Rate limit configuration is read from config — not hard-coded.
 */
final class LoginAttemptService extends BaseService
{
    private readonly int $maxAttemptsByIp;
    private readonly int $maxAttemptsByEmail;
    private readonly int $windowSeconds;

    public function __construct(
        LoggerInterface                     $logger,
        private readonly LoginAttemptRepository $attemptRepo,
        Config                      $config,
    ) {
        parent::__construct($logger);
        $this->maxAttemptsByIp    = $config->getInt('auth.rate_limit.max_by_ip', 20);
        $this->maxAttemptsByEmail = $config->getInt('auth.rate_limit.max_by_email', 10);
        $this->windowSeconds      = $config->getInt('auth.rate_limit.window_seconds', 900); // 15 minutes
    }

    /**
     * Determine whether the login attempt should be rate-limited.
     *
     * Checks BOTH IP-based and email-based attempt counts.
     * Either exceeding the threshold causes a rate limit response.
     */
    public function isRateLimited(string $ipAddress, string $email): bool
    {
        $byIp    = $this->attemptRepo->countRecentFailuresByIp($ipAddress, $this->windowSeconds);
        $byEmail = $this->attemptRepo->countRecentFailuresByEmail($email, $this->windowSeconds);

        return $byIp >= $this->maxAttemptsByIp || $byEmail >= $this->maxAttemptsByEmail;
    }

    /**
     * Record a login attempt (success or failure).
     *
     * @param int|null $userId  null when the user was not found
     */
    public function record(
        ?int   $userId,
        string $emailAttempted,
        string $ipAddress,
        string $userAgent,
        bool   $success,
    ): void {
        try {
            $this->attemptRepo->create([
                'user_id'          => $userId,
                'email_attempted'  => $emailAttempted,
                'ip_address'       => $ipAddress,
                'user_agent'       => $userAgent,
                'success'          => $success ? 1 : 0,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Attempt tracking must NEVER block the auth flow
            $this->logger->error('Failed to record login attempt', ['error' => $e->getMessage()]);
        }
    }
}
