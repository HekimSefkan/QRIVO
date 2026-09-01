<?php

declare(strict_types=1);

/**
 * QRIVO — migration runner.
 *
 *   php scripts/migrate.php            apply every pending migration
 *   php scripts/migrate.php --status   list applied / pending, change nothing
 *   php scripts/migrate.php --fresh    DROP the database, recreate it, re-apply
 *
 * Behaviour:
 *   - reads DB settings from backend/.env through the existing Config class
 *   - creates the database if absent (utf8mb4 / utf8mb4_unicode_ci)
 *   - applies database/migrations/*.sql in filename order
 *   - records each applied file in `schema_migrations`, so re-running is a no-op
 *
 * Transactions: each file is wrapped in a transaction. Note that MySQL performs
 * an IMPLICIT COMMIT on every DDL statement (CREATE TABLE, etc.), so the wrapper
 * gives true all-or-nothing behaviour only for data-only migrations (002, 008)
 * and for the ledger write. For DDL files a failure mid-file leaves the earlier
 * statements applied and the file NOT recorded as applied — re-running is safe
 * because every migration uses `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`.
 */

require_once __DIR__ . '/_cli.php';

use QRIVO\Infrastructure\Database\SqlScriptSplitter;

$args    = array_slice($argv, 1);
$status  = in_array('--status', $args, true);
$fresh   = in_array('--fresh', $args, true);
$config  = qrivo_config();
$dbName  = $config->getString('database.database', 'qrivo');
$dir     = QRIVO_ROOT . '/../database/migrations';

qrivo_heading('QRIVO migrations');
qrivo_line('  database : ' . $dbName . ' @ ' . $config->getString('database.host', '127.0.0.1') . ':' . $config->getInt('database.port', 3306));
qrivo_line('  env      : ' . $config->getString('app.env', 'production'));
qrivo_line('  source   : database/migrations/');
qrivo_line();

if (!is_dir($dir)) {
    qrivo_abort("Migration directory not found: {$dir}");
}

// ─── --fresh : drop and recreate (local only, destructive) ──────────────────

$server = qrivo_server_pdo();
$quoted = '`' . str_replace('`', '``', $dbName) . '`';

if ($fresh) {
    qrivo_require_local_env('migrate.php --fresh');
    qrivo_info("Dropping database {$dbName} (--fresh)");
    $server->exec("DROP DATABASE IF EXISTS {$quoted}");
    qrivo_ok("Dropped {$dbName}");
}

// ─── create the database if it does not exist ───────────────────────────────

$existed = (bool) $server->query(
    "SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $server->quote($dbName)
)->fetchColumn();

if ($existed) {
    qrivo_skip("Database {$dbName} already exists");
} else {
    $server->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    qrivo_ok("Created database {$dbName} (utf8mb4 / utf8mb4_unicode_ci)");
}

$pdo = qrivo_database_pdo();

// ─── ensure the ledger exists before anything is recorded ───────────────────
// (000_create_schema_migrations.sql creates the same table; this bootstrap makes
//  the runner work on a database that has never been touched.)

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `filename`    VARCHAR(255)  NOT NULL,
        `checksum`    CHAR(64)      NOT NULL,
        `statements`  INT UNSIGNED  NOT NULL DEFAULT 0,
        `duration_ms` INT UNSIGNED  NOT NULL DEFAULT 0,
        `applied_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_schema_migrations_filename` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

/** @var array<string, array<string, mixed>> $applied filename => row */
$applied = [];
foreach ($pdo->query('SELECT `filename`, `checksum`, `applied_at` FROM `schema_migrations`') as $row) {
    $applied[(string) $row['filename']] = $row;
}

$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    qrivo_abort("No .sql files found in {$dir}");
}

// ─── --status : report only ─────────────────────────────────────────────────

if ($status) {
    qrivo_line();
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            $drift = !hash_equals((string) $applied[$name]['checksum'], hash_file('sha256', $file));
            $note  = $drift ? qrivo_paint('  (file changed since it was applied)', 'yellow') : '';
            qrivo_ok(str_pad($name, 42) . 'applied ' . $applied[$name]['applied_at'] . $note);
        } else {
            qrivo_line(qrivo_paint(' PEND  ', 'yellow') . str_pad($name, 42) . 'not applied');
        }
    }
    $pending = count($files) - count(array_intersect_key($applied, array_flip(array_map('basename', $files))));
    qrivo_line();
    qrivo_line('  ' . count($files) . ' migration(s), ' . $pending . ' pending.');
    qrivo_line();
    exit(0);
}

// ─── apply ──────────────────────────────────────────────────────────────────

$appliedNow = 0;
$skipped    = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql  = file_get_contents($file);

    if ($sql === false) {
        qrivo_abort("Cannot read {$name}");
    }

    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        $drift = !hash_equals((string) $applied[$name]['checksum'], $checksum);
        qrivo_skip(str_pad($name, 42) . 'already applied' . ($drift ? qrivo_paint('  ← file changed since; not re-run', 'yellow') : ''));
        $skipped++;
        continue;
    }

    $statements = SqlScriptSplitter::split($sql);
    $started    = microtime(true);

    $pdo->beginTransaction();

    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        $insert = $pdo->prepare(
            'INSERT INTO `schema_migrations` (`filename`, `checksum`, `statements`, `duration_ms`, `applied_at`)
             VALUES (:f, :c, :s, :d, :a)'
        );
        $insert->execute([
            'f' => $name,
            'c' => $checksum,
            's' => count($statements),
            'd' => $duration,
            'a' => date('Y-m-d H:i:s'),
        ]);

        // DDL already auto-committed; this commits the ledger row (and any
        // data-only statements) and closes the transaction cleanly.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        qrivo_ok(str_pad($name, 42) . str_pad((string) count($statements) . ' statement(s)', 18) . $duration . ' ms');
        $appliedNow++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        qrivo_line();
        qrivo_fail("{$name} failed and was NOT recorded as applied.");
        qrivo_line('       ' . $e->getMessage());
        qrivo_line();
        qrivo_line('       Migrations are idempotent (CREATE TABLE IF NOT EXISTS / INSERT IGNORE),');
        qrivo_line('       so fix the cause and re-run `php scripts/migrate.php`.');
        qrivo_line();
        exit(1);
    }
}

qrivo_line();
qrivo_line('  ' . qrivo_paint((string) $appliedNow . ' applied', 'green') . ', ' . $skipped . ' already up to date.');

$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
qrivo_line('  ' . $tables . ' table(s) in ' . $dbName . '.');
qrivo_line();
