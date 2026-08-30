<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

/** HTTP 404 Not Found — the requested resource does not exist. */
final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message, 404);
    }
}
