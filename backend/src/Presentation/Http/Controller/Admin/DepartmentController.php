<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\DepartmentService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\DepartmentRepository;

final class DepartmentController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_DEPARTMENT_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new DepartmentService(
            $this->logger,
            new DepartmentRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
