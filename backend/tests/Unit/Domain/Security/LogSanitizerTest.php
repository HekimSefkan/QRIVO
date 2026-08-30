<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Domain\Security;

use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Security\LogSanitizer;

/**
 * The single redaction pass for logs, security events and audit values
 * (SECURITY_RULES.md §9 / §10 / §11).
 */
final class LogSanitizerTest extends TestCase
{
    private LogSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new LogSanitizer();
    }

    public function test_redacts_sensitive_top_level_keys(): void
    {
        $out = $this->sanitizer->sanitize([
            'password'      => 'hunter2',
            'access_token'  => 'abc',
            'refresh_token' => 'def',
            'session_secret' => 'ghi',
            'api_key'       => 'jkl',
            'authorization' => 'Bearer x',
            'private_key'   => 'mno',
        ]);

        foreach ($out as $value) {
            $this->assertSame(LogSanitizer::REDACTED, $value);
        }
    }

    public function test_key_matching_ignores_case_and_separators(): void
    {
        $out = $this->sanitizer->sanitize([
            'accessToken'   => 'a',
            'Access-Token'  => 'b',
            'ACCESS_TOKEN'  => 'c',
            'refreshTokenHash' => 'd',
            'passwordHash'  => 'e',
        ]);

        $this->assertSame(
            [LogSanitizer::REDACTED, LogSanitizer::REDACTED, LogSanitizer::REDACTED, LogSanitizer::REDACTED, LogSanitizer::REDACTED],
            array_values($out),
        );
    }

    public function test_redacts_nested_secrets_at_any_depth(): void
    {
        $out = $this->sanitizer->sanitize([
            'user' => [
                'id' => 5,
                'auth' => ['password' => 'x', 'note' => 'ok'],
            ],
            'list' => [
                ['token' => 'aaa', 'event' => 'login'],
            ],
            'credentials' => ['whatever' => 'y'],
        ]);

        $this->assertSame(5, $out['user']['id']);
        $this->assertSame(LogSanitizer::REDACTED, $out['user']['auth']['password']);
        $this->assertSame('ok', $out['user']['auth']['note']);
        $this->assertSame(LogSanitizer::REDACTED, $out['list'][0]['token']);
        $this->assertSame('login', $out['list'][0]['event']);
        // A whole subtree under a sensitive key is redacted outright.
        $this->assertSame(LogSanitizer::REDACTED, $out['credentials']);
    }

    public function test_preserves_non_sensitive_keys_and_scalars(): void
    {
        $in = [
            'email'        => 'student@example.com',
            'user_id'      => 42,
            'reason'       => 'invalid_credentials',
            'session_uuid' => '11111111-1111-1111-1111-111111111111',
            'event_type'   => 'LOGIN_FAILURE',
            'active'        => true,
            'score'         => 0,
            'nothing'       => null,
        ];

        $this->assertSame($in, $this->sanitizer->sanitize($in));
    }

    public function test_redacts_pem_private_key_value_regardless_of_key(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA...\n-----END RSA PRIVATE KEY-----";
        $out = $this->sanitizer->sanitize(['blob' => $pem]);

        $this->assertSame(LogSanitizer::REDACTED, $out['blob']);
    }

    public function test_redacts_jwt_value_regardless_of_key(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NSJ9.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $out = $this->sanitizer->sanitize(['value' => $jwt]);

        $this->assertSame(LogSanitizer::REDACTED, $out['value']);
    }

    public function test_redacts_long_bare_hex_token_value(): void
    {
        $hex = str_repeat('a1b2', 32); // 128 hex chars, like a raw access token
        $out = $this->sanitizer->sanitize(['x' => $hex]);

        $this->assertSame(LogSanitizer::REDACTED, $out['x']);
    }

    public function test_redacts_long_base64url_token_value(): void
    {
        $tok = 'QWxhZGRpbjpvcGVuc2VzYW1lQWxhZGRpbjpvcGVuc2VzYW1lQWxhZGRpbg';
        $out = $this->sanitizer->sanitize(['x' => $tok]);

        $this->assertSame(LogSanitizer::REDACTED, $out['x']);
    }

    public function test_preserves_uuid_and_short_values(): void
    {
        $in = [
            'uuid'  => '11111111-2222-3333-4444-555555555555',
            'short' => 'abc123',
            'phrase' => 'the quick brown fox jumps over the lazy dog and then some',
        ];

        $this->assertSame($in, $this->sanitizer->sanitize($in));
    }

    public function test_truncates_over_long_strings(): void
    {
        $out = $this->sanitizer->sanitize(['note' => str_repeat('x ', 2000)]);

        $this->assertStringEndsWith('…[truncated]', $out['note']);
        $this->assertLessThan(4200, strlen($out['note']));
    }

    public function test_depth_guard_stops_runaway_nesting(): void
    {
        $deep = ['v' => 'leaf'];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['child' => $deep];
        }

        // Must not overflow the stack / hang.
        $out = $this->sanitizer->sanitize($deep);
        $this->assertIsArray($out);
    }

    public function test_static_helpers_are_usable_directly(): void
    {
        $this->assertTrue(LogSanitizer::isSensitiveKey('X-Api-Key'));
        $this->assertFalse(LogSanitizer::isSensitiveKey('event_type'));
        $this->assertSame(LogSanitizer::REDACTED, LogSanitizer::sanitizeString(str_repeat('f', 64)));
        $this->assertSame('plain', LogSanitizer::sanitizeString('plain'));
    }
}
