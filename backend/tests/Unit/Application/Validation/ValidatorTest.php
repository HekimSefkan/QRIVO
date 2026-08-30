<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Validation;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Exception\ValidationException;

/**
 * Tests for the Validator class.
 *
 * Covers all supported validation rules.
 */
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    // ─── required ──────────────────────────────────────────────────────────────

    public function test_required_passes_when_field_present(): void
    {
        $this->validator->validate(['name' => 'Alice'], ['name' => 'required']);
        $this->assertTrue(true); // no exception
    }

    public function test_required_fails_when_field_missing(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate([], ['name' => 'required']);
    }

    public function test_required_fails_when_field_empty_string(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['name' => ''], ['name' => 'required']);
    }

    public function test_required_fails_when_field_null(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['name' => null], ['name' => 'required']);
    }

    // ─── string ────────────────────────────────────────────────────────────────

    public function test_string_passes_for_string_value(): void
    {
        $this->validator->validate(['field' => 'hello'], ['field' => 'string']);
        $this->assertTrue(true);
    }

    public function test_string_fails_for_integer_value(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['field' => 42], ['field' => 'string']);
    }

    public function test_string_skips_when_value_null(): void
    {
        // optional field — null should not trigger string validation
        $this->validator->validate(['field' => null], ['field' => 'string']);
        $this->assertTrue(true);
    }

    // ─── integer ───────────────────────────────────────────────────────────────

    public function test_integer_passes_for_int_value(): void
    {
        $this->validator->validate(['age' => 25], ['age' => 'integer']);
        $this->assertTrue(true);
    }

    public function test_integer_passes_for_digit_string(): void
    {
        $this->validator->validate(['age' => '25'], ['age' => 'integer']);
        $this->assertTrue(true);
    }

    public function test_integer_fails_for_float_string(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['age' => '3.14'], ['age' => 'integer']);
    }

    // ─── numeric ───────────────────────────────────────────────────────────────

    public function test_numeric_passes_for_float(): void
    {
        $this->validator->validate(['amount' => 3.14], ['amount' => 'numeric']);
        $this->assertTrue(true);
    }

    public function test_numeric_fails_for_non_numeric_string(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['amount' => 'abc'], ['amount' => 'numeric']);
    }

    // ─── boolean ───────────────────────────────────────────────────────────────

    public function test_boolean_passes_for_true(): void
    {
        $this->validator->validate(['active' => true], ['active' => 'boolean']);
        $this->assertTrue(true);
    }

    public function test_boolean_passes_for_string_1(): void
    {
        $this->validator->validate(['active' => '1'], ['active' => 'boolean']);
        $this->assertTrue(true);
    }

    public function test_boolean_fails_for_arbitrary_string(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['active' => 'yes_please'], ['active' => 'boolean']);
    }

    // ─── email ─────────────────────────────────────────────────────────────────

    public function test_email_passes_for_valid_email(): void
    {
        $this->validator->validate(['email' => 'user@example.com'], ['email' => 'email']);
        $this->assertTrue(true);
    }

    public function test_email_fails_for_invalid_email(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['email' => 'not-an-email'], ['email' => 'email']);
    }

    public function test_email_skips_when_value_null(): void
    {
        $this->validator->validate(['email' => null], ['email' => 'email']);
        $this->assertTrue(true);
    }

    // ─── min ───────────────────────────────────────────────────────────────────

    public function test_min_passes_when_value_at_minimum(): void
    {
        $this->validator->validate(['age' => 18], ['age' => 'min:18']);
        $this->assertTrue(true);
    }

    public function test_min_fails_when_value_below_minimum(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['age' => 17], ['age' => 'min:18']);
    }

    // ─── max ───────────────────────────────────────────────────────────────────

    public function test_max_passes_when_value_at_maximum(): void
    {
        $this->validator->validate(['score' => 100], ['score' => 'max:100']);
        $this->assertTrue(true);
    }

    public function test_max_fails_when_value_exceeds_maximum(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['score' => 101], ['score' => 'max:100']);
    }

    // ─── min_length ────────────────────────────────────────────────────────────

    public function test_min_length_passes_when_sufficient_length(): void
    {
        $this->validator->validate(['password' => 'secret123'], ['password' => 'min_length:8']);
        $this->assertTrue(true);
    }

    public function test_min_length_fails_when_too_short(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['password' => 'short'], ['password' => 'min_length:8']);
    }

    // ─── max_length ────────────────────────────────────────────────────────────

    public function test_max_length_passes_when_within_length(): void
    {
        $this->validator->validate(['code' => 'AB'], ['code' => 'max_length:5']);
        $this->assertTrue(true);
    }

    public function test_max_length_fails_when_too_long(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['code' => 'TOOLONG'], ['code' => 'max_length:5']);
    }

    // ─── in ────────────────────────────────────────────────────────────────────

    public function test_in_passes_for_allowed_value(): void
    {
        $this->validator->validate(['status' => 'ACTIVE'], ['status' => 'in:ACTIVE,INACTIVE,PENDING']);
        $this->assertTrue(true);
    }

    public function test_in_fails_for_disallowed_value(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['status' => 'UNKNOWN'], ['status' => 'in:ACTIVE,INACTIVE,PENDING']);
    }

    // ─── uuid ──────────────────────────────────────────────────────────────────

    public function test_uuid_passes_for_valid_uuid(): void
    {
        $this->validator->validate(
            ['id' => '550e8400-e29b-41d4-a716-446655440000'],
            ['id' => 'uuid']
        );
        $this->assertTrue(true);
    }

    public function test_uuid_fails_for_non_uuid(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['id' => 'not-a-uuid'], ['id' => 'uuid']);
    }

    // ─── Combined rules ────────────────────────────────────────────────────────

    public function test_multiple_rules_pipe_separated(): void
    {
        $this->validator->validate(
            ['email' => 'admin@example.com'],
            ['email' => 'required|string|email']
        );
        $this->assertTrue(true);
    }

    public function test_multiple_fields_collected(): void
    {
        try {
            $this->validator->validate(
                [],
                [
                    'email'    => 'required|email',
                    'password' => 'required|min_length:8',
                ]
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('email', $errors);
            $this->assertArrayHasKey('password', $errors);
        }
    }

    public function test_validation_exception_message_and_errors(): void
    {
        try {
            $this->validator->validate(['email' => ''], ['email' => 'required']);
        } catch (ValidationException $e) {
            $this->assertSame('Validation failed.', $e->getMessage());
            $this->assertSame(422, $e->getCode());
            $this->assertNotEmpty($e->getErrors());
            return;
        }
        $this->fail('Expected ValidationException');
    }

    public function test_getErrors_returns_empty_after_successful_validation(): void
    {
        $this->validator->validate(['name' => 'Alice'], ['name' => 'required|string']);
        $this->assertEmpty($this->validator->getErrors());
    }
}
