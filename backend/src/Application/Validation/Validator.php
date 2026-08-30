<?php

declare(strict_types=1);

namespace QRIVO\Application\Validation;

use QRIVO\Domain\Exception\ValidationException;

/**
 * Input validator.
 *
 * Provides rule-based validation for request data.
 * Rules are defined as an array of field => rule strings.
 *
 * Supported rules (pipe-separated):
 * - required
 * - string
 * - integer
 * - numeric
 * - boolean
 * - email
 * - min:N
 * - max:N
 * - min_length:N
 * - max_length:N
 * - in:a,b,c
 * - uuid
 * - date            (ISO calendar date, YYYY-MM-DD)
 * - time            (24h clock, HH:MM or HH:MM:SS)
 * - integer_range:min,max
 */
final class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    /**
     * Validate data against rules.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules  field => 'rule1|rule2|...'
     *
     * @throws ValidationException when validation fails
     */
    public function validate(array $data, array $rules): void
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value      = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $this->applyRule($field, $value, $rule, $data);
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException('Validation failed.', $this->errors);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyRule(string $field, mixed $value, string $rule, array $data): void
    {
        [$ruleName, $param] = array_pad(explode(':', $rule, 2), 2, null);

        switch ($ruleName) {
            case 'required':
                if ($value === null || $value === '') {
                    $this->addError($field, 'The ' . $field . ' field is required.');
                }
                break;

            case 'string':
                if ($value !== null && !is_string($value)) {
                    $this->addError($field, 'The ' . $field . ' field must be a string.');
                }
                break;

            case 'integer':
                if ($value !== null && !is_int($value) && !ctype_digit((string) $value)) {
                    $this->addError($field, 'The ' . $field . ' field must be an integer.');
                }
                break;

            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    $this->addError($field, 'The ' . $field . ' field must be numeric.');
                }
                break;

            case 'boolean':
                if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    $this->addError($field, 'The ' . $field . ' field must be a boolean.');
                }
                break;

            case 'email':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, 'The ' . $field . ' field must be a valid email address.');
                }
                break;

            case 'min':
                if ($value !== null && is_numeric($value) && (float) $value < (float) $param) {
                    $this->addError($field, 'The ' . $field . ' field must be at least ' . $param . '.');
                }
                break;

            case 'max':
                if ($value !== null && is_numeric($value) && (float) $value > (float) $param) {
                    $this->addError($field, 'The ' . $field . ' field must not exceed ' . $param . '.');
                }
                break;

            case 'min_length':
                if ($value !== null && $value !== '' && mb_strlen((string) $value) < (int) $param) {
                    $this->addError($field, 'The ' . $field . ' field must be at least ' . $param . ' characters.');
                }
                break;

            case 'max_length':
                if ($value !== null && mb_strlen((string) $value) > (int) $param) {
                    $this->addError($field, 'The ' . $field . ' field must not exceed ' . $param . ' characters.');
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $param);
                if ($value !== null && !in_array((string) $value, $allowed, true)) {
                    $this->addError($field, 'The ' . $field . ' field must be one of: ' . $param . '.');
                }
                break;

            case 'uuid':
                $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                if ($value !== null && $value !== '' && !preg_match($pattern, (string) $value)) {
                    $this->addError($field, 'The ' . $field . ' field must be a valid UUID.');
                }
                break;

            case 'date':
                if ($value !== null && $value !== '') {
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
                    if ($d === false || $d->format('Y-m-d') !== (string) $value) {
                        $this->addError($field, 'The ' . $field . ' field must be a valid date (YYYY-MM-DD).');
                    }
                }
                break;

            case 'time':
                if ($value !== null && $value !== ''
                    && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value)
                ) {
                    $this->addError($field, 'The ' . $field . ' field must be a valid 24-hour time (HH:MM).');
                }
                break;

            case 'integer_range':
                [$lo, $hi] = array_pad(explode(',', (string) $param, 2), 2, null);
                if ($value !== null && $value !== '') {
                    if (!is_int($value) && !ctype_digit((string) $value)) {
                        $this->addError($field, 'The ' . $field . ' field must be an integer.');
                    } elseif ((int) $value < (int) $lo || (int) $value > (int) $hi) {
                        $this->addError($field, 'The ' . $field . ' field must be between ' . $lo . ' and ' . $hi . '.');
                    }
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
