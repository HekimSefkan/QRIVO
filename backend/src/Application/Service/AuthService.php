<?php

declare(strict_types=1);

namespace QRIVO\Application\Service;

use QRIVO\Application\DTO\Auth\LoginRequestDTO;
use QRIVO\Application\DTO\Auth\TokenResponseDTO;
use QRIVO\Application\Service\Security\DeviceSessionService;
use QRIVO\Domain\Entity\User;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Security\DeviceContext;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\UserRepository;

/**
 * Authentication service.
 *
 * Implements the complete authentication flow:
 * - login (credential verification, rate limiting, token issuance)
 * - logout (token revocation)
 * - refresh (refresh token rotation)
 * - token validation (for AuthMiddleware)
 *
 * Security (ARCHITECTURE_FREEZE.md §2.3, SECURITY_RULES.md §3):
 * - Argon2id password verification — NEVER plaintext comparison
 * - Tokens are 64-byte cryptographically secure random bytes, hex-encoded
 * - Only SHA-256 hashes are stored in the database
 * - Rate limiting enforced server-side via LoginAttemptService
 * - Token reuse detection: if a refresh token is found but revoked → security event
 * - All failed attempts trigger security events
 * - All successful logins trigger audit logs
 * - Internal error details are NEVER exposed to the client
 */
final class AuthService extends BaseService
{
    private readonly int $accessTokenTtl;
    private readonly int $refreshTokenTtl;
    private ?DeviceSessionService $deviceSessions = null;

    public function __construct(
        LoggerInterface                     $logger,
        private readonly UserRepository         $userRepo,
        private readonly DeviceSessionRepository $sessionRepo,
        private readonly LoginAttemptService     $loginAttemptService,
        private readonly SecurityLogService      $securityLogService,
        private readonly Config             $config,
    ) {
        parent::__construct($logger);
        $this->accessTokenTtl  = $config->getInt('auth.access_token_ttl', 3600);
        $this->refreshTokenTtl = $config->getInt('auth.refresh_token_ttl', 2592000);
    }

    /**
     * Device/session security (PROJECT_SPECIFICATION.md §6.13). Lazily built
     * from this service's own dependencies so construction sites are unchanged.
     */
    private function deviceSessions(): DeviceSessionService
    {
        return $this->deviceSessions ??= new DeviceSessionService(
            $this->logger,
            $this->sessionRepo,
            $this->securityLogService,
            $this->config,
        );
    }

    // ─── Login ─────────────────────────────────────────────────────────────────

    /**
     * Authenticate a user with email + password.
     *
     * Full validation sequence:
     * 1. Rate limit check (IP + email)
     * 2. User lookup by email
     * 3. Argon2id password verification
     * 4. Active account check
     * 5. Approved account check
     * 6. Token generation (access + refresh)
     * 7. Session persistence (hashes only)
     * 8. Attempt recording (success=1)
     * 9. Audit log (LOGIN_SUCCESS)
     *
     * @throws TooManyRequestsException when rate limited
     * @throws UnauthorizedException    when credentials invalid or account restricted
     */
    public function login(LoginRequestDTO $dto): TokenResponseDTO
    {
        // 1. Rate limit check (server-side, before any DB user lookup)
        if ($this->loginAttemptService->isRateLimited($dto->ipAddress, $dto->email)) {
            $this->securityLogService->recordSecurityEvent(
                SecurityEventType::LOGIN_FAILURE,
                'HIGH',
                null,
                $dto->ipAddress,
                $dto->userAgent,
                ['reason' => 'rate_limited', 'email' => $dto->email]
            );
            throw new TooManyRequestsException('Too many login attempts. Please try again later.');
        }

        // 2. User lookup
        $userRow = $this->userRepo->findByEmail($dto->email);

        // 3. Password verification — use constant-time comparison via password_verify
        //    Even if user not found, run a dummy verify to prevent timing attacks.
        $passwordValid = false;
        if ($userRow !== null) {
            $passwordValid = password_verify($dto->password, $userRow['password_hash']);
        } else {
            // Dummy verify to maintain constant timing
            password_verify($dto->password, '$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy');
        }

        if ($userRow === null || !$passwordValid) {
            $userId = $userRow['id'] ?? null;
            $this->loginAttemptService->record(
                $userId ? (int) $userId : null,
                $dto->email,
                $dto->ipAddress,
                $dto->userAgent,
                false,
            );
            $this->securityLogService->recordSecurityEvent(
                SecurityEventType::LOGIN_FAILURE,
                'MEDIUM',
                $userId ? (int) $userId : null,
                $dto->ipAddress,
                $dto->userAgent,
                ['reason' => 'invalid_credentials', 'email' => $dto->email]
            );
            // Security: never reveal whether the email exists or the password is wrong
            throw new UnauthorizedException('Invalid credentials.');
        }

        // 4. Active account check
        if (!(bool) $userRow['is_active']) {
            $this->loginAttemptService->record((int) $userRow['id'], $dto->email, $dto->ipAddress, $dto->userAgent, false);
            $this->securityLogService->recordSecurityEvent(
                SecurityEventType::LOGIN_FAILURE,
                'MEDIUM',
                (int) $userRow['id'],
                $dto->ipAddress,
                $dto->userAgent,
                ['reason' => 'account_inactive']
            );
            throw new UnauthorizedException('Account is inactive.');
        }

        // 5. Approval check
        if (!(bool) $userRow['is_approved']) {
            $this->loginAttemptService->record((int) $userRow['id'], $dto->email, $dto->ipAddress, $dto->userAgent, false);
            $this->securityLogService->recordSecurityEvent(
                SecurityEventType::LOGIN_FAILURE,
                'LOW',
                (int) $userRow['id'],
                $dto->ipAddress,
                $dto->userAgent,
                ['reason' => 'account_not_approved']
            );
            throw new UnauthorizedException('Account is pending approval.');
        }

        // 6. Generate tokens
        $accessToken  = $this->generateToken();
        $refreshToken = $this->generateToken();
        $expiresAt    = date('Y-m-d H:i:s', time() + $this->accessTokenTtl);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $this->refreshTokenTtl);

