<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\AcademicTermService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\AcademicTermRepository;

final class AcademicTermController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_ACADEMIC_TERM_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new AcademicTermService(
            $this->logger,
            new AcademicTermRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
