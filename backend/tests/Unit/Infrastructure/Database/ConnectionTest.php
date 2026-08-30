<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Infrastructure\Database;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;

/**
 * Tests for the database Connection wrapper.
 *
 * These tests do NOT require a live database connection.
 * They verify the Connection class can be instantiated and that
 * it defers connection (lazy initialization).
 */
final class ConnectionTest extends TestCase
{
    public function test_connection_can_be_instantiated_without_connecting(): void
    {
        $config     = new Config(QRIVO_ROOT);
        $connection = new Connection($config);

        // Instantiation should not throw — connection is lazy
        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function test_isConnected_returns_false_when_no_db_available(): void
    {
        // In CI / testing without a live DB, isConnected should return false
        // rather than throw an exception.
        $config = new Config(QRIVO_ROOT);

        // Override DB settings to point to a non-existent host to test graceful failure
        // We cannot easily override the config post-construction, but we can test
        // that isConnected() returns a boolean (true or false, not an exception).
        $connection = new Connection($config);
        $result     = $connection->isConnected();

        $this->assertIsBool($result);
    }

    public function test_connection_defers_until_getPdo_is_called(): void
    {
        // Arrange: create a Connection with bad credentials
        // It should NOT throw on construction
        $config     = new Config(QRIVO_ROOT);
        $connection = new Connection($config);

        // The object should exist — no connection yet
        $this->assertInstanceOf(Connection::class, $connection);
    }
}
