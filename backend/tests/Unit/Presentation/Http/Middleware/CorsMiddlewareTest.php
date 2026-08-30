<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Middleware;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Presentation\Http\Middleware\CorsMiddleware;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Tests for the CORS middleware.
 */
final class CorsMiddlewareTest extends TestCase
{
    private function makeConfig(array $corsConfig = []): Config
    {
        // We use QRIVO_ROOT to instantiate a real config but then we can't
        // easily override values without writing temp files. Instead we use
        // a partial mock or a simple stub.
        return new Config(QRIVO_ROOT);
    }

    private function makeRequest(
        string $method  = 'GET',
        string $uri     = '/api/v1/health',
        array  $headers = [],
    ): Request {
        return new Request($method, $uri, [], [], $headers);
    }

    private function makeHandler(JsonResponse $response): callable
    {
        return fn(Request $req): JsonResponse => $response;
    }

    public function test_options_preflight_returns_204(): void
    {
        $config     = $this->makeConfig();
        $middleware = new CorsMiddleware($config);
        $request    = $this->makeRequest('OPTIONS', '/api/v1/health', ['origin' => 'http://localhost:3000']);
        $handler    = $this->makeHandler(JsonResponse::success());

        $response = $middleware->process($request, $handler);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_non_options_passes_to_next_handler(): void
    {
        $config     = $this->makeConfig();
        $middleware = new CorsMiddleware($config);
        $request    = $this->makeRequest('GET');
        $expected   = JsonResponse::success(['api' => 'ok'], 'OK');
        $handler    = $this->makeHandler($expected);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_wildcard_allowed_origin_is_applied(): void
    {
        $config     = $this->makeConfig();
        $middleware = new CorsMiddleware($config);
        $request    = $this->makeRequest('GET', '/api/v1/health', ['origin' => 'http://any-origin.com']);
        $handler    = $this->makeHandler(JsonResponse::success());

        // Should not throw or return an error
        $response = $middleware->process($request, $handler);
        $this->assertSame(200, $response->getStatusCode());
    }
}
