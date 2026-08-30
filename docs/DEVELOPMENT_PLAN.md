# QRIVO — Development Plan

> **Phased implementation plan derived from `ORIGINAL_SPECIFICATION.md`.**
> Each phase is independently testable. No phase is complete until tests pass, commit exists, and push succeeds.

---

## Current Status (updated 2026-08-30)

| Phase | State | Commit |
|-------|-------|--------|
| 0 — Project Foundation | ✅ Complete | `528b413`, `13a244a` |
| 1 — Specification & Architecture | ✅ Complete | `8b56c39` |
| 2 — Architecture Freeze | ✅ Complete | `a837af2` |
| 3 — Database Architecture | ✅ Complete | `a837af2` |
| 4 — Database Migrations | 🔄 Restructured — now incremental per feature phase (see note below) | — |
| 5 — Backend Foundation | ✅ Complete | `ec1e411` |
| 6 — Authentication | ✅ Complete | `feat(auth): implement QRIVO authentication` |
| 7 — Authorization & RBAC | ⏭️ Next | — |
| 8–23 | ⛔ Not started | — |

**Test status at Phase 6:** 155 tests, 265 assertions, 100% passing (`backend/`).

### Migration strategy note (deviation AD-001)

Phase 4 was **not** executed as a single monolithic migration phase. Instead,
database migrations are authored **incrementally, per feature/domain phase**, in
the same commit as the code that depends on them. The complete schema is already
designed and frozen in `database/docs/`; each migration file is a transcription
of one domain group from that frozen design, numbered in FK-dependency order
(`001_`, `002_`, …). See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md)
AD-001. A consolidated `database/docs/MIGRATION_GUIDE.md` will be added once the
schema is substantially complete.

### Accepted deviations

