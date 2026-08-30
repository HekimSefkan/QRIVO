<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Report;

use QRIVO\Application\Service\BaseService;
use QRIVO\Domain\Enum\AttendanceSource;
use QRIVO\Domain\Enum\AttendanceStatus;
use QRIVO\Domain\Enum\SessionStatus;
use QRIVO\Domain\Exception\ValidationException;

/**
 * Shared request-query parsing / validation for the attendance reports
 * (PROJECT_SPECIFICATION.md §6.16 — "pagination and filtering where required").
 *
 * Only whitelisted filters survive; ids must be positive integers; enum filters
 * must be valid; dates are normalised to a full `Y-m-d H:i:s` bound. Anything
 * else is a 422.
 */
abstract class AbstractReportService extends BaseService
{
    private const ID_FILTERS = [
        'school_id', 'faculty_id', 'department_id', 'program_id',
        'course_id', 'class_id', 'academic_term_id', 'teacher_id', 'student_id',
    ];

    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 25;

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $allowed  filter keys this report accepts
     * @return array{filters: array<string, mixed>, page: int, per_page: int}
     */
    protected function parseQuery(array $query, array $allowed): array
    {
        $errors  = [];
        $filters = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $query) || $query[$key] === '' || $query[$key] === null) {
                continue;
            }
            $value = $query[$key];

            if (in_array($key, self::ID_FILTERS, true)) {
                if (!is_numeric($value) || (int) $value < 1) {
                    $errors[$key][] = 'Must be a positive integer.';
                    continue;
                }
                $filters[$key] = (int) $value;
                continue;
            }

            $filters[$key] = match ($key) {
                'status'         => $this->validateEnum($value, AttendanceStatus::class, $key, $errors),
                'source'         => $this->validateEnum($value, AttendanceSource::class, $key, $errors),
                'session_status' => $this->validateEnum($value, SessionStatus::class, $key, $errors),
                'from'           => $this->validateDate($value, $key, $errors, endOfDay: false),
                'to'             => $this->validateDate($value, $key, $errors, endOfDay: true),
                default          => (string) $value,
            };
        }

        // Drop keys that failed validation (null return).
        $filters = array_filter($filters, static fn ($v): bool => $v !== null);

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        if ($errors !== []) {
            throw new ValidationException('Invalid report filters.', $errors);
        }

        return ['filters' => $filters, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param class-string $enumClass
     * @param array<string, list<string>> $errors
     */
    private function validateEnum(mixed $value, string $enumClass, string $key, array &$errors): ?string
    {
        $case = $enumClass::tryFrom((string) $value);
        if ($case === null) {
            $errors[$key][] = 'Not a recognised value.';
            return null;
        }

        return $case->value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function validateDate(mixed $value, string $key, array &$errors, bool $endOfDay): ?string
    {
        $raw = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) === 1) {
            return str_replace('T', ' ', $raw) . (strlen($raw) === 16 ? ':00' : '');
        }

        $errors[$key][] = 'Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.';

        return null;
    }

    /**
     * @return array<string, int>
     */
    protected function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }
}
