<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

/**
 * Thrown when request input fails validation.
 * HTTP 422 Unprocessable Entity.
 */
final class ValidationException extends DomainException
{
    /**
     * @param array<string, string[]> $errors  field => [messages]
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message, 422);
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
