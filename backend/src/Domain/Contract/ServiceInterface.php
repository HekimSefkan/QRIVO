<?php

declare(strict_types=1);

namespace QRIVO\Domain\Contract;

/**
 * Marker interface for application services.
 *
 * Services contain business logic.
 * They MUST NOT directly access the database — they use repositories.
 * They MUST NOT handle HTTP concerns — those belong in controllers.
 */
interface ServiceInterface {}