Deliberate, reviewed differences from the literal source wording are tracked in
[`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md): AD-001 (incremental
migrations), AD-002 (login checks password before account-state), AD-003
(`course_schedules` plural), AD-004 (`PENDING_REVIEW` status — resolved via
OQ-001). `ORIGINAL_SPECIFICATION.md` remains unchanged.

---

## Development Workflow

```
ALGORITHM / ARCHITECTURE
        ↓
    ANALYSIS
        ↓
      PLAN
        ↓
 IMPLEMENTATION
        ↓
      TEST
        ↓
   GIT DIFF
        ↓
    COMMIT
        ↓
  GITHUB PUSH
        ↓
  NEXT PHASE
```

---

## Phase 0: Project Foundation

- [x] Create project repository
- [x] Initialize Git
- [x] Create `AGENTS.md`
- [x] Create `.gitignore`
- [x] Create `.env.example`
- [x] Create `README.md`
- [x] Create `CHANGELOG.md`
- [x] Configure GitHub remote
- [x] Initial commit and push

**Commit:** `chore: initialize QRIVO project foundation`

---

## Phase 1: Specification & Architecture

- [x] Read `ORIGINAL_SPECIFICATION.md`
- [x] Create `docs/PROJECT_SPECIFICATION.md`
- [x] Create `docs/ARCHITECTURE_RULES.md`
- [x] Create `docs/ATTENDANCE_ALGORITHM.md`
- [x] Create `docs/SECURITY_RULES.md`
- [x] Create `docs/DEVELOPMENT_PLAN.md`
- [x] Create `docs/OPEN_QUESTIONS.md`

**Commit:** `docs: synchronize QRIVO specification and architecture`

---

## Phase 2: Architecture Freeze — ✅ COMPLETE (`a837af2`)

- [x] Create `docs/ARCHITECTURE_FREEZE.md`
- [x] Document all locked architectural decisions
- [x] Define component responsibilities, inputs, outputs, dependencies, security boundaries

**Commit:** `docs(database): define QRIVO database architecture` (`a837af2` — bundled with Phase 3)

---

## Phase 3: Database Architecture — ✅ COMPLETE (`a837af2`)

- [x] Design complete MySQL 8 database schema
- [x] Create ER diagram
- [x] Document tables, relationships, indexes, constraints
- [x] Validate model consistency

**Commit:** `docs(database): define QRIVO database architecture` (`a837af2`)

---

## Phase 4: Database Migrations — 🔄 RESTRUCTURED (incremental per feature phase)

This phase is **no longer executed as one monolithic step.** Migrations are
created incrementally, per feature/domain, in the same commit as the dependent
backend code. See the "Migration strategy note" above and
[`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-001.

- [x] `database/migrations/` established, files numbered in FK-dependency order
- [x] InnoDB, utf8mb4, foreign keys, indexes, unique constraints (per migration)
- [x] `001_create_auth_tables.sql` — identity & access + security/audit tables (delivered with Phase 6)
- [ ] `002+` — academic structure, assignments, attendance, QR, risk (delivered with their phases)
- [ ] `database/seeders/` — added when seed data beyond default roles is needed
- [ ] `database/docs/MIGRATION_GUIDE.md` — added once the schema is substantially complete

**Commits:** one per feature phase (e.g. `001_...` shipped in the Phase 6 commit).

---

## Phase 5: Backend Foundation — ✅ COMPLETE (`ec1e411`)

- [x] PHP 8.3+ project setup with Composer
- [x] Application bootstrap, configuration, environment
- [x] Database connection (PDO)
- [x] Router, request/response handling
- [x] Exception handling, logging
- [x] Middleware pipeline
- [x] Service layer, repository layer infrastructure
- [x] Validation infrastructure
- [x] `backend/README.md`
- [x] Automated foundation tests (118 tests)

**Commit:** `feat(backend): establish QRIVO backend foundation` (`ec1e411`)

---

## Phase 6: Authentication — ✅ COMPLETE

- [x] `POST /api/v1/auth/login`
- [x] `POST /api/v1/auth/logout`
- [x] `POST /api/v1/auth/refresh`
- [x] Password verification (Argon2id) with constant-time dummy verify for unknown users
- [x] Rate limiting (IP + email, config-driven), login attempt tracking
- [x] Audit logging (LOGIN_SUCCESS, LOGOUT) and security event logging (all failure paths)
- [x] Refresh token rotation + token reuse detection (`TOKEN_REUSE` security event)
- [x] Tokens stored as SHA-256 hashes only; raw tokens never persisted or logged
- [x] `001_create_auth_tables.sql` migration
- [x] Tests: valid login, invalid login, enumeration safety, rate limit, logout, refresh, rotation, reuse (37 tests)

**Note:** Login performs password verification *before* active/approved checks to
prevent account-state enumeration — see [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-002.

**Commit:** `feat(auth): implement QRIVO authentication`

---

## Phase 7: Authorization & RBAC

- [ ] Role-based authorization (SUPER_ADMIN, ADMIN, TEACHER, STUDENT)
- [ ] Permission-based checks
- [ ] Resource ownership validation
- [ ] Relationship-based authorization
- [ ] IDOR/BOLA protection
- [ ] Authorization tests

**Commit:** `feat(auth): implement QRIVO authorization and RBAC`

---

## Phase 8: Academic Structure

- [ ] Schools, Faculties, Departments, Programs
- [ ] Academic Years, Academic Terms
- [ ] Classes, Rooms, Courses
- [ ] Teachers, Students
- [ ] Model, Repository, Service, Validation, Authorization, Controller, API, Tests

**Commit:** `feat(admin): implement QRIVO academic structure`

---

## Phase 9: Course Scheduling

- [ ] class_courses, teacher_courses, teacher_class_assignments
- [ ] student_class_assignments, student_courses, course_schedule
- [ ] Teacher-course-class-room-time validation
- [ ] Authorization and tests

**Commit:** `feat(schedule): implement QRIVO course scheduling`

---

## Phase 10: Attendance Sessions

- [ ] `POST /api/v1/teacher/attendance/start`
- [ ] Full 10-step validation sequence
- [ ] Session creation with transaction
- [ ] Student initialization (WAITING)
- [ ] Duplicate active session prevention
- [ ] Automated tests

**Commit:** `feat(attendance): implement QRIVO attendance sessions`

---

## Phase 11: Dynamic QR System

- [ ] QR generation service (session_id, timestamp, nonce, HMAC-SHA256 signature)
- [ ] QR refresh mechanism
- [ ] QR expiry validation
- [ ] QR replay protection
- [ ] Security tests

**Commit:** `feat(qr): implement QRIVO dynamic QR system`

---

## Phase 12: Challenge-Response Attendance

- [ ] Challenge generation (challenge_id, nonce, expires_at)
- [ ] Challenge validation and single-use enforcement
- [ ] Full verification pipeline (QR → Challenge → Validation → Transaction)
- [ ] Replay protection, duplicate attendance protection
- [ ] All failure path tests

**Commit:** `feat(security): implement QRIVO challenge-response attendance`

---

## Phase 13: Teacher Live Attendance

- [ ] Teacher attendance dashboard
- [ ] QR display, session info, student list
- [ ] Live counters (TOTAL, WAITING, PRESENT, ABSENT, LATE, EXCUSED)
- [ ] WebSocket or AJAX polling
- [ ] Responsive layout

**Commit:** `feat(teacher): implement QRIVO live attendance`

---

## Phase 14: Manual Attendance

- [ ] `PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`
- [ ] State transitions with audit logging
- [ ] Student self-modification blocked
- [ ] Authorization and audit tests

**Commit:** `feat(attendance): implement QRIVO manual attendance`

---

## Phase 15: Session Close/Cancel

- [ ] `POST /api/v1/teacher/attendance/{id}/close`
- [ ] ACTIVE → CLOSED flow
- [ ] CANCELLED support
- [ ] WAITING → ABSENT/PENDING_REVIEW
- [ ] Transaction, audit, concurrent protection

**Commit:** `feat(attendance): implement QRIVO session closing`

---

## Phase 16: Mobile Application Foundation

- [ ] Flutter project structure
- [ ] API client, environment config
- [ ] Authentication with secure token storage
- [ ] Student Dashboard, Profile, Schedule, Attendance History

**Commit:** `feat(mobile): initialize QRIVO mobile application`

---

## Phase 17: Mobile QR Attendance

- [ ] QR scanner
- [ ] QR → Challenge → Verification → Result flow
- [ ] Error handling for all failure states
- [ ] Network error handling

**Commit:** `feat(mobile): implement QRIVO QR attendance flow`

---

## Phase 18: Device Session Security

- [ ] Device registration, session tracking
- [ ] Suspicious device detection
- [ ] Integration with risk scoring

**Commit:** `feat(security): implement QRIVO device session security`

---

## Phase 19: Risk Scoring

- [ ] Risk signal evaluation service
- [ ] Configurable risk levels (LOW, MEDIUM, HIGH, BLOCKED)
- [ ] Integration with attendance pipeline
- [ ] Tests for each risk scenario

**Commit:** `feat(security): implement QRIVO risk scoring`

---

## Phase 20: Security Events & Audit

- [ ] Structured security event logging
- [ ] Audit trails for administrative and attendance changes
- [ ] Safe logging (no passwords/tokens/keys)

**Commit:** `feat(security): implement QRIVO audit and security events`

---

## Phase 21: Reporting

- [ ] Teacher, Admin, Student reports
- [ ] Authorization enforced on all reports
- [ ] Pagination, filtering

**Commit:** `feat(reports): implement QRIVO attendance reporting`

---

## Phase 22: Integration Testing

- [ ] Complete end-to-end flow test
- [ ] All security failure path tests
- [ ] `tests/TEST_REPORT.md`

**Commit:** `test: complete QRIVO integration validation`

---

## Phase 23: Final Audit

- [ ] Architecture integrity verification
- [ ] Security audit
- [ ] `docs/FINAL_AUDIT.md`

**Commit:** `chore: finalize QRIVO release`
