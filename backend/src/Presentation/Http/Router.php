<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http;

use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Response\JsonResponse;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * HTTP Router using FastRoute.
 *
 * Routes are defined in routes/api.php.
 * Dispatches to controller methods.
 * Handles 404/405 responses.
 */
final class Router
{
    private Dispatcher $dispatcher;

    public function __construct(private readonly string $basePath)
    {
        $this->dispatcher = $this->buildDispatcher();
    }

    private function buildDispatcher(): Dispatcher
    {
        return simpleDispatcher(function (RouteCollector $r) {
            $routes = $this->basePath . '/routes/api.php';
            if (file_exists($routes)) {
                require $routes;
            }
        });
    }

    /**
     * Dispatch the incoming request to the appropriate controller method.
     */
    public function dispatch(
        Request    $request,
        Connection $db,
        Logger     $logger,
        Config     $config,
    ): JsonResponse {
        $routeInfo = $this->dispatcher->dispatch(
            $request->getMethod(),
            $request->getUri(),
        );

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND    => JsonResponse::error('Endpoint not found.', 404),
            Dispatcher::METHOD_NOT_ALLOWED => JsonResponse::error('Method not allowed.', 405),
            Dispatcher::FOUND        => $this->callRoute($routeInfo, $request, $db, $logger, $config),
        };
    }

    /**
     * @param array<mixed> $routeInfo
     */
    private function callRoute(
        array      $routeInfo,
        Request    $request,
        Connection $db,
        Logger     $logger,
        Config     $config,
    ): JsonResponse {
        [, $handler, $vars] = $routeInfo;

        $request = $request->withParams($vars);

        [$controllerClass, $method] = is_array($handler)
            ? $handler
            : explode('@', $handler);

        try {
            $controller = new $controllerClass($db, $logger, $config);
            return $controller->$method($request);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($e->getMessage(), $e->getErrors());
        } catch (UnauthorizedException $e) {
            return JsonResponse::error($e->getMessage(), 401);
        } catch (ForbiddenException $e) {
            return JsonResponse::error($e->getMessage(), 403);
        } catch (NotFoundException $e) {
            return JsonResponse::error($e->getMessage(), 404);
        } catch (ConflictException $e) {
            return JsonResponse::error($e->getMessage(), 409);
        } catch (TooManyRequestsException $e) {
            return JsonResponse::error($e->getMessage(), 429);
        } catch (\Throwable $e) {
            $logger->error('Unhandled controller exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            // Security: never expose internal error details to clients
            return JsonResponse::error('An internal server error occurred.', 500);
        }
    }
}
