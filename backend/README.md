# QRIVO — Backend

> Secure QR-Based University Attendance System — PHP Backend

---

## Overview

The QRIVO backend is a PHP 8.3+ REST API built with a **Clean Architecture** / **MVC with Service Layer** approach. It powers the web dashboard and mobile Flutter application.

All security decisions are enforced **server-side**. The client is never trusted for authentication, authorization, or attendance state.

---

## Technology Stack

| Layer            | Technology               |
|------------------|--------------------------|
| Language         | PHP 8.3+                 |
| Database         | MySQL 8+ (InnoDB, utf8mb4) |
| DB Access        | PDO (prepared statements) |
| Package Manager  | Composer                 |
| Router           | `nikic/fast-route`       |
| Logging          | `monolog/monolog`        |
| Env Loading      | `vlucas/phpdotenv`       |
| Testing          | PHPUnit 11               |
| QR Signing       | HMAC-SHA256 *(future)*   |
| Password Hashing | Argon2id *(future)*      |

---

## Directory Structure

```
backend/
├── public/
│   └── index.php           # Sole entry point — all HTTP requests land here
├── src/
│   ├── Bootstrap/
│   │   └── App.php         # Application bootstrap (env, config, logger, DB, router)
│   ├── Domain/
│   │   ├── Authorization/  # RolePermissionMap — canonical role → permission map
│   │   ├── Contract/       # Interfaces: RepositoryInterface, PolicyInterface, ServiceInterface, LoggerInterface
│   │   ├── Attendance/     # AttendanceEligibility, QrPayload, QrValidationResult, RiskAssessment
│   │   ├── Contract/       # …, RiskEvaluatorInterface
│   │   ├── Entity/         # User, DeviceSession, Entity/Academic/* (11), Entity/Schedule/* (6), Entity/Attendance/* (3)
│   │   ├── Enum/           # UserRole, Permission, AttendanceStatus/Source, SessionStatus, DayOfWeek, QrValidationReason, ChallengeFailureReason, RiskLevel/Outcome, SecurityEventType
│   │   └── Exception/      # Domain exceptions: Unauthorized, Forbidden, NotFound, Conflict, Validation, ...
│   ├── Application/
│   │   ├── DTO/            # Base + Auth DTOs
│   │   ├── Policy/         # SelfOwnedResourcePolicy, AttendanceAuthorizationPolicy
│   │   ├── Service/        # Auth*, Authorization*, AttendanceEligibility*, Service/{Academic (11), Schedule (6), Attendance: Session + Qr + Challenge + RiskEvaluation + LiveAttendance}
│   │   └── Validation/     # Validator — input validation rules engine
│   ├── Infrastructure/
│   │   ├── Config/         # Config — dot-notation config loader from PHP files + ENV
│   │   ├── Database/       # Connection — lazy PDO wrapper with transaction helpers
│   │   ├── Logging/        # Logger — Monolog wrapper with sensitive key redaction
│   │   └── Repository/     # Base/AbstractCrud/Reference/Schedule/Relationship, Repository/{Academic,Schedule,Attendance}/*
│   └── Presentation/
│       └── Http/
│           ├── Controller/        # Health, Auth/*, Admin/* (16), Teacher/{AttendanceEligibility,Attendance,LiveAttendance}, Student/Attendance
│           ├── Middleware/        # Cors, JsonBody, Auth, Authorization, MiddlewarePipeline
│           ├── Response/          # JsonResponse — standard API envelope
│           ├── BaseController.php
│           ├── ExceptionHandler.php
│           ├── Request.php
│           └── Router.php
├── config/
│   ├── app.php             # Application config
│   ├── attendance.php      # Dynamic-QR TTL / refresh / clock-skew
│   ├── auth.php            # Token TTLs + login rate-limit thresholds
│   ├── database.php        # Database config
│   └── logging.php         # Logging config
├── routes/
│   └── api.php             # Route definitions (FastRoute)
├── storage/
│   └── logs/               # Application log files (auto-created)
├── tests/
│   ├── bootstrap.php
│   ├── Unit/               # Unit tests (no DB required)
│   └── Integration/        # Integration tests (requires DB)
├── .env.example            # Environment variable template
├── composer.json
└── phpunit.xml
```

