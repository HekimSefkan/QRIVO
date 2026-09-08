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
        $this->applyTimezone();
        $this->assertProductionConfiguration();
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

    /**
     * Apply the configured application timezone to PHP.
     *
     * `config/app.php` has always READ `APP_TIMEZONE`, but nothing ever called
     * date_default_timezone_set(), so the value was dead: PHP silently used its
     * php.ini default (UTC) while MySQL's own clock ran on the machine's local
     * time. That left the two halves of the application three hours apart on a
     * UTC+3 machine -- `CURRENT_TIMESTAMP` column defaults and SQL NOW() used
     * local time while every PHP-written timestamp used UTC.
     *
     * Setting it here, in the composition root before anything else runs, means
     * every `new DateTimeImmutable('now')` in the application agrees with the
     * database (see Connection::alignSessionTimezone()).
     *
     * An invalid identifier is a configuration error, not a runtime condition:
     * we fall back to UTC rather than let PHP emit a warning and carry on with
     * an unknown clock.
     */
    private function applyTimezone(): void
    {
        $tz = $this->config->getString('app.timezone', 'UTC');

        if ($tz === '' || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }

        date_default_timezone_set($tz);
    }

    /**
     * Refuse to boot with a production-unsafe configuration.
     *
     * FINAL_AUDIT F-4: `config/app.php` falls back to `*` when
     * CORS_ALLOWED_ORIGINS is unset, which lets ANY origin make credentialed
     * cross-site requests to the API. That is fine for local development and
     * unacceptable in production, and until now it was only written down in a
     * document -- nothing stopped a deployment shipping with the wildcard.
     *
     * This fails CLOSED and LOUDLY, at startup, before a single request is
     * served. A misconfigured production deployment that refuses to start is a
     * problem someone fixes in a minute; one that starts and quietly accepts
     * every origin is a problem nobody notices.
     *
     * Only `APP_ENV=production` is gated. Local, testing and staging are
     * untouched, so no developer workflow changes.
     *
     * @throws \RuntimeException when the configuration would be unsafe
     */
    private function assertProductionConfiguration(): void
    {
        if ($this->config->getString('app.env', 'local') !== 'production') {
            return;
        }

        $raw = trim((string) ($_ENV['CORS_ALLOWED_ORIGINS'] ?? ''));

        if ($raw === '') {
            throw new \RuntimeException(
                'Refusing to start: APP_ENV=production but CORS_ALLOWED_ORIGINS is not set. '
                . 'Set it to the explicit origin(s) that may call this API, e.g. '
                . 'CORS_ALLOWED_ORIGINS=https://qrivo.example.edu'
            );
        }

        // A wildcard anywhere in the list defeats the whole list.
        foreach (explode(',', $raw) as $origin) {
            if (trim($origin) === '*') {
                throw new \RuntimeException(
                    'Refusing to start: APP_ENV=production but CORS_ALLOWED_ORIGINS contains "*". '
                    . 'A wildcard lets any site issue credentialed requests to this API. '
                    . 'List the exact origin(s) instead.'
                );
            }
        }
    }
}
