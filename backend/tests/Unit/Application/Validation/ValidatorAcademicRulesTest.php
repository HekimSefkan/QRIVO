<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Exception\ValidationException;

/**
 * Covers the `date` and `integer_range` rules added for the academic module.
 */
final class ValidatorAcademicRulesTest extends TestCase
{
    public function test_date_rule_accepts_iso_date(): void
    {
        (new Validator())->validate(['d' => '2025-09-01'], ['d' => 'required|date']);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('badDates')]
    public function test_date_rule_rejects_bad_values(string $value): void
    {
        $this->expectException(ValidationException::class);
        (new Validator())->validate(['d' => $value], ['d' => 'date']);
    }

    public static function badDates(): array
    {
        return [['2025-13-01'], ['2025-02-30'], ['01-09-2025'], ['not-a-date'], ['2025/09/01']];
    }

    public function test_date_rule_skips_when_absent(): void
    {
        (new Validator())->validate([], ['d' => 'date']);
        $this->addToAssertionCount(1);
    }

    public function test_integer_range_accepts_within_bounds(): void
    {
        (new Validator())->validate(['n' => 4], ['n' => 'integer_range:1,10']);
        (new Validator())->validate(['n' => '1'], ['n' => 'integer_range:1,10']);
        $this->addToAssertionCount(1);
    }

    public function test_integer_range_rejects_out_of_bounds(): void
    {
        $this->expectException(ValidationException::class);
        (new Validator())->validate(['n' => 11], ['n' => 'integer_range:1,10']);
    }

    public function test_integer_range_rejects_non_integer(): void
    {
        $this->expectException(ValidationException::class);
        (new Validator())->validate(['n' => 'abc'], ['n' => 'integer_range:1,10']);
    }
}
