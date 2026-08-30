<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\FacultyService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\FacultyRepository;

final class FacultyController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_FACULTY_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new FacultyService(
            $this->logger,
            new FacultyRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
