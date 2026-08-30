<?php

declare(strict_types=1);

namespace QRIVO\Domain\Exception;

use RuntimeException;

/**
 * Base domain exception for all QRIVO domain-layer errors.
 */
abstract class DomainException extends RuntimeException {}
