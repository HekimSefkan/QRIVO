<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Schedule\StudentCourseService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Schedule\StudentCourseRepository;

/**
 * Read-only: only `index` and `show` are routed. `student_courses` is a derived
 * table (DD-005) maintained by the student-class-assignment and class-course
 * services.
 */
final class StudentCourseController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ASSIGNMENT_COURSE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new StudentCourseService(
            $this->logger,
            new StudentCourseRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
