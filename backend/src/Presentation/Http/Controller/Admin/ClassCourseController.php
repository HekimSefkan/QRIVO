<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Schedule\ClassCourseService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\Schedule\ClassCourseRepository;

final class ClassCourseController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ASSIGNMENT_COURSE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new ClassCourseService(
            $this->logger,
            new ClassCourseRepository($this->db),
            $this->securityLog(),
            $this->reference(),
            new ScheduleRepository($this->db),
        );
    }
}
