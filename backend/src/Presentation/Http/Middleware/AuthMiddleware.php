<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Middleware;

use QRIVO\Application\Service\AuthService;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Authentication middleware.
 *
 * Validates the Bearer token on protected routes.
 * Attaches the validated user context to the request as `auth_user`.
 *
 * Security (SECURITY_RULES.md §2):
 * - All security decisions are server-side.
 * - The token is validated against the database on every request.
 * - Expired, revoked, or invalid tokens result in HTTP 401.
 * - Internal error details are NEVER exposed to the client.
 *
 * Usage: applied per-route in the router (not globally, since /health and /auth/login
 * do not require authentication).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $authService) {}

    public function process(Request $request, callable $next): JsonResponse
    {
        $rawToken = $request->getBearerToken();

        if ($rawToken === null || $rawToken === '') {
            return JsonResponse::error('Authentication required.', 401);
        }

        try {
            $userContext = $this->authService->validateToken($rawToken);
        } catch (UnauthorizedException $e) {
            return JsonResponse::error($e->getMessage(), 401);
        } catch (\Throwable) {
            // Security: never expose internal error details
            return JsonResponse::error('Authentication failed.', 401);
        }

        // Attach user context to the request for downstream controllers
        $request = $request->withParams(array_merge($request->getParams(), ['auth_user' => $userContext]));

        return $next($request);
    }
}
