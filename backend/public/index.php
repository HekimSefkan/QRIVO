<?php

declare(strict_types=1);

// ─── Application Entry Point ───────────────────────────────────────────────
// All HTTP requests are routed through this file.
// Serves as the sole public-facing file for the backend.

define('QRIVO_START', microtime(true));
define('QRIVO_ROOT', dirname(__DIR__));

require_once QRIVO_ROOT . '/vendor/autoload.php';

use QRIVO\Bootstrap\App;

$app = new App(QRIVO_ROOT);
$app->run();
