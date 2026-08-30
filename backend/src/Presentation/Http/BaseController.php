<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http;

use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Application\Service\AuthService;
use QRIVO\Application\Service\LoginAttemptService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\LoginAttemptRepository;
use QRIVO\Infrastructure\Repository\PermissionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\UserRepository;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Base controller.
 *
 * Provides shared infrastructure for all concrete controllers:
 * - Database access, logger, config
 * - Response helpers
 * - Server-side authentication + authorization entry points
 *
 * Controllers are in the Presentation layer. They MUST NOT contain business
 * logic — they parse input, call `authenticate()` / the authorization guards,
 * then delegate to services.
 *
 * Security:
 * - `authenticate()` is the ONLY sanctioned way to obtain the actor context.
 *   It re-validates the bearer token against the database on every request.
 * - Authorization is never assumed from the frontend. Every privileged action
 *   calls a guard on `authorization()`.
 */
abstract class BaseController
{
    private ?AuthService $authService = null;
    private ?AuthorizationService $authorizationService = null;

    public function __construct(
        protected readonly Connection $db,
        protected readonly Logger     $logger,
        protected readonly Config     $config,
    ) {}

    // ─── Authentication / Authorization ────────────────────────────────────────

    /**
     * Validate the request's bearer token and return the actor context.
     *
     * @return array{user_id:int, uuid:string, email:string, first_name:string, last_name:string, roles:string[], session_id:int, ip_address:string, user_agent:string}
     * @throws \QRIVO\Domain\Exception\UnauthorizedException when the token is missing, invalid, expired or revoked
     */
    protected function authenticate(Request $request): array
    {
        $token = $request->getBearerToken();
        if ($token === null || $token === '') {
            throw new \QRIVO\Domain\Exception\UnauthorizedException('Authentication required.');
        }

        $context = $this->authServiceInstance()->validateToken($token);
        $context['ip_address'] = $request->getIp();
        $context['user_agent'] = (string) ($request->getHeader('user-agent') ?? '');

        return $context;
    }

    protected function authorization(): AuthorizationService
    {
        if ($this->authorizationService === null) {
            $securityLog = new SecurityLogService(
                $this->logger,
                new SecurityEventRepository($this->db),
                new AuditLogRepository($this->db),
            );

            $this->authorizationService = new AuthorizationService(
                $this->logger,
                new PermissionRepository($this->db),
                new RelationshipRepository($this->db),
                $securityLog,
            );
        }

        return $this->authorizationService;
    }

    protected function authServiceInstance(): AuthService
    {
        if ($this->authService === null) {
            $securityLog = new SecurityLogService(
                $this->logger,
                new SecurityEventRepository($this->db),
                new AuditLogRepository($this->db),
            );
            $loginAttempts = new LoginAttemptService(
                $this->logger,
                new LoginAttemptRepository($this->db),
                $this->config,
            );

            $this->authService = new AuthService(
                $this->logger,
                new UserRepository($this->db),
                new DeviceSessionRepository($this->db),
                $loginAttempts,
                $securityLog,
                $this->config,
            );
        }

        return $this->authService;
    }

    // ─── Response helpers ─────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $data */
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return JsonResponse::success($data, $message, $status);
    }

    /** @param array<string, string[]> $errors */
    protected function validationError(string $message, array $errors): JsonResponse
    {
        return JsonResponse::validationError($message, $errors);
    }

    protected function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return JsonResponse::error($message, 404);
    }

    protected function forbidden(string $message = 'Access denied.'): JsonResponse
    {
        return JsonResponse::error($message, 403);
    }

    protected function unauthorized(string $message = 'Authentication required.'): JsonResponse
    {
        return JsonResponse::error($message, 401);
    }

    protected function created(mixed $data = null, string $message = 'Created.'): JsonResponse
    {
        return JsonResponse::created($data, $message);
    }
}
