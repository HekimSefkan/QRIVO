<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Schedule\StudentClassAssignmentService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\Schedule\StudentClassAssignmentRepository;

final class StudentClassAssignmentController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ASSIGNMENT_COURSE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new StudentClassAssignmentService(
            $this->logger,
            new StudentClassAssignmentRepository($this->db),
            $this->securityLog(),
            $this->reference(),
            new ScheduleRepository($this->db),
        );
    }
}
