<?php

declare(strict_types=1);

// PHPUnit bootstrap — loaded before any tests run.

define('QRIVO_ROOT', dirname(__DIR__));

require_once QRIVO_ROOT . '/vendor/autoload.php';

// Load test .env if present
$envFile = QRIVO_ROOT . '/.env.testing';
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(QRIVO_ROOT, '.env.testing');
    $dotenv->safeLoad();
}