        // 7. Store session with hashed tokens (NEVER store plaintext).
        //    Device registration (§6.13): derive + persist the fingerprint and
        //    device name, and emit NEW_DEVICE / SUSPICIOUS_DEVICE events.
        $device        = DeviceContext::fromRequest($dto->ipAddress, $dto->userAgent, $dto->deviceId, $dto->deviceName);
        $deviceColumns = $this->deviceSessions()->registerSession((int) $userRow['id'], $device, 'login');

        $sessionUuid = $this->generateUuid();
        $this->sessionRepo->create([
            'uuid'                => $sessionUuid,
            'user_id'             => (int) $userRow['id'],
            'device_fingerprint'  => $deviceColumns['device_fingerprint'],
            'device_name'         => $deviceColumns['device_name'],
            'ip_address'          => $dto->ipAddress,
            'user_agent'          => $dto->userAgent,
            'access_token_hash'   => $this->hashToken($accessToken),
            'refresh_token_hash'  => $this->hashToken($refreshToken),
            'expires_at'          => $refreshExpiresAt,
            'last_active_at'      => date('Y-m-d H:i:s'),
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        // 8. Record successful attempt
        $this->loginAttemptService->record((int) $userRow['id'], $dto->email, $dto->ipAddress, $dto->userAgent, true);

        // 9. Audit log
        $this->securityLogService->recordAuditLog(
            'LOGIN_SUCCESS',
            (int) $userRow['id'],
            'user',
            (int) $userRow['id'],
            null,
            null,
            null,
            $dto->ipAddress,
        );

        // Build user entity for response (no password_hash)
        $roles = $this->userRepo->getRoleNames((int) $userRow['id']);
        $user  = User::fromArray($userRow, $roles);

        return new TokenResponseDTO(
            accessToken:  $accessToken,
            refreshToken: $refreshToken,
            expiresAt:    $expiresAt,
            tokenType:    'Bearer',
            user:         $user->toSafeArray(),
        );
    }

    // ─── Logout ────────────────────────────────────────────────────────────────

    /**
     * Invalidate the session associated with the given access token.
     *
     * Security: revoke the session record so the token can no longer be used.
     */
    public function logout(string $rawAccessToken, string $ipAddress): void
    {
        $hash    = $this->hashToken($rawAccessToken);
        $session = $this->sessionRepo->findByAccessTokenHash($hash);

        if ($session !== null) {
            $this->sessionRepo->revoke((int) $session['id']);

            $this->securityLogService->recordAuditLog(
                'LOGOUT',
                (int) $session['user_id'],
                'device_session',
                (int) $session['id'],
                null,
                null,
                null,
                $ipAddress,
            );
        }
        // If session not found, do not reveal that — respond with success either way
    }

    // ─── Refresh ───────────────────────────────────────────────────────────────

    /**
     * Rotate tokens using a valid refresh token.
     *
     * Security:
     * - If refresh token is found but revoked → TOKEN_REUSE security event.
     *   This may indicate token theft. The entire session remains revoked.
     * - If refresh token is not found or expired → UnauthorizedException.
     *
     * @throws UnauthorizedException
     */
    public function refresh(
        string $rawRefreshToken,
        string $ipAddress,
        string $userAgent,
        ?string $deviceId = null,
        ?string $deviceName = null,
    ): TokenResponseDTO {
        $hash    = $this->hashToken($rawRefreshToken);
        $session = $this->sessionRepo->findByRefreshTokenHash($hash);

        // Token not found
        if ($session === null) {
            throw new UnauthorizedException('Invalid or expired refresh token.');
        }

        // Token reuse detection: if revoked → security event
        if ($session['revoked_at'] !== null) {
            $this->securityLogService->recordSecurityEvent(
                SecurityEventType::TOKEN_REUSE,
                'HIGH',
                (int) $session['user_id'],
                $ipAddress,
                $userAgent,
                ['reason' => 'revoked_refresh_token_reuse']
            );
            throw new UnauthorizedException('Refresh token has been revoked.');
        }

        // Token expired
        if (strtotime($session['expires_at']) < time()) {
            throw new UnauthorizedException('Refresh token has expired.');
        }

        // Revoke the old session (token rotation)
        $this->sessionRepo->revoke((int) $session['id']);

        // Issue new tokens
        $userRow = $this->userRepo->findUserById((int) $session['user_id']);
        if ($userRow === null || !(bool) $userRow['is_active'] || !(bool) $userRow['is_approved']) {
            throw new UnauthorizedException('Account is no longer active.');
        }

        $accessToken      = $this->generateToken();
        $refreshToken     = $this->generateToken();
        $expiresAt        = date('Y-m-d H:i:s', time() + $this->accessTokenTtl);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $this->refreshTokenTtl);
        $sessionUuid      = $this->generateUuid();

        $device        = DeviceContext::fromRequest($ipAddress, $userAgent, $deviceId, $deviceName);
        $deviceColumns = $this->deviceSessions()->registerSession((int) $userRow['id'], $device, 'refresh');

        $this->sessionRepo->create([
            'uuid'               => $sessionUuid,
            'user_id'            => (int) $userRow['id'],
            'device_fingerprint' => $deviceColumns['device_fingerprint'],
            'device_name'        => $deviceColumns['device_name'],
            'ip_address'         => $ipAddress,
            'user_agent'         => $userAgent,
            'access_token_hash'  => $this->hashToken($accessToken),
            'refresh_token_hash' => $this->hashToken($refreshToken),
            'expires_at'         => $refreshExpiresAt,
            'last_active_at'     => date('Y-m-d H:i:s'),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $roles = $this->userRepo->getRoleNames((int) $userRow['id']);
        $user  = User::fromArray($userRow, $roles);

        return new TokenResponseDTO(
            accessToken:  $accessToken,
            refreshToken: $refreshToken,
            expiresAt:    $expiresAt,
            tokenType:    'Bearer',
            user:         $user->toSafeArray(),
        );
    }

    // ─── Token Validation (for AuthMiddleware) ─────────────────────────────────

    /**
     * Validate an access token and return the user context array.
     *
     * Used by AuthMiddleware to authenticate protected routes.
     * Updates last_active_at on success.
     *
     * When a {@see DeviceContext} is supplied, device/session rules are enforced
     * (idle timeout, fingerprint binding) and a fingerprint is returned so
     * downstream services (attendance risk scoring) can reuse it.
     *
     * @return array{user_id: int, uuid: string, email: string, roles: string[], session_id: int, device_fingerprint: ?string}
     * @throws UnauthorizedException
     */
    public function validateToken(string $rawAccessToken, ?DeviceContext $device = null): array
    {
        $hash    = $this->hashToken($rawAccessToken);
        $session = $this->sessionRepo->findByAccessTokenHash($hash);

        if ($session === null) {
            throw new UnauthorizedException('Invalid or expired token.');
        }

        $userRow = $this->userRepo->findUserById((int) $session['user_id']);
        if ($userRow === null || !(bool) $userRow['is_active'] || !(bool) $userRow['is_approved']) {
            throw new UnauthorizedException('Account is inactive or no longer approved.');
        }

        // Device & session security (§6.13): idle timeout + fingerprint binding.
        // This also records activity (last_active_at / ip).
        if ($device !== null) {
            $this->deviceSessions()->assertSessionUsable($session, $device);
        } else {
            $this->sessionRepo->updateLastActive((int) $session['id']);
        }

        $roles = $this->userRepo->getRoleNames((int) $userRow['id']);

        return [
            'user_id'            => (int) $userRow['id'],
            'uuid'               => $userRow['uuid'],
            'email'              => $userRow['email'],
            'first_name'         => $userRow['first_name'],
            'last_name'          => $userRow['last_name'],
            'roles'              => $roles,
            'session_id'         => (int) $session['id'],
            'device_fingerprint' => $device?->fingerprint,
        ];
    }

    // ─── Token Utilities ───────────────────────────────────────────────────────

    /**
     * Generate a cryptographically secure random token.
     * Returns 128 hex characters (64 bytes of entropy).
     *
     * Security: uses random_bytes() — never predictable.
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    /**
     * Hash a raw token for database storage.
     * Uses SHA-256 — sufficient for lookup; Argon2id is for passwords only.
     *
     * Security: raw tokens are NEVER stored.
     */
    public function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Generate a version 4 UUID for session identifiers.
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
