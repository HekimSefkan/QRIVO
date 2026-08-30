<?php

declare(strict_types=1);

namespace QRIVO\Domain\Contract;

/**
 * Logger interface.
 *
 * Defines the logging contract used by all application and domain services.
 * Concrete implementations live in Infrastructure\Logging.
 *
 * PSR-3 aligned method signatures.
 */
interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void;
}
