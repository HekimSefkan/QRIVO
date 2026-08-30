<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

/** HTTP 401 Unauthorized — authentication required or token invalid. */
final class UnauthorizedException extends DomainException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message, 401);
    }
}
