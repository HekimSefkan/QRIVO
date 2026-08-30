<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Middleware;

use PHPUnit\Framework\TestCase;
use QRIVO\Presentation\Http\Middleware\MiddlewarePipeline;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Middleware\MiddlewareInterface;

/**
 * Tests for the MiddlewarePipeline.
 */
final class MiddlewarePipelineTest extends TestCase
{
    private function makeRequest(): Request
    {
        return new Request('GET', '/api/v1/health');
    }

    public function test_pipeline_with_no_middleware_calls_handler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $called   = false;

        $response = $pipeline->process($this->makeRequest(), function (Request $req) use (&$called): JsonResponse {
            $called = true;
            return JsonResponse::success();
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_pipeline_executes_middleware_in_fifo_order(): void
    {
        $pipeline = new MiddlewarePipeline();
        $order    = [];

        $middleware1 = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(Request $request, callable $next): JsonResponse
            {
                $this->order[] = 'middleware1-before';
                $response = $next($request);
                $this->order[] = 'middleware1-after';
                return $response;
            }
        };

        $middleware2 = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(Request $request, callable $next): JsonResponse
            {
                $this->order[] = 'middleware2-before';
                $response = $next($request);
                $this->order[] = 'middleware2-after';
                return $response;
            }
        };

        $pipeline->add($middleware1);
        $pipeline->add($middleware2);

        $pipeline->process($this->makeRequest(), function (Request $req) use (&$order): JsonResponse {
            $order[] = 'handler';
            return JsonResponse::success();
        });

        $this->assertSame([
            'middleware1-before',
            'middleware2-before',
            'handler',
            'middleware2-after',
            'middleware1-after',
        ], $order);
    }

    public function test_middleware_can_short_circuit_pipeline(): void
    {
        $pipeline = new MiddlewarePipeline();
        $handlerCalled = false;

        $blocker = new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): JsonResponse
            {
                return JsonResponse::error('Blocked.', 401);
            }
        };

        $pipeline->add($blocker);

        $response = $pipeline->process($this->makeRequest(), function (Request $req) use (&$handlerCalled): JsonResponse {
            $handlerCalled = true;
            return JsonResponse::success();
        });

        $this->assertFalse($handlerCalled);
        $this->assertSame(401, $response->getStatusCode());
    }
}
