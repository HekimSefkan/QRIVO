<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Domain\Authorization;

use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Authorization\RolePermissionMap;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\UserRole;

final class RolePermissionMapTest extends TestCase
{
    public function test_every_role_is_mapped(): void
    {
        $map = RolePermissionMap::all();

        foreach (UserRole::cases() as $role) {
            $this->assertArrayHasKey($role->value, $map, "role {$role->value} must be in the map");
        }
    }

    public function test_all_mapped_permissions_exist_in_enum(): void
    {
        $valid = Permission::allValues();

        foreach (RolePermissionMap::all() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains($permission, $valid, "{$role} references unknown permission {$permission}");
            }
        }
    }

    public function test_super_admin_has_all_permissions(): void
    {
        $this->assertSame(
            Permission::allValues(),
            RolePermissionMap::forRole(UserRole::SUPER_ADMIN->value),
        );
    }

    public function test_least_privilege_separation(): void
    {
        $admin   = RolePermissionMap::forRole(UserRole::ADMIN->value);
        $teacher = RolePermissionMap::forRole(UserRole::TEACHER->value);
        $student = RolePermissionMap::forRole(UserRole::STUDENT->value);

        // Admin manages structure but cannot run attendance
        $this->assertContains(Permission::ACADEMIC_SCHOOL_MANAGE->value, $admin);
        $this->assertNotContains(Permission::ATTENDANCE_SESSION_START->value, $admin);

        // Teacher runs attendance but cannot manage structure or assign roles
        $this->assertContains(Permission::ATTENDANCE_SESSION_START->value, $teacher);
        $this->assertNotContains(Permission::ACADEMIC_SCHOOL_MANAGE->value, $teacher);
        $this->assertNotContains(Permission::IAM_ROLE_ASSIGN->value, $teacher);

        // Student only submits and views own data
        $this->assertContains(Permission::ATTENDANCE_QR_SUBMIT->value, $student);
        $this->assertNotContains(Permission::ATTENDANCE_SESSION_START->value, $student);
        $this->assertNotContains(Permission::REPORT_INSTITUTION_VIEW->value, $student);
    }

    public function test_iam_role_assign_is_super_admin_only(): void
    {
        foreach (RolePermissionMap::all() as $role => $permissions) {
            if ($role === UserRole::SUPER_ADMIN->value) {
                $this->assertContains(Permission::IAM_ROLE_ASSIGN->value, $permissions);
            } else {
                $this->assertNotContains(Permission::IAM_ROLE_ASSIGN->value, $permissions);
            }
        }
    }

    public function test_for_roles_returns_union(): void
    {
        $union = RolePermissionMap::forRoles([UserRole::TEACHER->value, UserRole::STUDENT->value]);

        $this->assertContains(Permission::ATTENDANCE_SESSION_START->value, $union);
        $this->assertContains(Permission::ATTENDANCE_QR_SUBMIT->value, $union);
    }
}
