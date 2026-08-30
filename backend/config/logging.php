<?php

declare(strict_types=1);

return [
    'channel'   => $_ENV['LOG_CHANNEL']   ?? 'file',
    'level'     => $_ENV['LOG_LEVEL']     ?? 'debug',
    'path'      => $_ENV['LOG_PATH']      ?? null, // null = auto (storage/logs/qrivo.log)
    'max_files' => (int) ($_ENV['LOG_MAX_FILES'] ?? 30),
];
