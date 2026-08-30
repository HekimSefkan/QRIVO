<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\Teacher;
use QRIVO\Domain\Enum\UserRole;

/**
 * Teacher profile management.
 *
 * A teacher profile links an existing `users` account to a department. User
 * account provisioning itself is out of scope here (OQ-004): the user must
 * already exist, be active and approved. On creation the non-privileged TEACHER
 * role is attached to the account so it is coherent (an admin action, audited).
 */
final class TeacherService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'teacher';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'user_id'         => 'required|integer',
            'department_id'   => 'required|integer',
            'employee_number' => 'required|string|min_length:1|max_length:50',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['department_id' => 'department_id', 'user_id' => 'user_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate) {
            // user_id is the immutable identity link — settable only on create.
            $data['user_id'] = (int) ($input['user_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('department_id', $input)) {
            $data['department_id'] = (int) ($input['department_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('employee_number', $input)) {
            $data['employee_number'] = trim((string) ($input['employee_number'] ?? ''));
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return Teacher::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('department_id', 'departments', $data['department_id'] ?? null);

        if ($existing === null) {
            $userId = (int) ($data['user_id'] ?? 0);
            if (!$this->reference->userIsUsable($userId)) {
                $this->fail('user_id', 'The user_id must reference an existing, active, approved user account.');
            }
            if ($this->reference->userHasProfile('teachers', $userId)) {
                $this->fail('user_id', 'This user already has a teacher profile.');
            }
        }

        if (isset($data['employee_number'])
            && $this->repo->existsActiveMatching(['employee_number' => $data['employee_number']], $existing['id'] ?? null)
        ) {
            $this->fail('employee_number', 'This employee number is already in use.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     */
    protected function afterCreate(int $id, array $data, array $actor): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $this->reference->ensureUserRole($userId, UserRole::TEACHER->value);

        $this->audit->recordAuditLog(
            'USER_ROLE_ATTACHED',
            $this->actorId($actor),
            'user',
            $userId,
            null,
            ['role' => UserRole::TEACHER->value, 'reason' => 'teacher_profile_created', 'teacher_id' => $id],
            null,
            $this->actorIp($actor),
        );
    }
}
