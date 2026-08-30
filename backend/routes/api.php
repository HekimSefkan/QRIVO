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
use QRIVO\Presentation\Http\Controller\Admin\CourseController;
use QRIVO\Presentation\Http\Controller\Admin\DepartmentController;
use QRIVO\Presentation\Http\Controller\Admin\FacultyController;
use QRIVO\Presentation\Http\Controller\Admin\ProgramController;
use QRIVO\Presentation\Http\Controller\Admin\RoomController;
use QRIVO\Presentation\Http\Controller\Admin\SchoolController;
use QRIVO\Presentation\Http\Controller\Admin\StudentController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherController;
use QRIVO\Presentation\Http\Controller\Auth\AuthController;
use QRIVO\Presentation\Http\Controller\HealthController;

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

// ─── Teacher Attendance (Phases 10–15) ───────────────────────────────────
// $r->addRoute('POST',  '/api/v1/teacher/attendance/start',                        [AttendanceController::class, 'start']);
// $r->addRoute('POST',  '/api/v1/teacher/attendance/{id}/close',                   [AttendanceController::class, 'close']);
// $r->addRoute('PATCH', '/api/v1/teacher/attendance/{attendanceId}/student/{studentId}', [AttendanceController::class, 'updateStudent']);

