<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\ProgramService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\ProgramRepository;

final class ProgramController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_PROGRAM_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new ProgramService(
            $this->logger,
            new ProgramRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
