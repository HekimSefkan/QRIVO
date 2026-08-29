# QRIVO — Development Plan

> **Phased implementation plan derived from `ORIGINAL_SPECIFICATION.md`.**
> Each phase is independently testable. No phase is complete until tests pass, commit exists, and push succeeds.

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

## Phase 2: Architecture Freeze

- [ ] Create `docs/ARCHITECTURE_FREEZE.md`
- [ ] Document all locked architectural decisions
- [ ] Define component responsibilities, inputs, outputs, dependencies, security boundaries

**Commit:** `docs: freeze QRIVO system architecture`

---

## Phase 3: Database Architecture

- [ ] Design complete MySQL 8 database schema
- [ ] Create ER diagram
- [ ] Document tables, relationships, indexes, constraints
- [ ] Validate model consistency

**Commit:** `docs(database): define QRIVO database architecture`

---

## Phase 4: Database Migrations

- [ ] Create `database/migrations/` in dependency order
- [ ] Create `database/seeders/`
- [ ] InnoDB, utf8mb4, foreign keys, indexes, unique constraints
- [ ] Validate migrations
- [ ] Create `database/docs/MIGRATION_GUIDE.md`

**Commit:** `feat(database): implement QRIVO database migrations`

---

## Phase 5: Backend Foundation

- [ ] PHP 8.3+ project setup with Composer
- [ ] Application bootstrap, configuration, environment
- [ ] Database connection (PDO)
- [ ] Router, request/response handling
- [ ] Exception handling, logging
- [ ] Middleware pipeline
- [ ] Service layer, repository layer infrastructure
- [ ] Validation infrastructure
- [ ] `backend/README.md`
- [ ] Automated foundation tests

**Commit:** `feat(backend): establish QRIVO backend foundation`

---

## Phase 6: Authentication

- [ ] `POST /api/v1/auth/login`
- [ ] `POST /api/v1/auth/logout`
- [ ] `POST /api/v1/auth/refresh`
- [ ] Password verification (Argon2id)
- [ ] Rate limiting, login tracking
- [ ] Audit and security event logging
- [ ] Tests: valid login, invalid login, rate limit, token operations

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
