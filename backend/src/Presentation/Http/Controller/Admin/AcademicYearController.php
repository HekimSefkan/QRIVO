<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\AcademicYearService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\AcademicYearRepository;

final class AcademicYearController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_ACADEMIC_YEAR_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new AcademicYearService(
            $this->logger,
            new AcademicYearRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
