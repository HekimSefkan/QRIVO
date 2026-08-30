<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Schedule\CourseScheduleService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\Schedule\CourseScheduleRepository;

final class CourseScheduleController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ASSIGNMENT_SCHEDULE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new CourseScheduleService(
            $this->logger,
            new CourseScheduleRepository($this->db),
            $this->securityLog(),
            $this->reference(),
            new ScheduleRepository($this->db),
        );
    }
}
