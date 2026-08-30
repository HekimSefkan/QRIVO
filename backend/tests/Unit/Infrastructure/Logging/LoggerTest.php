<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Logging\Logger;

/**
 * Tests for the Logger class.
 *
 * These tests ensure the logger initializes correctly and that
 * the sanitize mechanism prevents sensitive data from being logged.
 * We test through the public interface without reading log files.
 */
final class LoggerTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        // phpunit.xml sets LOG_LEVEL=error so the logger writes to storage/logs
        $config       = new Config(QRIVO_ROOT);
        $this->logger = new Logger($config);
    }

    public function test_logger_can_be_instantiated(): void
    {
        $this->assertInstanceOf(Logger::class, $this->logger);
    }

    public function test_info_does_not_throw(): void
    {
        $this->logger->info('Test info message', ['key' => 'value']);
        $this->assertTrue(true);
    }

    public function test_debug_does_not_throw(): void
    {
        $this->logger->debug('Test debug message');
        $this->assertTrue(true);
    }

    public function test_warning_does_not_throw(): void
    {
        $this->logger->warning('Test warning');
        $this->assertTrue(true);
    }

    public function test_error_does_not_throw(): void
    {
        $this->logger->error('Test error', ['code' => 500]);
        $this->assertTrue(true);
    }

    public function test_critical_does_not_throw(): void
    {
        $this->logger->critical('Test critical');
        $this->assertTrue(true);
    }

    public function test_logger_log_directory_is_created(): void
    {
        $logDir = QRIVO_ROOT . '/storage/logs';
        $this->assertDirectoryExists($logDir);
    }

    /**
     * Security test: ensure sensitive keys are never written to the log file.
     *
     * We verify this by testing that the logger accepts these keys without
     * throwing (the sanitize method redacts them internally).
     */
    public function test_sensitive_keys_are_accepted_without_exception(): void
    {
        // The logger must not throw when sensitive keys are passed —
        // it should silently redact them.
        $this->logger->info('Test with sensitive context', [
            'password'      => 'plaintext_password',
            'token'         => 'raw_jwt_token',
            'access_token'  => 'access_token_value',
            'refresh_token' => 'refresh_token_value',
            'secret'        => 'some_secret',
            'api_key'       => 'api_key_value',
            'private_key'   => 'private_key_pem',
            'authorization' => 'Bearer some_jwt',
        ]);

        // If we reach here, the logger handled sensitive keys without throwing.
        $this->assertTrue(true);
    }

    /**
     * The logger delegates to the shared LogSanitizer — sensitive keys and
     * token-shaped values are redacted at any depth before they reach Monolog.
     */
    public function test_sanitize_redacts_via_shared_sanitizer(): void
    {
        $method = new \ReflectionMethod($this->logger, 'sanitize');
        $method->setAccessible(true);

        $out = $method->invoke($this->logger, [
            'user_id' => 7,
            'password' => 'plaintext',
            'meta' => ['access_token' => 'abc', 'ok' => 1],
            'blob' => str_repeat('f', 64),
        ]);

        $this->assertSame(7, $out['user_id']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('[REDACTED]', $out['meta']['access_token']);
        $this->assertSame(1, $out['meta']['ok']);
        $this->assertSame('[REDACTED]', $out['blob']);
    }
}
