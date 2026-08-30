<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\CourseService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\CourseRepository;

final class CourseController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_COURSE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new CourseService(
            $this->logger,
            new CourseRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
