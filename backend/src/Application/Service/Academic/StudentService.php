<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\Student;
use QRIVO\Domain\Enum\UserRole;

/**
 * Student profile management.
 *
 * A student profile links an existing `users` account to a program. User account
 * provisioning is out of scope here (OQ-004): the user must already exist, be
 * active and approved. On creation the non-privileged STUDENT role is attached
 * to the account (an admin action, audited).
 */
final class StudentService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'student';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'user_id'         => 'required|integer',
            'program_id'      => 'required|integer',
            'student_number'  => 'required|string|min_length:1|max_length:50',
            'enrollment_year' => 'required|integer_range:1950,2100',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['program_id' => 'program_id', 'user_id' => 'user_id', 'enrollment_year' => 'enrollment_year'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate) {
            $data['user_id'] = (int) ($input['user_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('program_id', $input)) {
            $data['program_id'] = (int) ($input['program_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('student_number', $input)) {
            $data['student_number'] = trim((string) ($input['student_number'] ?? ''));
        }
        if ($isCreate || array_key_exists('enrollment_year', $input)) {
            $data['enrollment_year'] = (int) ($input['enrollment_year'] ?? 0);
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return Student::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('program_id', 'programs', $data['program_id'] ?? null);

        if ($existing === null) {
            $userId = (int) ($data['user_id'] ?? 0);
            if (!$this->reference->userIsUsable($userId)) {
                $this->fail('user_id', 'The user_id must reference an existing, active, approved user account.');
            }
            if ($this->reference->userHasProfile('students', $userId)) {
                $this->fail('user_id', 'This user already has a student profile.');
            }
        }

        if (isset($data['student_number'])
            && $this->repo->existsActiveMatching(['student_number' => $data['student_number']], $existing['id'] ?? null)
        ) {
            $this->fail('student_number', 'This student number is already in use.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     */
    protected function afterCreate(int $id, array $data, array $actor): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $this->reference->ensureUserRole($userId, UserRole::STUDENT->value);

        $this->audit->recordAuditLog(
            'USER_ROLE_ATTACHED',
            $this->actorId($actor),
            'user',
            $userId,
            null,
            ['role' => UserRole::STUDENT->value, 'reason' => 'student_profile_created', 'student_id' => $id],
            null,
            $this->actorIp($actor),
        );
    }
}
