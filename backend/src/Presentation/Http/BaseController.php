<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http;

use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Base controller.
 *
 * Provides shared infrastructure for all concrete controllers:
 * - Database access
 * - Logger
 * - Config
 * - Response helpers
 *
 * Controllers are in the Presentation layer.
 * They MUST NOT contain business logic — delegate to services.
 */
abstract class BaseController
{
    public function __construct(
        protected readonly Connection $db,
        protected readonly Logger     $logger,
        protected readonly Config     $config,
    ) {}

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
