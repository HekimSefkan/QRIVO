<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\TeacherService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\TeacherRepository;

final class TeacherController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_TEACHER_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new TeacherService(
            $this->logger,
            new TeacherRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
