<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the QRIVO CLI scripts (migrate / seed / smoke test).
 *
 * Mirrors what QRIVO\Bootstrap\App does for HTTP requests — loads `.env`, then
 * builds the existing Config object — so the scripts read exactly the same
 * settings the running application does. No settings are duplicated here.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("These scripts may only be run from the command line.\n");
}

define('QRIVO_ROOT', dirname(__DIR__));

require_once QRIVO_ROOT . '/vendor/autoload.php';

use QRIVO\Infrastructure\Config\Config;

/**
 * Load backend/.env exactly like Bootstrap\App::loadEnvironment().
 */
function qrivo_load_env(): void
{
    if (file_exists(QRIVO_ROOT . '/.env')) {
        Dotenv\Dotenv::createImmutable(QRIVO_ROOT)->safeLoad();
    }
}

function qrivo_config(): Config
{
    static $config = null;

    if ($config === null) {
        $config = new Config(QRIVO_ROOT);

        // Same reason as BootstrapApp::applyTimezone(): the CLI scripts do not
        // go through the composition root, so without this they would run on
        // php.ini's default (UTC) while the web app runs on Europe/Istanbul --
        // and seed.php would compute the demo lesson window in the wrong zone.
        $tz = $config->getString('app.timezone', 'UTC');
        if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        date_default_timezone_set($tz);
    }

    return $config;
}

// ─── console helpers ────────────────────────────────────────────────────────

function qrivo_supports_colour(): bool
{
    static $supported = null;

    if ($supported === null) {
        $supported = (getenv('NO_COLOR') === false)
            && (DIRECTORY_SEPARATOR !== '\\' || getenv('ANSICON') !== false || getenv('WT_SESSION') !== false || getenv('TERM') !== false);
    }

    return $supported;
}

function qrivo_paint(string $text, string $colour): string
{
    if (!qrivo_supports_colour()) {
        return $text;
    }

    $codes = ['red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'cyan' => '0;36', 'grey' => '0;90', 'bold' => '1'];

    return "\033[" . ($codes[$colour] ?? '0') . 'm' . $text . "\033[0m";
}

function qrivo_line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

function qrivo_ok(string $text): void
{
    qrivo_line(qrivo_paint('  OK   ', 'green') . $text);
}

function qrivo_skip(string $text): void
{
    qrivo_line(qrivo_paint(' SKIP  ', 'grey') . $text);
}

function qrivo_info(string $text): void
{
    qrivo_line(qrivo_paint('  ..   ', 'cyan') . $text);
}

function qrivo_fail(string $text): void
{
    qrivo_line(qrivo_paint(' FAIL  ', 'red') . $text);
}

function qrivo_heading(string $text): void
{
    qrivo_line();
    qrivo_line(qrivo_paint($text, 'bold'));
    qrivo_line(str_repeat('─', max(8, min(72, strlen($text)))));
}

/**
 * Abort with a message and a non-zero exit code.
 */
function qrivo_abort(string $message, int $code = 1): never
{
    qrivo_line();
    qrivo_fail($message);
    qrivo_line();
    exit($code);
}

/**
 * Guard: destructive / demo-data scripts must never touch a non-local install.
 */
function qrivo_require_local_env(string $scriptName): void
{
    $env = qrivo_config()->getString('app.env', 'production');

    if ($env !== 'local') {
        qrivo_abort(
            "{$scriptName} refuses to run: APP_ENV is '{$env}', expected 'local'." . PHP_EOL
            . '         This script creates demo accounts and must never run against a shared'
            . PHP_EOL . '         or production database. Set APP_ENV=local in backend/.env if this is'
            . PHP_EOL . '         your development machine.'
        );
    }
}

/**
 * A PDO connection to the MySQL SERVER (no database selected) — needed to
 * create the schema itself. Uses the same credentials as the application.
 */
function qrivo_server_pdo(): PDO
{
    $config = qrivo_config();

    $dsn = sprintf(
        '%s:host=%s;port=%d;charset=%s',
        $config->getString('database.driver', 'mysql'),
        $config->getString('database.host', '127.0.0.1'),
        $config->getInt('database.port', 3306),
        $config->getString('database.charset', 'utf8mb4'),
    );

    try {
        return new PDO(
            $dsn,
            $config->getString('database.username'),
            $config->getString('database.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    } catch (PDOException $e) {
        qrivo_abort(
            'Cannot reach the database server at '
            . $config->getString('database.host', '127.0.0.1') . ':' . $config->getInt('database.port', 3306)
            . PHP_EOL . '         Is MySQL running? Check DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD in backend/.env.'
            . PHP_EOL . '         (' . $e->getMessage() . ')'
        );
    }
}

/**
 * A PDO connection to the QRIVO database itself.
 */
function qrivo_database_pdo(): PDO
{
    $config = qrivo_config();
    $name   = $config->getString('database.database', 'qrivo');

    $dsn = sprintf(
        '%s:host=%s;port=%d;dbname=%s;charset=%s',
        $config->getString('database.driver', 'mysql'),
        $config->getString('database.host', '127.0.0.1'),
        $config->getInt('database.port', 3306),
        $name,
        $config->getString('database.charset', 'utf8mb4'),
    );

    try {
        $pdo = new PDO(
            $dsn,
            $config->getString('database.username'),
            $config->getString('database.password'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );

        // Match Connection::alignSessionTimezone() so seed.php computes the demo
        // lesson window against the same clock the API will evaluate it with.
        if ($config->getString('database.driver', 'mysql') === 'mysql') {
            $pdo->prepare('SET time_zone = :offset')
                ->execute(['offset' => (new DateTimeImmutable('now'))->format('P')]);
        }

        return $pdo;
    } catch (PDOException $e) {
        qrivo_abort(
            "Cannot open database '{$name}'. Run `php scripts/migrate.php` first."
            . PHP_EOL . '         (' . $e->getMessage() . ')'
        );
    }
}

qrivo_load_env();

// Resolve configuration immediately on include. This is what APPLIES
// APP_TIMEZONE (see qrivo_config()), and it must happen before any script reads
// the clock -- smoke_test.php calls date('H:i:s') to find the live schedule
// slot, and with a lazy timezone it read UTC while the schedule was stored in
// local wall-clock time, so it found no covering slot.
qrivo_config();