---

## Setup

### 1. Install Dependencies

```bash
cd backend
composer install
```

### 2. Configure Environment

```bash
cp .env.example .env
# Edit .env with your local settings
```

### 3. Required Environment Variables

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=qrivo
DB_USERNAME=root
DB_PASSWORD=

LOG_LEVEL=debug
CORS_ALLOWED_ORIGINS=*
```

### 4. Serve the Application

```bash
php -S localhost:8000 -t public/
```

---

## Running Tests

```bash
# All tests
composer test

# Unit tests only (no DB required)
vendor/bin/phpunit --testsuite Unit

# Integration tests (requires live DB)
vendor/bin/phpunit --testsuite Integration
```

---

## API Endpoints

All endpoints are versioned under `/api/v1/`.

### Currently Implemented

| Method | Endpoint              | Auth          | Description         |
|--------|-----------------------|---------------|---------------------|
| `GET`  | `/api/v1/health`      | none          | System health check |
| `POST` | `/api/v1/auth/login`  | none          | User login (Argon2id, rate limited) |
| `POST` | `/api/v1/auth/logout` | Bearer        | Logout / token invalidation |
| `POST` | `/api/v1/auth/refresh`| refresh token | Rotate tokens (reuse detection) |
| `GET`  | `/api/v1/auth/me`     | Bearer        | Authenticated caller's identity + roles |

**Academic structure (admin — `academic.*.manage`, ADMIN / SUPER_ADMIN):**
standard REST (`GET` list, `POST` create, `GET`/`PATCH`/`DELETE` `/{id}`) under

`/api/v1/admin/{schools, faculties, departments, programs, rooms, courses,
academic-years, academic-terms, classes, teachers, students}`

**Course assignments & scheduling (admin — `assignment.course.manage` /
`assignment.schedule.manage`):** same REST shape under `/api/v1/admin/{class-courses,
teacher-courses, teacher-class-assignments, student-class-assignments,
course-schedules}`. `student-courses` is `GET`-only (derived, DD-005).

**Attendance eligibility (teacher — `attendance.session.start`):**
`GET /api/v1/teacher/attendance/eligibility?class_id=&course_id=&academic_term_id=&at=`
— server-side check of whether the caller may open attendance for that
course/class/time, and in which room. Creates nothing.

**Attendance sessions (teacher — `attendance.session.start`):**
`POST /api/v1/teacher/attendance/start` (`{class_id, course_id, [academic_term_id], [room_id]}`)
runs the full 10-step ATTENDANCE_ALGORITHM.md §2 sequence in one transaction,
initialises every enrolled student as `WAITING`, and refuses a duplicate ACTIVE
session for the same class/course/term (409). `GET /api/v1/teacher/attendance/{id}`
returns the caller's own session. `session_secret` is never returned.

**Dynamic QR (ATTENDANCE_ALGORITHM.md §3):**
`GET /api/v1/teacher/attendance/{id}/qr` (`attendance.live.view`) returns the
current short-lived QR for the teacher's own ACTIVE session — payload is exactly
`{session_id (UUID), timestamp, nonce, signature}`, wire format
`qrivo.v1.<uuid>.<ts>.<nonce>.<hmac_sha256>`; poll every `refresh_seconds`.
`POST /api/v1/student/attendance/qr/verify` (`attendance.qr.submit`, `{qr}`)
validates a scanned QR (non-consuming; creates nothing). Signing key is the
per-session `session_secret` (never returned); replay protection = nonce
(`qr_used_nonces`) + server-side expiry. Tunables in `config/attendance.php` /
`QR_TTL_SECONDS`.

**Challenge-response attendance (ATTENDANCE_ALGORITHM.md §4, `attendance.qr.submit`):**
`POST /api/v1/student/attendance/challenge` (`{qr}`) → `{challenge_id, nonce,
expires_at}`; `POST /api/v1/student/attendance/verify` (`{challenge_id, nonce,
qr}`) runs the full 13-point checklist in order and records attendance inside one
transaction (atomic single-use challenge, duplicate check, risk assessment,
`WAITING → PRESENT`). Challenges are single-use and short-lived; failed attempts
get a generic message (detail → `security_events`).

**Teacher live attendance (PROJECT_SPECIFICATION.md §6.8, `attendance.live.view`):**
`GET /api/v1/teacher/attendance/{id}/live` returns the dashboard snapshot
(session + current QR + live counters `TOTAL/WAITING/PRESENT/ABSENT/LATE/EXCUSED/PENDING_REVIEW`
+ student roster). Poll `.../live/counters` every `poll_interval_ms` and
re-fetch `.../live/students` (`?search=`, `?status=`, `?updated_since=`) when
`students_version` changes (AJAX polling — the spec's fallback). Session
ownership is re-checked on **every** request; only that session's students are
returned; `session_secret` is never exposed.

List endpoints accept `?page`, `?per_page`, `?search`, and id filters
(e.g. `?school_id=`, `?academic_term_id=`). Responses are paginated with a `meta` block.

Authorization is enforced server-side via `AuthorizationService` (role,
permission, ownership, relationship) and `AuthorizationMiddleware` /
`AbstractResourceController::guard()`. Permissions are seeded by
`database/migrations/002_seed_rbac_permissions.sql`.

### Planned (Future Phases)

| Method   | Endpoint                                                             | Description                |
|----------|----------------------------------------------------------------------|----------------------------|
| `POST`   | `/api/v1/teacher/attendance/start`                                   | Start attendance session   |
| `POST`   | `/api/v1/teacher/attendance/{id}/close`                              | Close attendance session   |
| `PATCH`  | `/api/v1/teacher/attendance/{attendanceId}/student/{studentId}`      | Manual attendance update   |

---

## API Response Format

All responses use the standard QRIVO envelope:

**Success:**
```json
{
  "success": true,
  "message": "OK",
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Error description."
}
```

**Validation Error (422):**
```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field must be at least 8 characters."]
  }
}
```

---

## Architecture

### Layers

| Layer            | Namespace                        | Responsibility                                   |
|------------------|----------------------------------|--------------------------------------------------|
| **Domain**       | `QRIVO\Domain`                   | Entities, enums, contracts, domain exceptions    |
| **Application**  | `QRIVO\Application`              | Business logic services, validators, DTOs        |
| **Infrastructure** | `QRIVO\Infrastructure`         | DB connection, config, logging, repositories     |
| **Presentation** | `QRIVO\Presentation`             | HTTP controllers, middleware, request/response    |

### Dependency Flow

```
Presentation → Application → Domain
Infrastructure → Domain
Presentation → Infrastructure (injected via constructor)
```

Controllers **must not** contain business logic — delegate to services.  
Services **must not** access the database directly — use repositories.

---

## Security

- All security decisions are **server-side**
- Passwords hashed with **Argon2id** *(implemented in auth phase)*
- PDO with **prepared statements only** (`ATTR_EMULATE_PREPARES = false`)
- Sensitive keys (`password`, `token`, `secret`, etc.) **redacted** from logs
- Internal errors **never exposed** to clients (only logged server-side)
- CORS configured via environment variables — not hard-coded
- RBAC + resource-level + relationship-based authorization *(implemented in auth phase)*

---

## Development Phases

This backend is being built incrementally:

- [x] Phase 5 — Backend Foundation
- [x] Phase 6 — Authentication
- [x] Phase 7 — RBAC + Authorization
- [x] Phase 8 — Admin & Academic Structure
- [x] Phase 9 — Course/Teacher/Student Assignments + Schedule
- [x] Phase 10 — Attendance Session
- [x] Phase 11 — Dynamic QR
- [x] Phase 12 — Challenge-Response Attendance
- [x] Phase 13 — Teacher Live Attendance
- [ ] Phase 14 — Manual Attendance
- [ ] ...

See [`docs/PROJECT_SPECIFICATION.md`](../docs/PROJECT_SPECIFICATION.md) for the full phase list.

---

## References

- [Project Specification](../docs/PROJECT_SPECIFICATION.md)
- [Architecture Rules](../docs/ARCHITECTURE_RULES.md)
- [Security Rules](../docs/SECURITY_RULES.md)
- [Attendance Algorithm](../docs/ATTENDANCE_ALGORITHM.md)
- [Changelog](../CHANGELOG.md)
