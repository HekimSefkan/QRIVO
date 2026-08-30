<?php

declare(strict_types=1);

namespace QRIVO\Application\Service;

use QRIVO\Domain\Contract\LoggerInterface;

/**
 * Base service providing shared infrastructure for all application services.
 *
 * Responsibilities:
 * - Provide logger access
 * - Common service utilities
 *
 * Services contain business logic.
 * They do NOT directly access the database — they use repositories.
 * They do NOT handle HTTP concerns — those belong in controllers.
 */
abstract class BaseService
{
    public function __construct(protected readonly LoggerInterface $logger) {}
}
