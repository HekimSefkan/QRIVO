<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Middleware;

use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Middleware pipeline.
 *
 * Executes registered middleware in FIFO order around the core handler.
 * Each middleware receives the request and a "next" callable.
 */
final class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Process the request through the middleware stack and finally the handler.
     *
     * @param callable(Request): JsonResponse $handler
     */
    public function process(Request $request, callable $handler): JsonResponse
    {
        $stack = $handler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next  = $stack;
            $stack = fn(Request $req): JsonResponse => $middleware->process($req, $next);
        }

        return $stack($request);
    }
}
