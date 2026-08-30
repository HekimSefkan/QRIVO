<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Enum\AttendanceStatus;
use QRIVO\Domain\Enum\SessionStatus;
use QRIVO\Domain\Enum\UserRole;

/**
 * Tests for Domain enum classes.
 */
final class DomainEnumTest extends TestCase
{
    // ─── UserRole ──────────────────────────────────────────────────────────────

    public function test_user_role_values_match_specification(): void
    {
        $this->assertSame('SUPER_ADMIN', UserRole::SUPER_ADMIN->value);
        $this->assertSame('ADMIN', UserRole::ADMIN->value);
        $this->assertSame('TEACHER', UserRole::TEACHER->value);
        $this->assertSame('STUDENT', UserRole::STUDENT->value);
    }

    public function test_user_role_hierarchy_super_admin_is_highest(): void
    {
        $this->assertTrue(UserRole::SUPER_ADMIN->isAtLeast(UserRole::SUPER_ADMIN));
        $this->assertTrue(UserRole::SUPER_ADMIN->isAtLeast(UserRole::ADMIN));
        $this->assertTrue(UserRole::SUPER_ADMIN->isAtLeast(UserRole::TEACHER));
        $this->assertTrue(UserRole::SUPER_ADMIN->isAtLeast(UserRole::STUDENT));
    }

    public function test_user_role_hierarchy_student_is_lowest(): void
    {
        $this->assertFalse(UserRole::STUDENT->isAtLeast(UserRole::SUPER_ADMIN));
        $this->assertFalse(UserRole::STUDENT->isAtLeast(UserRole::ADMIN));
        $this->assertFalse(UserRole::STUDENT->isAtLeast(UserRole::TEACHER));
        $this->assertTrue(UserRole::STUDENT->isAtLeast(UserRole::STUDENT));
    }

    public function test_user_role_hierarchy_admin_above_teacher(): void
    {
        $this->assertTrue(UserRole::ADMIN->isAtLeast(UserRole::TEACHER));
        $this->assertFalse(UserRole::TEACHER->isAtLeast(UserRole::ADMIN));
    }

    public function test_user_role_label_returns_human_readable(): void
    {
        $this->assertSame('Super Administrator', UserRole::SUPER_ADMIN->label());
        $this->assertSame('Administrator', UserRole::ADMIN->label());
        $this->assertSame('Teacher', UserRole::TEACHER->label());
        $this->assertSame('Student', UserRole::STUDENT->label());
    }

    public function test_user_role_from_value(): void
    {
        $role = UserRole::from('TEACHER');
        $this->assertSame(UserRole::TEACHER, $role);
    }

    // ─── AttendanceStatus ──────────────────────────────────────────────────────

    public function test_attendance_status_values(): void
    {
        $this->assertSame('WAITING', AttendanceStatus::WAITING->value);
        $this->assertSame('PRESENT', AttendanceStatus::PRESENT->value);
        $this->assertSame('ABSENT', AttendanceStatus::ABSENT->value);
        $this->assertSame('LATE', AttendanceStatus::LATE->value);
        $this->assertSame('EXCUSED', AttendanceStatus::EXCUSED->value);
        $this->assertSame('PENDING_REVIEW', AttendanceStatus::PENDING_REVIEW->value);
    }

    public function test_teacher_assignable_statuses_do_not_include_pending_review(): void
    {
        $assignable = AttendanceStatus::teacherAssignable();
        $this->assertNotContains(AttendanceStatus::PENDING_REVIEW, $assignable);
        $this->assertContains(AttendanceStatus::WAITING, $assignable);
        $this->assertContains(AttendanceStatus::PRESENT, $assignable);
        $this->assertContains(AttendanceStatus::ABSENT, $assignable);
        $this->assertContains(AttendanceStatus::LATE, $assignable);
        $this->assertContains(AttendanceStatus::EXCUSED, $assignable);
    }

    public function test_terminal_statuses_do_not_include_waiting(): void
    {
        $terminal = AttendanceStatus::terminal();
        $this->assertNotContains(AttendanceStatus::WAITING, $terminal);
        $this->assertNotContains(AttendanceStatus::PENDING_REVIEW, $terminal);
        $this->assertContains(AttendanceStatus::PRESENT, $terminal);
        $this->assertContains(AttendanceStatus::ABSENT, $terminal);
        $this->assertContains(AttendanceStatus::LATE, $terminal);
        $this->assertContains(AttendanceStatus::EXCUSED, $terminal);
    }

    // ─── SessionStatus ─────────────────────────────────────────────────────────

    public function test_session_status_values(): void
    {
        $this->assertSame('ACTIVE', SessionStatus::ACTIVE->value);
        $this->assertSame('CLOSED', SessionStatus::CLOSED->value);
        $this->assertSame('CANCELLED', SessionStatus::CANCELLED->value);
    }

    public function test_session_status_active_is_active(): void
    {
        $this->assertTrue(SessionStatus::ACTIVE->isActive());
        $this->assertFalse(SessionStatus::CLOSED->isActive());
        $this->assertFalse(SessionStatus::CANCELLED->isActive());
    }

    public function test_session_status_terminal(): void
    {
        $this->assertFalse(SessionStatus::ACTIVE->isTerminal());
        $this->assertTrue(SessionStatus::CLOSED->isTerminal());
        $this->assertTrue(SessionStatus::CANCELLED->isTerminal());
    }
}
