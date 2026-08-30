<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http;

use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Global exception handler.
 *
 * Catches uncaught exceptions and errors.
 * Security: never exposes stack traces or internal details to the client.
 * All errors are logged server-side.
 */
final class ExceptionHandler
{
    public function __construct(private readonly Logger $logger) {}

    /**
     * Handle an uncaught Throwable.
     */
    public function handle(\Throwable $e): void
    {
        $this->logger->critical('Uncaught exception', [
            'type'    => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);

        JsonResponse::error('An internal server error occurred.', 500)->send();
    }

    /**
     * Handle a PHP error (converted to exception by error_handler).
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $this->logger->error('PHP error', [
            'errno'   => $errno,
            'message' => $errstr,
            'file'    => $errfile,
            'line'    => $errline,
        ]);
        return true;
    }
}
