<?php

declare(strict_types=1);

namespace QRIVO\Application\DTO;

/**
 * Base Data Transfer Object.
 *
 * DTOs carry data between layers without exposing domain internals.
 * They are immutable value objects — no setters, no business logic.
 *
 * Architecture (ARCHITECTURE_RULES.md §1.2):
 * DTOs are a required architectural component.
 */
abstract class BaseDTO
{
    /**
     * Convert the DTO to an associative array representation.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
