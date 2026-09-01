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
│   │   ├── Risk/           # RiskPolicy — resolved weights + thresholds for the scoring engine
│   │   ├── Security/       # DeviceContext, LogSanitizer
│   │   ├── Contract/       # …, RiskEvaluatorInterface
│   │   ├── Entity/         # User, DeviceSession, Entity/Academic/* (11), Entity/Schedule/* (6), Entity/Attendance/* (3)
│   │   ├── Enum/           # UserRole, Permission, AttendanceStatus/Source, SessionStatus, DayOfWeek, QrValidationReason, ChallengeFailureReason, RiskLevel/Outcome/Signal, SecurityEventType
│   │   └── Exception/      # Domain exceptions: Unauthorized, Forbidden, NotFound, Conflict, Validation, ...
│   ├── Application/
│   │   ├── DTO/            # Base + Auth DTOs
│   │   ├── Policy/         # SelfOwnedResourcePolicy, AttendanceAuthorizationPolicy
│   │   ├── Service/        # Auth*, Authorization*, SecurityLog*, AttendanceEligibility*, Service/{Academic (11), Schedule (6), Attendance: Session/Qr/Challenge/LiveAttendance/ManualAttendance, Security: DeviceSession/RiskScoring/AuditQuery, Report: Abstract/Teacher/Admin/Student}
│   │   └── Validation/     # Validator — input validation rules engine
│   ├── Infrastructure/
│   │   ├── Config/         # Config — dot-notation config loader from PHP files + ENV
│   │   ├── Database/       # Connection — lazy PDO wrapper with transaction helpers
│   │   ├── Logging/        # Logger — Monolog wrapper with sensitive key redaction
│   │   └── Repository/     # Base/AbstractCrud/Reference/Schedule/Relationship, Repository/{Academic,Schedule,Attendance}/*
│   └── Presentation/
│       └── Http/
│           ├── Controller/        # Health, Auth/*, Admin/* (16 + SecurityEvent + AuditLog + Report), Teacher/{AttendanceEligibility,Attendance,LiveAttendance,Report}, Student/{Attendance,Self,Report}
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

### 4. Create the schema and demo data

```bash
php scripts/migrate.php     # creates the database + applies database/migrations/*.sql
php scripts/seed.php        # demo institution, courses, schedules and 16 accounts
```

`migrate.php` records applied files in `schema_migrations`, so re-running is a
no-op. `seed.php` is idempotent and **refuses to run unless `APP_ENV=local`**;
it needs `SEED_DEFAULT_PASSWORD` in `.env` and hashes every password at runtime
with Argon2id. See [`docs/RUNBOOK.md`](../docs/RUNBOOK.md) for the full guide,
including the Docker path.

### 5. Serve the Application

```bash
php -S localhost:8000 -t public/
```

### 6. Verify end to end

```bash
php scripts/smoke_test.php   # in a second terminal, against the running server
```

Walks login → start attendance → QR → challenge → verify → live list → manual
override → close, asserting the security controls (replay 409, student override
403, post-close rejection) at each step.

---

## Developer Scripts

| Script | Purpose |
|--------|---------|
| `scripts/migrate.php` | Apply migrations. `--status` lists applied/pending; `--fresh` drops and rebuilds (local only). |
| `scripts/seed.php` | Idempotent demo dataset + accounts. Local only; Argon2id hashes generated at runtime. |
| `scripts/smoke_test.php` | End-to-end HTTP verification against a running server. `--base-url=` to retarget. |

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
| `POST` | `/api/v1/auth/login`  | none          | User login (Argon2id, rate limited). Optional `X-Device-Id` / `X-Device-Name` headers register the device (§6.13) |
| `POST` | `/api/v1/auth/logout` | Bearer        | Logout / token invalidation |
| `POST` | `/api/v1/auth/refresh`| refresh token | Rotate tokens (reuse detection). Honours the same `X-Device-*` headers |
| `GET`  | `/api/v1/auth/me`     | Bearer        | Authenticated caller's identity + roles |
| `GET`  | `/api/v1/student/dashboard`          | Bearer (student) | Profile + today's schedule + attendance summary |
| `GET`  | `/api/v1/student/profile`           | Bearer (student) | Caller's own student profile |
| `GET`  | `/api/v1/student/schedule`          | Bearer (student) | Caller's own weekly class meetings |
| `GET`  | `/api/v1/student/attendance/history`| Bearer (student) | Caller's own attendance records (paginated) |

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

**Manual attendance (ATTENDANCE_ALGORITHM.md §6, `attendance.record.update`):**
`PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`
(`attendanceId` = session id, `studentId` = `students.id`, body `{status, reason?}`)
runs the 8-step sequence (ownership, membership, status + transition validation)
and updates the record (`source = MANUAL`) + writes a full `audit_logs` entry —
in one transaction. Students never hold this permission and can never modify
their own attendance; a teacher can override a QR-submitted status.

