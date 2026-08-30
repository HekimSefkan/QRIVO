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
use QRIVO\Presentation\Http\Controller\Admin\AuditLogController;
use QRIVO\Presentation\Http\Controller\Admin\ClassController;
use QRIVO\Presentation\Http\Controller\Admin\ClassCourseController;
use QRIVO\Presentation\Http\Controller\Admin\CourseController;
use QRIVO\Presentation\Http\Controller\Admin\CourseScheduleController;
use QRIVO\Presentation\Http\Controller\Admin\DepartmentController;
use QRIVO\Presentation\Http\Controller\Admin\FacultyController;
use QRIVO\Presentation\Http\Controller\Admin\ProgramController;
use QRIVO\Presentation\Http\Controller\Admin\ReportController as AdminReportController;
use QRIVO\Presentation\Http\Controller\Admin\RoomController;
use QRIVO\Presentation\Http\Controller\Admin\SchoolController;
use QRIVO\Presentation\Http\Controller\Admin\SecurityEventController;
use QRIVO\Presentation\Http\Controller\Admin\StudentClassAssignmentController;
use QRIVO\Presentation\Http\Controller\Admin\StudentController;
use QRIVO\Presentation\Http\Controller\Admin\StudentCourseController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherClassAssignmentController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherController;
use QRIVO\Presentation\Http\Controller\Admin\TeacherCourseController;
use QRIVO\Presentation\Http\Controller\Auth\AuthController;
use QRIVO\Presentation\Http\Controller\HealthController;
use QRIVO\Presentation\Http\Controller\Student\AttendanceController as StudentAttendanceController;
use QRIVO\Presentation\Http\Controller\Student\ReportController as StudentReportController;
use QRIVO\Presentation\Http\Controller\Student\SelfController as StudentSelfController;
use QRIVO\Presentation\Http\Controller\Teacher\AttendanceController;
use QRIVO\Presentation\Http\Controller\Teacher\AttendanceEligibilityController;
use QRIVO\Presentation\Http\Controller\Teacher\LiveAttendanceController;
use QRIVO\Presentation\Http\Controller\Teacher\ReportController as TeacherReportController;

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

// ─── Security event & audit trail (Phase 20 — admin, read-only) ──────────
// `security.event.view` / `audit.log.view` (ADMIN / SUPER_ADMIN). Append-only:
// no write endpoint — records are created by SecurityLogService.
$r->addRoute('GET', '/api/v1/admin/security-events', [SecurityEventController::class, 'index']);
$r->addRoute('GET', '/api/v1/admin/audit-logs',      [AuditLogController::class, 'index']);

// ─── Attendance eligibility (Phase 9 — teacher) ──────────────────────────
// "May this teacher open attendance for course/class at this time?" — the
// server-side authorization determination. No session is created, no QR issued.
$r->addRoute('GET', '/api/v1/teacher/attendance/eligibility', [AttendanceEligibilityController::class, 'check']);

// ─── Attendance sessions (Phase 10 — teacher) ────────────────────────────
// Creation runs the full 10-step ATTENDANCE_ALGORITHM.md §2 sequence inside a
// transaction; duplicate ACTIVE sessions for a (class, course, term) are refused.
$r->addRoute('POST', '/api/v1/teacher/attendance/start',       [AttendanceController::class, 'start']);
$r->addRoute('GET',  '/api/v1/teacher/attendance/{id:\d+}',    [AttendanceController::class, 'show']);

// ─── Dynamic QR (Phase 11) ──────────────────────────────────────────────
// Teacher: current short-lived HMAC-SHA256 signed QR for their ACTIVE session.
$r->addRoute('GET',  '/api/v1/teacher/attendance/{id:\d+}/qr', [AttendanceController::class, 'qr']);

