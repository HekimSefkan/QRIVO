<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Schedule;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Entity\Schedule\CourseSchedule;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Infrastructure\Repository\AbstractCrudRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;

/**
 * Manages `course_schedules` — the weekly meeting slots of a teacher-class
 * assignment. Validation:
 *   - teacher_class_assignment and room must exist
 *   - day_of_week ∈ 0..6, start_time < end_time
 *   - no room double-booking (overlapping slot, same room + day)
 *   - no teacher double-booking (overlapping slot for any of the teacher's assignments)
 */
final class CourseScheduleService extends AbstractAcademicService
{
    public function __construct(
        LoggerInterface $logger,
        AbstractCrudRepository $repo,
        SecurityLogService $audit,
        ReferenceRepository $reference,
        private readonly ScheduleRepository $schedule,
    ) {
        parent::__construct($logger, $repo, $audit, $reference);
    }

    protected function entityName(): string
    {
        return 'course_schedule';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'teacher_class_assignment_id' => 'required|integer',
            'room_id'                     => 'required|integer',
            'day_of_week'                 => 'required|integer_range:0,6',
            'start_time'                  => 'required|time',
            'end_time'                    => 'required|time',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return [
            'teacher_class_assignment_id' => 'teacher_class_assignment_id',
            'room_id'                     => 'room_id',
            'day_of_week'                 => 'day_of_week',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate || array_key_exists('teacher_class_assignment_id', $input)) {
            $data['teacher_class_assignment_id'] = (int) ($input['teacher_class_assignment_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('room_id', $input)) {
            $data['room_id'] = (int) ($input['room_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('day_of_week', $input)) {
            $data['day_of_week'] = (int) ($input['day_of_week'] ?? 0);
        }
        if ($isCreate || array_key_exists('start_time', $input)) {
            $data['start_time'] = self::normaliseTime((string) ($input['start_time'] ?? ''));
        }
        if ($isCreate || array_key_exists('end_time', $input)) {
            $data['end_time'] = self::normaliseTime((string) ($input['end_time'] ?? ''));
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return CourseSchedule::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('teacher_class_assignment_id', 'teacher_class_assignments', $data['teacher_class_assignment_id'] ?? null, false);
        $this->requireReference('room_id', 'rooms', $data['room_id'] ?? null);

        $start = (string) ($data['start_time'] ?? '');
        $end   = (string) ($data['end_time'] ?? '');
        if ($start !== '' && $end !== '' && $end <= $start) {
            $this->fail('end_time', 'The end_time must be after the start_time.');
        }

        $tca   = (int) ($data['teacher_class_assignment_id'] ?? 0);
        $room  = (int) ($data['room_id'] ?? 0);
        $dow   = (int) ($data['day_of_week'] ?? 0);
        $exceptId = $existing['id'] ?? null;

        if ($this->schedule->roomHasConflict($room, $dow, $start, $end, $exceptId)) {
            throw new ConflictException('This room is already booked for an overlapping time on that day.');
        }

        if ($this->schedule->teacherHasConflict($tca, $dow, $start, $end, $exceptId)) {
            throw new ConflictException('This teacher already has an overlapping schedule on that day.');
        }
    }

    private static function normaliseTime(string $t): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t . ':00' : $t;
    }
}
