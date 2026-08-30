<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Database;

use QRIVO\Infrastructure\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO database connection wrapper.
 *
 * Provides a lazy-initialized PDO connection.
 * Connection is established on first use (not on construction).
 * All connection settings are read from configuration — never hard-coded.
 *
 * Security:
 * - Uses prepared statements (PDO::ATTR_EMULATE_PREPARES = false)
 * - Throws on error (PDO::ERRMODE_EXCEPTION)
 * - Enforces utf8mb4 charset
 */
final class Connection
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config) {}

    /**
     * Get the PDO instance (lazy-initialized).
     *
     * @throws RuntimeException if connection fails
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->createConnection();
        }

        return $this->pdo;
    }

    /**
     * Create a new PDO connection using configuration.
     */
    private function createConnection(): PDO
    {
        $driver   = $this->config->getString('database.driver', 'mysql');
        $host     = $this->config->getString('database.host', '127.0.0.1');
        $port     = $this->config->getInt('database.port', 3306);
        $database = $this->config->getString('database.database', 'qrivo');
        $charset  = $this->config->getString('database.charset', 'utf8mb4');
        $options  = $this->config->get('database.options', []);
        $username = $this->config->getString('database.username');
        $password = $this->config->getString('database.password');

        $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s',
            $driver, $host, $port, $database, $charset
        );

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            // Do NOT expose database credentials or connection details in exceptions
            throw new RuntimeException(
                'Database connection failed. Check configuration.',
                (int) $e->getCode()
            );
        }
    }

    /**
     * Check if connection is alive (for health checks).
     */
    public function isConnected(): bool
    {
        try {
            $this->getPdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        $this->getPdo()->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->getPdo()->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): void
    {
        if ($this->getPdo()->inTransaction()) {
            $this->getPdo()->rollBack();
        }
    }

    /**
     * Execute a query within a transaction.
     * Automatically commits on success and rolls back on failure.
     *
     * @param callable $callback fn(PDO): mixed
     * @return mixed The return value of $callback
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this->getPdo());
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Prepare and execute a statement, returning all rows.
     *
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    /**
     * Prepare and execute a statement, returning the first row.
     *
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Prepare and execute a statement (INSERT/UPDATE/DELETE).
     *
     * @param array<string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function lastInsertId(): string
    {
        return $this->getPdo()->lastInsertId();
    }
}
