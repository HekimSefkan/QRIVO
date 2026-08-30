<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Auth;

use QRIVO\Application\DTO\Auth\LoginRequestDTO;
use QRIVO\Application\Service\AuthService;
use QRIVO\Application\Service\LoginAttemptService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\LoginAttemptRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\UserRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Authentication controller.
 *
 * Handles:
 * - POST /api/v1/auth/login
 * - POST /api/v1/auth/logout
 * - POST /api/v1/auth/refresh
 *
 * Responsibilities (Presentation layer only):
 * - Parse and validate request input
 * - Delegate to AuthService for all business logic
 * - Return standardized JSON responses
 *
 * Security:
 * - Never expose internal error details to the client
 * - Never log raw tokens or passwords
 * - Rate limiting is enforced inside AuthService via LoginAttemptService
 */
final class AuthController extends BaseController
{
    private AuthService $authService;

    public function __construct(Connection $db, Logger $logger, Config $config)
    {
        parent::__construct($db, $logger, $config);
        $this->authService = $this->buildAuthService();
    }

    /**
     * POST /api/v1/auth/login
     *
     * Request body: { "email": string, "password": string }
     * Response: { token_type, access_token, refresh_token, expires_at, user }
     */
    public function login(Request $request): JsonResponse
    {
        $validator = new Validator();
        $validator->validate($request->getBody(), [
            'email'    => 'required|string|email',
            'password' => 'required|string|min_length:1',
        ]);

        $dto = new LoginRequestDTO(
            email:     (string) $request->input('email'),
            password:  (string) $request->input('password'),
            ipAddress: $request->getIp(),
            userAgent: (string) ($request->getHeader('user-agent') ?? ''),
        );

        $result = $this->authService->login($dto);

        return $this->success($result->toArray(), 'Login successful.');
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Authorization: Bearer <access_token>
     * Response: { success: true, message: "Logged out successfully." }
     */
    public function logout(Request $request): JsonResponse
    {
        $rawToken = $request->getBearerToken();

        if ($rawToken !== null && $rawToken !== '') {
            $this->authService->logout($rawToken, $request->getIp());
        }

        // Always return success — do not reveal whether token was valid
        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Request body: { "refresh_token": string }
     * Response: { token_type, access_token, refresh_token, expires_at, user }
     */
    public function refresh(Request $request): JsonResponse
    {
        $validator = new Validator();
        $validator->validate($request->getBody(), [
            'refresh_token' => 'required|string|min_length:1',
        ]);

        $rawRefreshToken = (string) $request->input('refresh_token');
        $result          = $this->authService->refresh(
            $rawRefreshToken,
            $request->getIp(),
            (string) ($request->getHeader('user-agent') ?? ''),
        );

        return $this->success($result->toArray(), 'Token refreshed successfully.');
    }

    /**
     * Build the AuthService with all required dependencies.
     *
     * Note: In a future phase this will be replaced by a proper DI container.
     * For now, dependencies are wired manually in the controller constructor,
     * following the same pattern as the existing skeleton.
     */
    private function buildAuthService(): AuthService
    {
        $userRepo         = new UserRepository($this->db);
        $sessionRepo      = new DeviceSessionRepository($this->db);
        $attemptRepo      = new LoginAttemptRepository($this->db);
        $securityEventRepo = new SecurityEventRepository($this->db);
        $auditLogRepo     = new AuditLogRepository($this->db);

        $securityLogService  = new SecurityLogService($this->logger, $securityEventRepo, $auditLogRepo);
        $loginAttemptService = new LoginAttemptService($this->logger, $attemptRepo, $this->config);

        return new AuthService(
            $this->logger,
            $userRepo,
            $sessionRepo,
            $loginAttemptService,
            $securityLogService,
            $this->config,
        );
    }
}
