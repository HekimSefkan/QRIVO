<?php

declare(strict_types=1);

namespace QRIVO\Domain\Attendance;

use QRIVO\Domain\Enum\QrValidationReason;

/**
 * Immutable result of validating a scanned QR payload.
 *
 * On failure the payload is exposed only when it parsed (for logging context);
 * never any session secret.
 */
final class QrValidationResult
{
    private function __construct(
        public readonly QrValidationReason $reason,
        public readonly ?QrPayload $payload = null,
        public readonly ?int $sessionId = null,
    ) {}

    public static function valid(QrPayload $payload, int $sessionId): self
    {
        return new self(QrValidationReason::VALID, $payload, $sessionId);
    }

    public static function invalid(QrValidationReason $reason, ?QrPayload $payload = null): self
    {
        return new self($reason, $payload);
    }

    public function isValid(): bool
    {
        return $this->reason->isValid();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid'        => $this->isValid(),
            'reason'       => $this->reason->value,
            'session_uuid' => $this->payload?->sessionUuid,
        ];
    }
}
