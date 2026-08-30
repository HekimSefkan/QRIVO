<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

/**
 * HTTP 409 Conflict — the request conflicts with current state.
 *
 * Examples: duplicate attendance, concurrent session conflict.
 */
final class ConflictException extends DomainException
{
    public function __construct(string $message = 'Conflict with the current state of the resource.')
    {
        parent::__construct($message, 409);
    }
}
