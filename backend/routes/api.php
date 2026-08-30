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

use QRIVO\Presentation\Http\Controller\HealthController;

// ─── Health ──────────────────────────────────────────────────────────────────
$r->addRoute('GET', '/api/v1/health', [HealthController::class, 'check']);

// ─── Authentication (Phase 6) ─────────────────────────────────────────────
// $r->addRoute('POST', '/api/v1/auth/login',   [AuthController::class, 'login']);
// $r->addRoute('POST', '/api/v1/auth/logout',  [AuthController::class, 'logout']);
// $r->addRoute('POST', '/api/v1/auth/refresh', [AuthController::class, 'refresh']);

// ─── Teacher Attendance (Phases 10–15) ───────────────────────────────────
// $r->addRoute('POST',  '/api/v1/teacher/attendance/start',                        [AttendanceController::class, 'start']);
// $r->addRoute('POST',  '/api/v1/teacher/attendance/{id}/close',                   [AttendanceController::class, 'close']);
// $r->addRoute('PATCH', '/api/v1/teacher/attendance/{attendanceId}/student/{studentId}', [AttendanceController::class, 'updateStudent']);
