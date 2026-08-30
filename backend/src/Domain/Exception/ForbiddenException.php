<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

/** HTTP 403 Forbidden — authenticated but not authorized. */
final class ForbiddenException extends DomainException
{
    public function __construct(string $message = 'Access denied.')
    {
        parent::__construct($message, 403);
    }
}
