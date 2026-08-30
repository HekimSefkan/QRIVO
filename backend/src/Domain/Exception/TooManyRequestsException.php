<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

/**
 * HTTP 429 Too Many Requests — rate limit exceeded.
 *
 * Used for login rate limiting and other throttled endpoints.
 */
final class TooManyRequestsException extends DomainException
{
    public function __construct(string $message = 'Too many requests. Please try again later.')
    {
        parent::__construct($message, 429);
    }
}
