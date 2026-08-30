<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Auth;

use QRIVO\Application\DTO\Auth\LoginRequestDTO;
use QRIVO\Application\Validation\Validator;
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
 * - GET  /api/v1/auth/me      (authenticated — returns the caller's own profile)
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
 * - `me` requires a valid bearer token — enforced server-side via authenticate()
 */
final class AuthController extends BaseController
{
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

        $result = $this->authServiceInstance()->login($dto);

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
            $this->authServiceInstance()->logout($rawToken, $request->getIp());
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
        $result          = $this->authServiceInstance()->refresh(
            $rawRefreshToken,
            $request->getIp(),
            (string) ($request->getHeader('user-agent') ?? ''),
        );

        return $this->success($result->toArray(), 'Token refreshed successfully.');
    }

    /**
     * GET /api/v1/auth/me
     *
     * Authorization: Bearer <access_token>
     * Returns the authenticated caller's own identity + roles. This endpoint
     * demonstrates server-side authentication enforcement: the identity comes
     * only from the validated token, never from a client-supplied id.
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $this->authenticate($request);

        return $this->success([
            'uuid'       => $actor['uuid'],
            'email'      => $actor['email'],
            'first_name' => $actor['first_name'],
            'last_name'  => $actor['last_name'],
            'roles'      => $actor['roles'],
        ], 'Authenticated.');
    }
}
