<?php

declare(strict_types=1);

namespace QRIVO\Domain\Security;

/**
 * The device/network identity of a single HTTP request.
 *
 * `fingerprint` is derived server-side from the User-Agent plus any stable
 * client-supplied device identifier (`X-Device-Id`) — exactly as
 * `database/docs/TABLES.md` describes for `device_sessions.device_fingerprint`
 * ("Derived from UA + device identifiers"). The client never sends the
 * fingerprint itself and it is never trusted as an authorization input — it is
 * only a signal for session binding and risk scoring (SECURITY_RULES.md §1).
 */
final class DeviceContext
{
    public function __construct(
        public readonly ?string $fingerprint,
        public readonly ?string $deviceName,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
    ) {}

    /**
     * Build the context from request-derived values. All inputs are optional —
     * a missing User-Agent and no device id simply yields a null fingerprint,
     * which disables fingerprint-based checks for that request rather than
     * failing.
     */
    public static function fromRequest(
        ?string $ipAddress,
        ?string $userAgent,
        ?string $clientDeviceId = null,
        ?string $clientDeviceName = null,
    ): self {
        $ua = self::clean($userAgent);
        $deviceId = self::clean($clientDeviceId);

        $seedParts = array_filter([$deviceId, $ua], static fn (?string $v): bool => $v !== null);
        $fingerprint = $seedParts === []
            ? null
            : hash('sha256', implode('|', $seedParts));

        $name = self::clean($clientDeviceName) ?? self::summariseUserAgent($ua);

        return new self(
            fingerprint: $fingerprint,
            deviceName: $name,
            ipAddress: self::clean($ipAddress),
            userAgent: $ua,
        );
    }

    public function isEmpty(): bool
    {
        return $this->fingerprint === null
            && $this->deviceName === null
            && $this->ipAddress === null
            && $this->userAgent === null;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0.0.0.0') {
            return null;
        }

        return mb_substr($trimmed, 0, 255);
    }

    /**
     * A short, non-identifying label for the device list. Best-effort only.
     */
    private static function summariseUserAgent(?string $ua): ?string
    {
        if ($ua === null) {
            return null;
        }

        foreach ([
            'Android'          => 'Android device',
            'iPhone'           => 'iPhone',
            'iPad'             => 'iPad',
            'Dart'             => 'QRIVO mobile app',
            'Windows'          => 'Windows',
            'Macintosh'        => 'macOS',
            'Linux'            => 'Linux',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                return $label;
            }
        }

        return 'Unknown device';
    }
}
