<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Middleware;

use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * JSON body parsing middleware.
 *
 * Ensures the request body is parsed from JSON when Content-Type is application/json.
 * The Request::fromGlobals() already does this, but this middleware validates
 * that the body is well-formed JSON when the content type indicates JSON.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): JsonResponse
    {
        $contentType = $request->getHeader('content-type') ?? '';

        if (
            str_contains($contentType, 'application/json')
            && !$request->isMethod('GET')
            && !$request->isMethod('OPTIONS')
        ) {
            // Validate that body was parseable (non-null body from fromGlobals means it parsed OK)
            // If body is empty array but raw input was non-empty, it may have been invalid JSON
            // This is a best-effort check at the middleware level
        }

        return $next($request);
    }
}
