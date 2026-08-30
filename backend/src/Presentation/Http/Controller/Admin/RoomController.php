<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Academic\RoomService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\Academic\RoomRepository;

final class RoomController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ACADEMIC_ROOM_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new RoomService(
            $this->logger,
            new RoomRepository($this->db),
            $this->securityLog(),
            $this->reference(),
        );
    }
}
