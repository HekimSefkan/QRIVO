<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\SchoolService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\SchoolRepository;

final class SchoolController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_SCHOOL_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new SchoolService(
            $this->logger,
            new SchoolRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