**Teacher live attendance (PROJECT_SPECIFICATION.md §6.8, `attendance.live.view`):**
`GET /api/v1/teacher/attendance/{id}/live` returns the dashboard snapshot
(session + current QR + live counters `TOTAL/WAITING/PRESENT/ABSENT/LATE/EXCUSED/PENDING_REVIEW`
+ student roster). Poll `.../live/counters` every `poll_interval_ms` and
re-fetch `.../live/students` (`?search=`, `?status=`, `?updated_since=`) when
`students_version` changes (AJAX polling — the spec's fallback). Session
ownership is re-checked on **every** request; only that session's students are
returned; `session_secret` is never exposed.

**Student self-service (Phase 16 — consumed by the mobile app):**
`GET /api/v1/student/profile` (`profile.self.view`), `GET /api/v1/student/schedule`
(`schedule.self.view`), `GET /api/v1/student/attendance/history`
(`attendance.history.self.view`, paginated), and `GET /api/v1/student/dashboard`
(profile + today's schedule + attendance summary + recent records). Every response
is scoped to the caller's own `students` row — the student id is resolved
server-side from the token, never taken from the request. Teachers and admins
without the self-view permissions receive 403.

**Attendance reporting (Phase 21):** see the dedicated section below —
`/api/v1/{student,teacher,admin}/reports/*`, each permission-gated and scoped to
the caller's role.

List endpoints accept `?page`, `?per_page`, `?search`, and id filters
(e.g. `?school_id=`, `?academic_term_id=`). Responses are paginated with a `meta` block.

Authorization is enforced server-side via `AuthorizationService` (role,
permission, ownership, relationship) and `AuthorizationMiddleware` /
`AbstractResourceController::guard()`. Permissions are seeded by
`database/migrations/002_seed_rbac_permissions.sql`.

**Attendance session lifecycle (teacher):**
`POST /api/v1/teacher/attendance/start` (`attendance.session.start`),
`GET /api/v1/teacher/attendance/{id}`,
`GET /api/v1/teacher/attendance/{id}/qr` (`attendance.live.view`),
`PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`
(`attendance.record.update`),
`POST /api/v1/teacher/attendance/{id}/close` (`attendance.session.close` —
ACTIVE → CLOSED, remaining WAITING → ABSENT|PENDING_REVIEW per `system_settings`,
transactional + audited + concurrency-safe, `ATTENDANCE_ALGORITHM.md` §7),
`POST /api/v1/teacher/attendance/{id}/cancel` (`attendance.session.cancel` —
ACTIVE → CANCELLED, records untouched, reason audited).

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

### Device & session security (PROJECT_SPECIFICATION.md §6.13)

Every authenticated session is a `device_sessions` row (hashed tokens only). On
login/refresh the server derives a device **fingerprint** —
`sha256(X-Device-Id | User-Agent)`, never sent by the client — and stores it with
an optional `X-Device-Name`. On every authenticated request `DeviceSessionService`:

- enforces an optional **idle timeout** (`SECURITY_SESSION_IDLE_TIMEOUT`) on top
  of the absolute `expires_at`;
- compares the request fingerprint to the session's — a mismatch always records a
  `SUSPICIOUS_DEVICE` event and feeds a `DEVICE_MISMATCH` risk signal, and is
  rejected (401) only when `SECURITY_ENFORCE_DEVICE_BINDING=true`;
- records a `NEW_DEVICE` event when a user authenticates from an unseen
  fingerprint, and a `SUSPICIOUS_DEVICE` event when active sessions exceed
  `SECURITY_MAX_ACTIVE_SESSIONS`.

The attendance challenge-response pipeline (step 12) folds these device signals
into risk scoring (step 13). Fingerprints are signals only — never an
authorization input. Thresholds live in `config/security.php` (not hard-coded);
`.env` keys `SECURITY_*`.

### Risk scoring (PROJECT_SPECIFICATION.md §6.14 / ATTENDANCE_ALGORITHM.md §9)

`RiskScoringService` is the **single** implementation of `RiskEvaluatorInterface`
— every attendance attempt is scored there and nowhere else (step 13 of the
challenge-response pipeline). The signal catalogue is fixed in
`Domain\Enum\RiskSignal` — exactly the ten spec signals, no more:

| category | signals |
|---|---|
| QR / challenge | `EXPIRED_QR`, `REPLAY_ATTEMPT`, `INVALID_CHALLENGE` |
| attendance | `EXCESSIVE_RETRY`, `DUPLICATE_ATTENDANCE`, `UNAUTHORIZED_RELATIONSHIP` |
| device | `NEW_DEVICE`, `MULTIPLE_DEVICE_ACTIVITY` |
| environment | `SUSPICIOUS_IP`, `LOCATION_MISMATCH` |

Detection: device signals arrive from `DeviceSessionService`; `EXCESSIVE_RETRY`
from the challenge count in the window; the QR / challenge / duplicate /
unauthorized signals from the caller's recent `security_events` (look-back
window); `SUSPICIOUS_IP` from a configured deny-list (empty by default — OQ-010);
`LOCATION_MISMATCH` only when a location signal is supplied (OQ-003).

Scoring: `score = Σ weight(signal)`, capped at 100 → level by the MEDIUM / HIGH /
BLOCKED thresholds → outcome by the fixed §9 table
(`RiskLevel::toOutcome()`: LOW/MEDIUM → PRESENT, HIGH → PENDING_REVIEW,
BLOCKED → no record). MEDIUM and HIGH also record a `RISK_ESCALATION` event;
BLOCKED records `BLOCKED_ATTENDANCE`. Every result is persisted to
`risk_assessments` (level, score, signals, outcome).

Every weight, threshold and window is configuration, never hard-coded (spec
§6.14). Resolution order: **`system_settings` row → `config/risk.php` (`.env`
`RISK_*`) → `RiskSignal::defaultWeight()`**. Migration `008` seeds the
`system_settings` rows.

### Security events & audit logging (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §10 / §11)

`SecurityLogService` is the single choke point for `security_events` and
`audit_logs` (both append-only). Every payload is passed through
`Domain\Security\LogSanitizer` **before persistence** — a recursive pass that
redacts sensitive keys at any depth (`password`, `*token*`, `*secret*`,
`authorization`, `private_key`, `nonce`, `signature`, `fingerprint`, …) and
token-shaped values (PEM keys, JWTs, long bare hex / base64). The same sanitizer
runs in `Logger` for file lines and again in `AuditQueryService` on read.

Audit categories: attendance changes (`ATTENDANCE_STATUS_CHANGED` /
`ATTENDANCE_RECORDED`), administrative actions (`{ENTITY}_CREATED/UPDATED/DELETED`,
`USER_ROLE_ATTACHED`), authentication events (`LOGIN_SUCCESS`, `LOGOUT`,
`TOKEN_REFRESHED`), and the `security_events` stream itself.

Read (admin, paginated + filtered, newest first):

| Method | Endpoint | Auth |
|--------|----------|------|
| `GET` | `/api/v1/admin/security-events` | `security.event.view` |
| `GET` | `/api/v1/admin/audit-logs` | `audit.log.view` |

`security-events` filters: `event_type`, `severity`, `user_id`,
`attendance_session_id`, `from`, `to`. `audit-logs` filters: `event_type`,
`actor_user_id`, `target_entity`, `target_id`, `from`, `to`.

### Attendance reporting (PROJECT_SPECIFICATION.md §6.16)

Read-only aggregations over `attendance_records` / `attendance_sessions`. One
repository (`Report\AttendanceReportRepository`), one query/validation base
(`Report\AbstractReportService`), one service per role. Authorization is
enforced **before any row is read**.

| Method | Endpoint | Auth | Scope |
|--------|----------|------|-------|
| `GET` | `/api/v1/student/reports/attendance` | `report.self.view` | own records only (student id from token) |
| `GET` | `/api/v1/teacher/reports/course/{id}` | `report.course.view` | a course the teacher teaches — per-session |
| `GET` | `/api/v1/teacher/reports/class/{id}` | `report.course.view` | a class the teacher is assigned to — per-student |
| `GET` | `/api/v1/teacher/reports/student/{id}` | `report.course.view` | a student's records, restricted to the teacher's own classes/courses |
| `GET` | `/api/v1/admin/reports/institution` | `report.institution.view` | institution-wide |
| `GET` | `/api/v1/admin/reports/department/{id}` | `report.institution.view` | a department |
| `GET` | `/api/v1/admin/reports/course/{id}` | `report.institution.view` | a course |
| `GET` | `/api/v1/admin/reports/attendance-statistics` | `report.institution.view` | institution-wide stats |

Filters (whitelisted per report): `course_id`, `class_id`, `academic_term_id`,
`status`, `source`, `session_status`, `from`, `to`, plus institutional ids for
admin reports; invalid input → 422. Row-level lists (`sessions`, `students`,
`records`) carry a `meta` block (`page`, `per_page`, `total`, `total_pages`);
`per_page` is capped at 100. Every report returns a `summary` block with per-
status counts and `present_rate` (= present / marked, where marked excludes
`WAITING`).

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
- [x] Phase 14 — Manual Attendance
- [x] Phase 15 — Session Close / Cancel *(delivered in Phase 22)*
- [x] Phase 16 — Mobile Application Foundation *(backend: student self-service)*
- [x] Phase 17 — Mobile QR Attendance *(mobile-only; no backend change)*
- [x] Phase 18 — Device Session Security
- [x] Phase 19 — Risk Scoring
- [x] Phase 20 — Security Events & Audit
- [x] Phase 21 — Attendance Reporting
- [x] Phase 22 — Integration Testing *(`tests/TEST_REPORT.md`)*
- [ ] ...

See [`docs/PROJECT_SPECIFICATION.md`](../docs/PROJECT_SPECIFICATION.md) for the full phase list.

---

## References

- [Project Specification](../docs/PROJECT_SPECIFICATION.md)
- [Architecture Rules](../docs/ARCHITECTURE_RULES.md)
- [Security Rules](../docs/SECURITY_RULES.md)
- [Attendance Algorithm](../docs/ATTENDANCE_ALGORITHM.md)
- [Changelog](../CHANGELOG.md)
