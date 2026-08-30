<?php

declare(strict_types=1);

/**
 * QRIVO API Routes
 *
 * All routes are versioned under /api/v1/
 * Controllers follow: [ControllerClass::class, 'method']
 *
 * @var \FastRoute\RouteCollector $r
 */

use QRIVO\Presentation\Http\Controller\Admin\AcademicTermController;
use QRIVO\Presentation\Http\Controller\Admin\AcademicYearController;
use QRIVO\Presentation\Http\Controller\Admin\ClassController;
use QRIVO\Presentation\Http\Controller\Admin\ClassCourseController;
use QRIVO\Presentation\Http\Controller\Admin\CourseController;
use QRIVO\Presentation\Http\Controller\Admin\CourseScheduleController;
use QRIVO\Presentation\Http\Controller\Admin\DepartmentController;
use QRIVO\Presentation\Http\Controller\Admin\FacultyController;
use QRIVO\Presentation\Http\Controller\Admin\ProgramController;
use QRIVO\Presentation\Http\Controller\Admin\RoomController;
use QRIVO\Presentation\Http\Controller\Admin\SchoolController;
use QRIVO\Presentation\Http\Controller\Admin\StudentClassAssignmentController;
use QRIVO\Presentation\Http\Controller\Admin\StudentController;
use QRIVO\Presentation\Http\Controller\Admin\StudentCourseController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherClassAssignmentController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherCourseController;
use QRIVO\Presentation\Http\Controller\Auth\AuthController;
use QRIVO\Presentation\Http\Controller\HealthController;
use QRIVO\Presentation\Http\Controller\Teacher\AttendanceController;
use QRIVO\Presentation\Http\Controller\Teacher\AttendanceEligibilityController;

// ─── Health ──────────────────────────────────────────────────────────────────
$r->addRoute('GET', '/api/v1/health', [HealthController::class, 'check']);

// ─── Authentication (Phase 6) ─────────────────────────────────────────────
$r->addRoute('POST', '/api/v1/auth/login',   [AuthController::class, 'login']);
$r->addRoute('POST', '/api/v1/auth/logout',  [AuthController::class, 'logout']);
$r->addRoute('POST', '/api/v1/auth/refresh', [AuthController::class, 'refresh']);

// ─── Current actor (Phase 7 — authenticated) ──────────────────────────────
// Identity is derived server-side from the bearer token, never from the client.
$r->addRoute('GET', '/api/v1/auth/me', [AuthController::class, 'me']);

// ─── Academic & institutional structure (Phase 8 — admin only) ────────────
// Every action authenticates the bearer token and requires the resource's
// `academic.*.manage` permission (ADMIN / SUPER_ADMIN). Enforced in
// AbstractResourceController::guard().
$resource = static function (string $path, string $controller) use ($r): void {
    $r->addRoute('GET',    $path,               [$controller, 'index']);
    $r->addRoute('POST',   $path,               [$controller, 'store']);
    $r->addRoute('GET',    $path . '/{id:\d+}', [$controller, 'show']);
    $r->addRoute('PATCH',  $path . '/{id:\d+}', [$controller, 'update']);
    $r->addRoute('DELETE', $path . '/{id:\d+}', [$controller, 'destroy']);
};

$resource('/api/v1/admin/schools',         SchoolController::class);
$resource('/api/v1/admin/faculties',       FacultyController::class);
$resource('/api/v1/admin/departments',     DepartmentController::class);
$resource('/api/v1/admin/programs',        ProgramController::class);
$resource('/api/v1/admin/rooms',           RoomController::class);
$resource('/api/v1/admin/courses',         CourseController::class);
$resource('/api/v1/admin/academic-years',  AcademicYearController::class);
$resource('/api/v1/admin/academic-terms',  AcademicTermController::class);
$resource('/api/v1/admin/classes',         ClassController::class);
$resource('/api/v1/admin/teachers',        TeacherController::class);
$resource('/api/v1/admin/students',        StudentController::class);

// ─── Course assignments & scheduling (Phase 9 — admin only) ───────────────
// `assignment.course.manage` for the assignment tables, `assignment.schedule.manage`
// for course_schedules.
$resource('/api/v1/admin/class-courses',              ClassCourseController::class);
$resource('/api/v1/admin/teacher-courses',            TeacherCourseController::class);
$resource('/api/v1/admin/teacher-class-assignments',  TeacherClassAssignmentController::class);
$resource('/api/v1/admin/student-class-assignments',  StudentClassAssignmentController::class);
$resource('/api/v1/admin/course-schedules',           CourseScheduleController::class);

// student_courses is derived (DD-005) — read-only.
$r->addRoute('GET', '/api/v1/admin/student-courses',           [StudentCourseController::class, 'index']);
$r->addRoute('GET', '/api/v1/admin/student-courses/{id:\d+}',  [StudentCourseController::class, 'show']);

// ─── Attendance eligibility (Phase 9 — teacher) ──────────────────────────
// "May this teacher open attendance for course/class at this time?" — the
// server-side authorization determination. No session is created, no QR issued.
$r->addRoute('GET', '/api/v1/teacher/attendance/eligibility', [AttendanceEligibilityController::class, 'check']);

// ─── Attendance sessions (Phase 10 — teacher) ────────────────────────────
// Creation runs the full 10-step ATTENDANCE_ALGORITHM.md §2 sequence inside a
// transaction; duplicate ACTIVE sessions for a (class, course, term) are refused.
$r->addRoute('POST', '/api/v1/teacher/attendance/start',    [AttendanceController::class, 'start']);
$r->addRoute('GET',  '/api/v1/teacher/attendance/{id:\d+}', [AttendanceController::class, 'show']);

// ─── Teacher Attendance (Phases 11–15) ───────────────────────────────────
// $r->addRoute('POST',  '/api/v1/teacher/attendance/{id}/close',                   [AttendanceController::class, 'close']);
// $r->addRoute('PATCH', '/api/v1/teacher/attendance/{attendanceId}/student/{studentId}', [AttendanceController::class, 'updateStudent']);

