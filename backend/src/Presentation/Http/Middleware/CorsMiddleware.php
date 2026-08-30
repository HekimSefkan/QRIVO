<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Middleware;

use QRIVO\Infrastructure\Config\Config;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * CORS middleware.
 *
 * Adds Cross-Origin Resource Sharing headers to all responses.
 * Handles pre-flight OPTIONS requests.
 * Allowed origins are configured via app.cors config — never hard-coded.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config) {}

    public function process(Request $request, callable $next): JsonResponse
    {
        $allowedOrigins = $this->config->get('app.cors.allowed_origins', ['*']);
        $origin         = $request->getHeader('origin') ?? '*';

        // Determine the allowed origin header value
        if (in_array('*', $allowedOrigins, true)) {
            $allowOrigin = '*';
        } elseif (in_array($origin, $allowedOrigins, true)) {
            $allowOrigin = $origin;
        } else {
            $allowOrigin = '';
        }

        $corsHeaders = [];
        if ($allowOrigin !== '') {
            $methods             = implode(', ', $this->config->get('app.cors.allowed_methods', ['GET', 'POST', 'OPTIONS']));
            $headers             = implode(', ', $this->config->get('app.cors.allowed_headers', ['Content-Type', 'Authorization']));
            $maxAge              = $this->config->getInt('app.cors.max_age', 86400);
            $corsHeaders = [
                'Access-Control-Allow-Origin'  => $allowOrigin,
                'Access-Control-Allow-Methods' => $methods,
                'Access-Control-Allow-Headers' => $headers,
                'Access-Control-Max-Age'       => (string) $maxAge,
            ];
        }

        // Handle pre-flight OPTIONS requests immediately
        if ($request->isMethod('OPTIONS')) {
            return (new JsonResponse(null, 204))->withHeaders($corsHeaders);
        }

        $response = $next($request);
        return $response->withHeaders($corsHeaders);
    }
}
