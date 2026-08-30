<?php

declare(strict_types=1);

namespace QRIVO\Bootstrap;

use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\ExceptionHandler;
use QRIVO\Presentation\Http\Middleware\MiddlewarePipeline;
use QRIVO\Presentation\Http\Middleware\CorsMiddleware;
use QRIVO\Presentation\Http\Middleware\JsonBodyMiddleware;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use Dotenv\Dotenv;

/**
 * Application bootstrap class.
 *
 * Responsibilities:
 * - Load environment variables
 * - Initialize configuration
 * - Initialize logging
 * - Initialize database connection
 * - Build middleware pipeline
 * - Build router
 * - Handle incoming HTTP request
 * - Dispatch to controller
 * - Send response
 */
final class App
{
    private Config $config;
    private Logger $logger;
    private Connection $db;
    private Router $router;
    private MiddlewarePipeline $pipeline;
    private ExceptionHandler $exceptionHandler;

    public function __construct(private readonly string $basePath)
    {
        $this->loadEnvironment();
        $this->config           = new Config($this->basePath);
        $this->logger           = new Logger($this->config);
        $this->exceptionHandler = new ExceptionHandler($this->logger);
        $this->db               = new Connection($this->config);
        $this->router           = new Router($this->basePath);
        $this->pipeline         = new MiddlewarePipeline();
        $this->registerMiddleware();
    }

    /**
     * Load .env from the backend directory.
     * Skips gracefully when .env is absent (production may use system env vars).
     */
    private function loadEnvironment(): void
    {
        $envFile = $this->basePath . '/.env';

        if (file_exists($envFile)) {
            $dotenv = Dotenv::createImmutable($this->basePath);
            $dotenv->safeLoad();
        }
    }

    /**
     * Register middleware in execution order.
     * Order: CORS → JSON body parsing → (authentication middleware added per-route in later phases)
     */
    private function registerMiddleware(): void
    {
        $this->pipeline->add(new CorsMiddleware($this->config));
        $this->pipeline->add(new JsonBodyMiddleware());
    }

    /**
     * Run the application: handle the request, dispatch, send response.
     */
    public function run(): void
    {
        set_exception_handler([$this->exceptionHandler, 'handle']);
        set_error_handler([$this->exceptionHandler, 'handleError']);

        $request  = Request::fromGlobals();
        $response = $this->pipeline->process($request, function (Request $req): JsonResponse {
            return $this->router->dispatch($req, $this->db, $this->logger, $this->config);
        });

        $response->send();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getDb(): Connection
    {
        return $this->db;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }
}
