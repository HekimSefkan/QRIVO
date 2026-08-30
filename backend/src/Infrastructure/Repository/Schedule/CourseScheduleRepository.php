<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Schedule;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class CourseScheduleRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'course_schedules';
    }

    protected function usesSoftDeletes(): bool
    {
        return false;
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['teacher_class_assignment_id', 'room_id', 'day_of_week', 'start_time', 'end_time'];
    }
}
