<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Logging;

use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Security\LogSanitizer;
use QRIVO\Infrastructure\Config\Config;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

/**
 * Application logger.
 *
 * Wraps Monolog with a context-aware interface.
 * Security requirements (from SECURITY_RULES.md):
 * - Must NOT log passwords, raw tokens, private keys.
 * - Must NOT log unnecessary sensitive personal data.
 */
class Logger implements LoggerInterface
{
    private MonologLogger $monolog;
    private readonly LogSanitizer $sanitizer;

    public function __construct(private readonly Config $config)
    {
        $this->monolog   = $this->createLogger();
        $this->sanitizer = new LogSanitizer();
    }

    private function createLogger(): MonologLogger
    {
        $logger  = new MonologLogger('qrivo');
        $level   = $this->resolveLevel($this->config->getString('logging.level', 'debug'));
        $logPath = $this->config->getString('logging.path', '');

        if ($logPath === '') {
            $logPath = $this->config->getBasePath() . '/storage/logs/qrivo.log';
        }

        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $maxFiles = $this->config->getInt('logging.max_files', 30);
        $logger->pushHandler(new RotatingFileHandler($logPath, $maxFiles, $level));

        return $logger;
    }

    private function resolveLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'emergency' => Level::Emergency,
            'alert'     => Level::Alert,
            'critical'  => Level::Critical,
            'error'     => Level::Error,
            'warning'   => Level::Warning,
            'notice'    => Level::Notice,
            'info'      => Level::Info,
            default     => Level::Debug,
        };
    }

    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->monolog->emergency($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->monolog->alert($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->monolog->critical($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->monolog->error($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->monolog->warning($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->monolog->notice($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->monolog->info($message, $this->sanitize($context));
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->monolog->debug($message, $this->sanitize($context));
    }

    /**
     * Sanitize the context array before it is written to the log file.
     *
     * Delegates to the shared {@see LogSanitizer} — the same pass applied to
     * persisted security events and audit logs (SECURITY_RULES.md §9): sensitive
     * keys are redacted at any depth, and token-shaped values (PEM keys, JWTs,
     * long bare hex / base64) are redacted regardless of their key.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        return $this->sanitizer->sanitize($context);
    }
}