// ─── Teacher live attendance (Phase 13) ─────────────────────────────────
// AJAX polling; session ownership re-checked on every request.
$r->addRoute('GET', '/api/v1/teacher/attendance/{id:\d+}/live',          [LiveAttendanceController::class, 'snapshot']);
$r->addRoute('GET', '/api/v1/teacher/attendance/{id:\d+}/live/counters', [LiveAttendanceController::class, 'counters']);
$r->addRoute('GET', '/api/v1/teacher/attendance/{id:\d+}/live/students', [LiveAttendanceController::class, 'students']);
// Student: preflight validation of a scanned QR (non-consuming; no attendance created).
$r->addRoute('POST', '/api/v1/student/attendance/qr/verify',   [StudentAttendanceController::class, 'verifyQr']);

// ─── Challenge-response attendance (Phase 12 — student) ──────────────────
// scan → challenge → challenge response → server validation → attendance.
$r->addRoute('POST', '/api/v1/student/attendance/challenge',   [StudentAttendanceController::class, 'challenge']);
$r->addRoute('POST', '/api/v1/student/attendance/verify',      [StudentAttendanceController::class, 'verify']);

// ─── Student self-service (Phase 16 — mobile app) ───────────────────────
// The backend is authoritative; the mobile client only renders these payloads.
$r->addRoute('GET', '/api/v1/student/dashboard',           [StudentSelfController::class, 'dashboard']);
$r->addRoute('GET', '/api/v1/student/profile',             [StudentSelfController::class, 'profile']);
$r->addRoute('GET', '/api/v1/student/schedule',            [StudentSelfController::class, 'schedule']);
$r->addRoute('GET', '/api/v1/student/attendance/history',  [StudentSelfController::class, 'attendanceHistory']);

// ─── Manual attendance (Phase 14 — teacher) ─────────────────────────────
// attendanceId = attendance session id; studentId = students.id. Every change
// is authorization-gated and audited (ATTENDANCE_ALGORITHM.md §6).
$r->addRoute('PATCH', '/api/v1/teacher/attendance/{attendanceId:\d+}/student/{studentId:\d+}', [AttendanceController::class, 'updateStudent']);

// ─── Attendance reporting (Phase 21) ────────────────────────────────────
// Authorization is enforced per role: students see only their own data;
// teachers only their assigned courses/classes/students; admins only per the
// `report.institution.view` permission. Pagination + filtering on every list.
$r->addRoute('GET', '/api/v1/student/reports/attendance',            [StudentReportController::class, 'attendance']);

$r->addRoute('GET', '/api/v1/teacher/reports/course/{id:\d+}',       [TeacherReportController::class, 'course']);
$r->addRoute('GET', '/api/v1/teacher/reports/class/{id:\d+}',        [TeacherReportController::class, 'classReport']);
$r->addRoute('GET', '/api/v1/teacher/reports/student/{id:\d+}',      [TeacherReportController::class, 'student']);

$r->addRoute('GET', '/api/v1/admin/reports/institution',             [AdminReportController::class, 'institution']);
$r->addRoute('GET', '/api/v1/admin/reports/department/{id:\d+}',     [AdminReportController::class, 'department']);
$r->addRoute('GET', '/api/v1/admin/reports/course/{id:\d+}',         [AdminReportController::class, 'course']);
$r->addRoute('GET', '/api/v1/admin/reports/attendance-statistics',   [AdminReportController::class, 'statistics']);

// ─── Session close / cancel (Phase 15 — teacher, ATTENDANCE_ALGORITHM.md §7) ──
// ACTIVE → CLOSED (remaining WAITING → ABSENT|PENDING_REVIEW per system_settings)
// or ACTIVE → CANCELLED. Transactional, ownership-checked, audited, concurrency-safe.
$r->addRoute('POST', '/api/v1/teacher/attendance/{id:\d+}/close',  [AttendanceController::class, 'close']);
$r->addRoute('POST', '/api/v1/teacher/attendance/{id:\d+}/cancel', [AttendanceController::class, 'cancel']);

