<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Schedule\TeacherCourseService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Schedule\TeacherCourseRepository;

final class TeacherCourseController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ASSIGNMENT_COURSE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new TeacherCourseService(
            $this->logger,
            new TeacherCourseRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
