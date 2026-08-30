<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\Schedule\TeacherClassAssignmentService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\Schedule\TeacherClassAssignmentRepository;

final class TeacherClassAssignmentController extends AbstractResourceController
{
    protected function managePermission(): Permission
    {
        return Permission::ASSIGNMENT_COURSE_MANAGE;
    }

    protected function makeService(): AbstractAcademicService
    {
        return new TeacherClassAssignmentService(
            $this->logger,
            new TeacherClassAssignmentRepository($this->db),
            $this->securityLog(),
            $this->reference(),
            new ScheduleRepository($this->db),
        );
    }
}
