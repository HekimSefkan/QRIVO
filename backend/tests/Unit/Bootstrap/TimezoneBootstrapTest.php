<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use QRIVO\Bootstrap\App;

/**
 * Regression guard for the Phase 30 timezone defect.
 *
 * `config/app.php` had always READ `APP_TIMEZONE`, but nothing ever called
 * date_default_timezone_set(). The value was dead: PHP ran on php.ini's default
 * (UTC) while the machine and MySQL ran on local time. On a UTC+3 machine that
 * put the two halves of the application three hours apart, and a lesson
 * scheduled 12:41-16:41 was evaluated as 09:41 and refused as
 * OUTSIDE_SCHEDULED_TIME.
 *
 * The boundary tests in AttendanceEligibilityServiceTest pin the comparison.
 * This pins the thing those tests assume: that `new DateTimeImmutable('now')`
 * anywhere in the application is in the CONFIGURED zone. Without this the
 * boundary tests would still pass while the running system was three hours out.
 *
 * Connection is lazily initialised, so constructing App touches no database.
 */
final class TimezoneBootstrapTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        $this->original = date_default_timezone_get();
        // Deliberately wrong, so a no-op bootstrap cannot pass by luck.
        date_default_timezone_set('America/Los_Angeles');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->original);
    }

    public function test_constructing_the_app_applies_the_configured_timezone(): void
    {
        $app = new App(QRIVO_ROOT);
        $configured = $app->getConfig()->getString('app.timezone', 'UTC');

        self::assertSame(
            $configured,
            date_default_timezone_get(),
            'Bootstrap\App must apply APP_TIMEZONE; otherwise every "now" in the app is in the wrong zone',
        );
        self::assertNotSame('America/Los_Angeles', date_default_timezone_get());
    }

    public function test_now_is_constructed_in_the_configured_zone(): void
    {
        $app = new App(QRIVO_ROOT);
        $configured = $app->getConfig()->getString('app.timezone', 'UTC');

        self::assertSame(
            $configured,
            (new \DateTimeImmutable('now'))->getTimezone()->getName(),
        );
    }
}
