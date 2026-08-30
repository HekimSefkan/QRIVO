<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\StudentService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\StudentRepository;

final class StudentController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_STUDENT_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new StudentService(
            $this->logger,
            new StudentRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
