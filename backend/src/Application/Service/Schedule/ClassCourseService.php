<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Schedule;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Entity\Schedule\ClassCourse;
use QRIVO\Infrastructure\Repository\AbstractCrudRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;

/**
 * Manages which courses are offered to a class in a term (`class_courses`).
 * Adding/removing a course refreshes the derived `student_courses` rows for
 * students already enrolled in the class (DD-005).
 */
final class ClassCourseService extends AbstractAcademicService
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
        return 'class_course';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'class_id'         => 'required|integer',
            'course_id'        => 'required|integer',
            'academic_term_id' => 'required|integer',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['class_id' => 'class_id', 'course_id' => 'course_id', 'academic_term_id' => 'academic_term_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        foreach (['class_id', 'course_id', 'academic_term_id'] as $key) {
            if ($isCreate || array_key_exists($key, $input)) {
                $data[$key] = (int) ($input[$key] ?? 0);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return ClassCourse::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('class_id', 'classes', $data['class_id'] ?? null);
        $this->requireReference('course_id', 'courses', $data['course_id'] ?? null);
        $this->requireReference('academic_term_id', 'academic_terms', $data['academic_term_id'] ?? null, false);

        if (isset($data['class_id'], $data['course_id'], $data['academic_term_id'])
            && $this->repo->existsActiveMatching([
                'class_id'         => (int) $data['class_id'],
                'course_id'        => (int) $data['course_id'],
                'academic_term_id' => (int) $data['academic_term_id'],
            ], $existing['id'] ?? null)
        ) {
            $this->fail('course_id', 'This course is already assigned to this class for this term.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     */
    protected function afterCreate(int $id, array $data, array $actor): void
    {
        $this->schedule->resyncClassCourses((int) $data['class_id'], (int) $data['academic_term_id']);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $actor
     */
    protected function afterDelete(int $id, array $existing, array $actor): void
    {
        $this->schedule->pruneStudentCoursesForClassCourse(
            (int) $existing['class_id'],
            (int) $existing['course_id'],
            (int) $existing['academic_term_id'],
        );
    }
}
