<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\ClassService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\ClassRepository;

final class ClassController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_CLASS_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new ClassService(
            $this->logger,
            new ClassRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
