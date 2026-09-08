<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use QRIVO\Bootstrap\App;
use RuntimeException;

/**
 * FINAL_AUDIT F-4 — a production deployment must not start with wildcard CORS.
 *
 * `config/app.php` falls back to `*` when CORS_ALLOWED_ORIGINS is unset, which
 * lets ANY origin make credentialed cross-site requests. That was documented in
 * docs/DEPLOYMENT.md but nothing enforced it, so a deployment could ship with
 * the wildcard and nobody would notice.
 *
 * The guard fails closed at startup. These tests pin both halves of it: that it
 * refuses the unsafe configurations, and — just as important — that it does NOT
 * interfere with local development, which would be the obvious way for someone
 * to be tempted to remove it.
 *
 * Connection is lazily initialised, so constructing App touches no database.
 */
final class ProductionCorsGuardTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'CORS_ALLOWED_ORIGINS'] as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    // NOTE ON THE "UNSET" CASE — deliberately not tested here.
    //
    // It cannot be simulated. App::__construct() calls loadEnvironment() before
    // the guard runs, and backend/.env supplies CORS_ALLOWED_ORIGINS on any
    // developer machine, so unsetting it in a test is immediately undone.
    //
    // The guard treats unset and empty identically: both reach the same
    // `$raw === ''` branch, which the test below exercises. Faking the unset
    // case by reaching past loadEnvironment() would be testing a code path that
    // does not exist in production.

    public function test_production_with_empty_cors_refuses_to_start(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['CORS_ALLOWED_ORIGINS'] = '   ';

        $this->expectException(RuntimeException::class);

        new App(QRIVO_ROOT);
    }

    public function test_production_with_wildcard_refuses_to_start(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/contains "\*"/');

        new App(QRIVO_ROOT);
    }

    public function test_a_wildcard_hidden_in_a_list_still_refuses(): void
    {
        // The dangerous case: it *looks* configured, and one entry undoes it all.
        $_ENV['APP_ENV'] = 'production';
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://qrivo.example.edu, * ,https://other.example';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/contains "\*"/');

        new App(QRIVO_ROOT);
    }

    public function test_production_with_explicit_origins_starts_normally(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://qrivo.example.edu';

        $app = new App(QRIVO_ROOT);

        self::assertSame('production', $app->getConfig()->getString('app.env', ''));
    }

    public function test_local_development_is_untouched_even_with_a_wildcard(): void
    {
        // If the guard fired here it would break every developer's setup, and
        // the pressure would be to delete it rather than configure production.
        $_ENV['APP_ENV'] = 'local';
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';

        $app = new App(QRIVO_ROOT);

        self::assertSame('local', $app->getConfig()->getString('app.env', ''));
    }

    public function test_testing_environment_is_untouched(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        unset($_ENV['CORS_ALLOWED_ORIGINS']);

        $app = new App(QRIVO_ROOT);

        self::assertSame('testing', $app->getConfig()->getString('app.env', ''));
    }
}
