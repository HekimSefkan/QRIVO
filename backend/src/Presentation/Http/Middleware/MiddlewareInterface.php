<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Middleware;

use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Middleware contract.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): JsonResponse $next
     */
    public function process(Request $request, callable $next): JsonResponse;
}
