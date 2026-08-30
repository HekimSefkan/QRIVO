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
| 4 — Database Migrations | 🔄 Restructured — now incremental per feature phase (see note below) | `001`–`007` |
| 5 — Backend Foundation | ✅ Complete | `ec1e411` |
| 6 — Authentication | ✅ Complete | `9c5a378` |
| 7 — Authorization & RBAC | ✅ Complete | `c4e3863` |
| 8 — Academic Structure | ✅ Complete | `8321d43` |
| 9 — Course Scheduling | ✅ Complete | `15942d7` |
| 10 — Attendance Sessions | ✅ Complete | `f3438f7` |
| 11 — Dynamic QR System | ✅ Complete | `9cb3ba1` |
| 12 — Challenge-Response Attendance | ✅ Complete | `a512561` |
| 13 — Teacher Live Attendance | ✅ Complete (backend) | `adbcebf` |
| 14 — Manual Attendance | ✅ Complete | `abe191c` |
| 15 — Session Close/Cancel | ⏭️ Deferred — skipped for now, still pending | — |
| 16 — Mobile Application Foundation | ✅ Complete | `abe191c` |
| 17 — Mobile QR Attendance | ✅ Complete | `8306ba6` |
| 18 — Device Session Security | ✅ Complete | `74a4ff2` |
| 19 — Risk Scoring | ✅ Complete | `0eb36f7` |
| 20 — Security Events & Audit | ✅ Complete | `26076f8` |
| 21 — Reporting | ✅ Complete | `feat(reports): implement QRIVO attendance reporting` |
| 22–23 | ⛔ Not started | — |

**Test status at Phase 21:** backend **553 tests, 1274 assertions, 100% passing**
(`backend/`). Mobile: 10 Dart test files under `mobile/test/`; the Flutter SDK is
not available on the development machine, so `flutter test` runs in CI / on a
developer workstation (see AD-011).

### Migration strategy note (deviation AD-001)

Phase 4 was **not** executed as a single monolithic migration phase. Instead,
database migrations are authored **incrementally, per feature/domain phase**, in
the same commit as the code that depends on them. The complete schema is already
designed and frozen in `database/docs/`; each migration file is a transcription
of one domain group from that frozen design, numbered in FK-dependency order
(`001_`, `002_`, …). Current: `001`–`008` (`008` = `system_settings`, added in
Phase 19 to hold risk-scoring parameters). See
[`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-001. A consolidated
`database/docs/MIGRATION_GUIDE.md` will be added once the schema is substantially
complete.

### Accepted deviations

Deliberate, reviewed differences from the literal source wording are tracked in
[`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md): AD-001 (incremental
migrations), AD-002 (login checks password before account-state), AD-003
(`course_schedules` plural), AD-004 (`PENDING_REVIEW` status — resolved via
OQ-001), AD-005 (SUPER_ADMIN = full system access; permission names derived from
spec §6 — interim resolution of OQ-005), AD-006 (teacher-class-assignment
prerequisite rule + schedule conflict checks; `student_courses` read-only),
AD-007 (session `end_time` NULL until close; `expires_at` = scheduled meeting
end; duplicate-active-session scope), AD-008 (`qr_used_nonces` nonce store; QR
wire format; QR TTL config — interim OQ-008), AD-009 (per-student QR-nonce replay;
basic Phase-12 risk evaluator; challenge TTL config), AD-010 (live attendance =
AJAX polling; WebSocket deferred — no WS server in the frozen stack), AD-011
(mobile project hand-scaffolded; generated platform folders gitignored; Flutter
tests authored but run in CI), AD-012 (`mobile_scanner` for camera/QR decode;
local `qrivo.` prefix sniff is a UX filter, not a security check), AD-013 (device
fingerprint = server-derived `sha256(X-Device-Id | UA)`; fingerprint binding is
log-only unless `enforce_fingerprint_binding`; device thresholds in
`config/security.php` not `system_settings`; no migration needed), AD-014 (risk
scoring = additive weighted model; the 10 spec signals only; weights/thresholds
in `system_settings` → `config/risk.php` → defaults; `LOCATION_MISMATCH` and
`SUSPICIOUS_IP` data-gated per OQ-003 / OQ-010), AD-015 (central `LogSanitizer`
redaction pass on all persisted + file logs; read-only admin trail endpoints
under `/api/v1/admin/{security-events,audit-logs}` using the pre-defined
view permissions), AD-016 (reporting: `present_rate` = present / marked;
student report endpoint sits alongside the Phase-16 history list; no per-admin
institutional partition — `report.institution.view` is institution-wide).
`ORIGINAL_SPECIFICATION.md` remains unchanged.

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

## Phase 7: Authorization & RBAC — ✅ COMPLETE

- [x] Role-based authorization (SUPER_ADMIN, ADMIN, TEACHER, STUDENT) — `AuthorizationService`
- [x] Permission-based checks — `Permission` enum, `permissions` / `role_permissions` seeded by `002_seed_rbac_permissions.sql`, resolved from the database via `PermissionRepository`
- [x] Resource ownership validation — `AuthorizationService::requireOwnership()`, `SelfOwnedResourcePolicy`
- [x] Relationship-based authorization — `RelationshipRepository` + teacher/student predicates (`AttendanceAuthorizationPolicy`); enforcement tables land in Phase 8 (AD-001), resolver is deny-by-default until then
- [x] IDOR/BOLA protection — ownership guards log `IDOR_ATTEMPT`; relationship guards log `UNAUTHORIZED_ACCESS`
- [x] Privilege-escalation protection — `guardRoleAssignment()` (no self-escalation; only SUPER_ADMIN grants SUPER_ADMIN); denials log `PRIVILEGE_ESCALATION`
- [x] Server-side enforcement points — `BaseController::authenticate()` + `authorization()`, `AuthMiddleware`, `AuthorizationMiddleware`; `GET /api/v1/auth/me`
- [x] Authorization tests (48 new)

**Note:** SUPER_ADMIN is treated as full system access (SECURITY_RULES.md §4) — interim
resolution of OQ-005, recorded there and in [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-005.
Permission names are derived from PROJECT_SPECIFICATION.md §6.

**Commit:** `feat(auth): implement QRIVO authorization and RBAC`

---

## Phase 8: Academic Structure — ✅ COMPLETE

- [x] Schools, Faculties, Departments, Programs
- [x] Academic Years, Academic Terms
- [x] Classes, Rooms, Courses
- [x] Teachers, Students
- [x] Model (`src/Domain/Entity/Academic/`), Repository (`.../Repository/Academic/`),
      Service (`.../Service/Academic/`), Validation (per-entity rules + `date` /
      `integer_range` rules), Authorization (`academic.*.manage` — ADMIN /
      SUPER_ADMIN, enforced in `AbstractResourceController::guard()`),
      Controller (`.../Controller/Admin/`), REST API
      (`/api/v1/admin/{resource}` — index/show/store/update/destroy), Tests (40 new)
- [x] Migration `003_create_academic_structure.sql` (11 tables, FK RESTRICT, soft delete per DD-009)
- [x] Relationships enforced at **both** layers: FK constraints in the DB;
      `ReferenceRepository` parent-existence checks (incl. soft-deleted parents)
      and `blockingChildren` delete guards in the service

**Notes:**
- Teacher/Student profiles link an existing `users` account and attach the
  non-privileged TEACHER/STUDENT role on creation — interim handling of OQ-004
  (user account provisioning remains out of scope).
- Assignment/scheduling tables (`class_courses`, `teacher_courses`,
  `teacher_class_assignments`, `student_class_assignments`, `student_courses`,
  `course_schedules`) are Phase 9 — not created here.

**Commit:** `feat(admin): implement QRIVO academic structure`

---

## Phase 9: Course Scheduling — ✅ COMPLETE

- [x] `class_courses`, `teacher_courses`, `teacher_class_assignments` — entity, repo, service, validation, admin REST API
- [x] `student_class_assignments`, `student_courses` (derived — read-only API, kept in sync per DD-005), `course_schedules`
- [x] Teacher-course-class-room-time validation:
      - `teacher_class_assignments` requires a matching `class_courses` + `teacher_courses` (coherent intersection — spec §6.4)
      - `course_schedules` validates `day_of_week` 0–6, `start < end`, room double-booking, teacher double-booking
- [x] **Attendance authorization determination** — `AttendanceEligibilityService::forTeacher()` answers
      "may this teacher open attendance for course/class at time T, and in which room?"
      (ATTENDANCE_ALGORITHM.md §2 steps 2–9, pre-QR); exposed as
      `GET /api/v1/teacher/attendance/eligibility` (`attendance.session.start`, TEACHER)
- [x] Authorization: `assignment.course.manage` / `assignment.schedule.manage` (ADMIN / SUPER_ADMIN) on the admin routes; teacher permission on the eligibility route
- [x] Migration `004_create_course_scheduling.sql` (6 tables, FK RESTRICT, no soft delete)
- [x] Tests (37 new)

**Notes:**
- `student_courses` is derived (DD-005): populated from `class_courses` when a student is enrolled,
  pruned when unenrolled or when a course leaves the class. Its API is read-only.
- The `teacher_class_assignments` prerequisite rule and the schedule conflict checks are recorded
  in [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-006.
- Dynamic QR is NOT implemented.

**Commit:** `feat(schedule): implement QRIVO course scheduling`

---

## Phase 10: Attendance Sessions — ✅ COMPLETE

- [x] `POST /api/v1/teacher/attendance/start` (+ `GET /api/v1/teacher/attendance/{id}` — own session)
- [x] Full 10-step validation sequence (ATTENDANCE_ALGORITHM.md §2), implemented exactly and in order:
      1 authentication (`BaseController::authenticate`) · 2–8 `AttendanceEligibilityService`
      (teacher role+profile, `teacher_class_assignments`, `course_schedules` day/time, scheduled room) ·
      9 term `is_active` · 10 no ACTIVE session for (class, course, term)
- [x] Session creation inside a single DB transaction with a class row lock
      (CONSTRAINTS.md §6 — "SELECT FOR UPDATE on active session check")
- [x] Student initialization — one WAITING/SYSTEM `attendance_records` row per enrolled student
- [x] Duplicate active session prevention — per (class, course, term); 409
- [x] `session_secret` generated server-side, never returned (DD-002)
- [x] Migration `005_create_attendance_sessions.sql` (`attendance_sessions`, `attendance_records`;
      `UNIQUE(attendance_session_id, student_id)` = C-001; FK RESTRICT)
- [x] Automated tests (23 new)

**Notes:**
- `end_time` is NULL at creation and `expires_at` = the scheduled meeting end —
  see [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-007.
- Close / cancel / manual attendance / live counters / QR are later phases.

**Commit:** `feat(attendance): implement QRIVO attendance sessions`

---

## Phase 11: Dynamic QR System — ✅ COMPLETE

- [x] QR generation service — payload is exactly `{session_id (session UUID), timestamp, nonce, signature}`
      (ATTENDANCE_ALGORITHM.md §3); wire format `qrivo.v1.<uuid>.<ts>.<nonce>.<sig>`;
      no other data in the QR (SECURITY_RULES.md §5)
- [x] Nonce — 16 random bytes (hex), unique per generation
- [x] Signature — HMAC-SHA256 over `qrivo.v1.<uuid>.<ts>.<nonce>`, keyed by
      `attendance_sessions.session_secret` (DD-002); the secret never leaves the backend
- [x] QR refresh mechanism — each generation returns a fresh nonce + signature +
      `ttl_seconds` / `refresh_seconds`; old QRs stop validating
- [x] QR expiry validation — server-side timestamp check against the configured TTL (± clock skew)
- [x] QR replay protection — `qr_used_nonces` (nonce + expiration, per §10 / §5);
      `validateAndConsume()` records the nonce atomically, repeat → `REPLAYED`
- [x] Migration `006_create_qr_used_nonces.sql`
- [x] `config/attendance.php` + `QR_TTL_SECONDS` / `QR_REFRESH_SECONDS` / `QR_CLOCK_SKEW_SECONDS` env
- [x] API: `GET /api/v1/teacher/attendance/{id}/qr` (`attendance.live.view`, own ACTIVE session);
      `POST /api/v1/student/attendance/qr/verify` (`attendance.qr.submit`, non-consuming preflight)
- [x] Security tests (24 new): valid, expired, future-dated, modified/tampered, forged signature,
      wrong-session-secret signature, malformed, wrong session, unknown session, closed session,
      replay (+ security-event logging)

**Notes:**
- A QR does **not** create attendance — it is validated here and exchanged for a
  challenge in Phase 12.
- `qr_used_nonces` is the nonce store named in `ARCHITECTURE_FREEZE.md` §2.8; it
  is not one of the 31 domain tables — see [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-008.
- QR TTL default 30 s (OQ-008 interim).

**Commit:** `feat(qr): implement QRIVO dynamic QR system`

---

## Phase 12: Challenge-Response Attendance — ✅ COMPLETE

- [x] Challenge generation — `{ challenge_id, nonce, expires_at }` exactly (ATTENDANCE_ALGORITHM.md §4);
      server-generated globally-unique challenge nonce (C-002); `qr_nonce` stored (DD-004)
- [x] Challenge expiration — `expires_at` checked at verify; configurable TTL (default 120 s)
- [x] Nonce handling — challenge nonce is the proof-of-possession the mobile echoes back;
      returned once, never again (`QrChallenge::toArray()` omits it); constant-time compare
- [x] Single-use enforcement — atomic `UPDATE … SET used_at WHERE id = ? AND used_at IS NULL`
      inside the transaction (DD-003); pre-check + locked re-check
- [x] Challenge ownership — `challenge.student_id` must equal the authenticated student;
      QR resubmitted at verify must match `challenge.qr_nonce` and `challenge.session`
- [x] Replay prevention — used challenge → 409 + `QR_REPLAY`; per-student QR-nonce replay
      at challenge issuance via `qr_challenges.qr_nonce` (DD-004)
- [x] Duplicate attendance protection — step 10: locked `attendance_records` row must be `WAITING`;
      `UNIQUE(attendance_session_id, student_id)` (C-001) is the DB backstop
- [x] Transaction handling — atomic single-use + duplicate check + risk assessment + attendance
      record, all in one `Connection::transaction()` (CONSTRAINTS.md §6)
- [x] Full pipeline — scan → QR validation (2-4) → membership (8-9) → challenge (5-7) →
      challenge response → session re-check (2) → device/session hook (12) → risk (13) → attendance
- [x] Rate limiting (11) — max challenge requests per (student, session) per window → 429
- [x] Risk (13) — **basic** evaluator (retry pressure) always runs, always writes `risk_assessments`;
      LOW→PRESENT, MEDIUM→PRESENT+`RISK_ESCALATION`, HIGH→PENDING_REVIEW, BLOCKED→no record
      (challenge still consumed). Full engine + `system_settings` is Phase 19.
- [x] Migration `007_create_qr_challenges.sql` (`qr_challenges`, `risk_assessments`)
- [x] Failure-path tests (32 new) — every reason in `ChallengeFailureReason`, each with its
      security event, generic client message, HTTP status
- [x] API: `POST /api/v1/student/attendance/challenge`, `POST /api/v1/student/attendance/verify`
      (`attendance.qr.submit`, STUDENT)

**Notes:** per-student QR-nonce replay + basic risk evaluator + challenge TTL config —
[`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-009.

**Commit:** `feat(security): implement QRIVO challenge-response attendance`

---

## Phase 13: Teacher Live Attendance — ✅ COMPLETE (backend)

- [x] Teacher attendance dashboard data — `GET /api/v1/teacher/attendance/{id}/live`
      (session info + course/class/room/status + remaining time + current QR + counters + student list)
- [x] QR display, session info, student list — all in the snapshot; student rows carry
      name, number, status, source (QR/MANUAL), marked time (PROJECT_SPECIFICATION.md §6.8)
- [x] Live counters — `TOTAL, WAITING, PRESENT, ABSENT, LATE, EXCUSED` (+ `PENDING_REVIEW`)
      (ATTENDANCE_ALGORITHM.md §8)
- [x] Realtime — **AJAX polling** (the spec's documented fallback; the frozen stack has no
      WebSocket server): `GET .../live/counters` (lightweight, `students_version` change signal,
      `poll_interval_ms`) + `GET .../live/students` (`search` / `status` / `updated_since` filters).
      See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-010.
- [x] Search + filter support (§6.8)
- [x] Backend authorization on **every** request — `attendance.live.view` (TEACHER) **and**
      session-ownership re-check on each call; a non-owner → 403 + `IDOR_ATTEMPT`
      (ARCHITECTURE_FREEZE.md §2.12). Only this session's students are returned; `session_secret`
      is never exposed.
- [x] Tests (17 new) — snapshot / counters / students, ownership on every endpoint, filters,
      no cross-session leakage, delta version, and an end-to-end check that a QR attendance
      shows up in the live view.
- [ ] Responsive layout — a web-client concern (OQ-006); the API returns every piece §6.8's
      desktop and mobile layouts need.

**Commit:** `feat(teacher): implement QRIVO live attendance`

---

## Phase 14: Manual Attendance — ✅ COMPLETE

- [x] `PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`
      (`attendanceId` = attendance **session** id, `studentId` = `students.id`)
- [x] Full 8-step sequence (ATTENDANCE_ALGORITHM.md §6), in order:
      1 auth · 2 authz (`attendance.record.update` — TEACHER only) · 3 attendance ownership ·
      4 student membership · 5 status validation (∈ WAITING/PRESENT/ABSENT/LATE/EXCUSED) ·
      6 transition validation (session not CANCELLED; new ≠ current) · 7 update
      (status + `source = MANUAL` + `marked_at`) · 8 audit — **7 & 8 in one transaction**
- [x] Teacher can override a QR-submitted status
- [x] State transitions with audit logging — every change writes an `audit_logs`
      row (`ATTENDANCE_STATUS_CHANGED`) carrying **actor, target, previous state,
      new state, timestamp, reason (when given), ip** — plus `teacher_id` /
      `student_id` / `attendance_session_id` per the spec's audit-data table
- [x] Student self-modification blocked — at step 2 (students never hold
      `attendance.record.update`) **and** step 3 (only the owning teacher passes),
      plus an explicit "you cannot modify your own attendance" guard for a
      user who is both a teacher and a student in the same class (`UNAUTHORIZED_ATTENDANCE`)
- [x] Authorization + audit tests (27 new) — ownership, permission, self-mod,
      membership, every validation branch, and full audit-field assertions

**Commit:** `feat(attendance): implement QRIVO manual attendance`

---

## Phase 15: Session Close/Cancel — ⏭️ DEFERRED (still pending)

Skipped in the current sequence at the user's direction. No code exists yet; it
remains an open phase and should be picked up before Phase 20 (audit) closes.

- [ ] `POST /api/v1/teacher/attendance/{id}/close`
- [ ] ACTIVE → CLOSED flow
- [ ] CANCELLED support
- [ ] WAITING → ABSENT/PENDING_REVIEW
- [ ] Transaction, audit, concurrent protection

**Commit:** `feat(attendance): implement QRIVO session closing`

---

## Phase 16: Mobile Application Foundation — ✅ COMPLETE

Backend (student self-service, consumed by the mobile client):

- [x] `Infrastructure/Repository/StudentSelfRepository` — read-only, keyed by
      `students.id`; profile, schedule (own class meetings), attendance history
      (paginated), attendance summary
- [x] `Application/Service/Student/StudentSelfService` — resolves the caller's
      student id via `RelationshipRepository`; rejects non-students (403);
      dashboard aggregation (profile + today's schedule + summary + recent)
- [x] `Presentation/Http/Controller/Student/SelfController` — permission-gated
      (`PROFILE_SELF_VIEW`, `SCHEDULE_SELF_VIEW`, `ATTENDANCE_HISTORY_SELF_VIEW`)
- [x] Routes: `GET /api/v1/student/{dashboard,profile,schedule}`,
      `GET /api/v1/student/attendance/history`
- [x] Tests: `StudentSelfServiceTest` (own-data only, pagination order, non-student
      rejection, dashboard), `StudentSelfRoutesTest` (student 200 / unauthenticated
      401 / teacher 403 / admin-without-self-perms 403 on all four)

Mobile (`mobile/`, Flutter):

- [x] Project structure — `core/{config,api,auth,models}`, `features/*`, `widgets/`
- [x] `ApiClient` — `/api/v1` base, bearer header, envelope unwrap, 401→refresh→retry
      once, `ApiException` mapping; config via `--dart-define=QRIVO_API_BASE_URL`
- [x] Authentication — `AuthRepository` (bare client, no recursion), `AuthController`
      (`ChangeNotifier`: bootstrap/validate, sign in/out, single-flight refresh),
      `SecureSessionStore` (Keychain / EncryptedSharedPreferences, one JSON blob)
- [x] Student Dashboard, Profile, Schedule, Attendance History screens + bottom-nav
      shell (no QR tab — Phase 17)
- [x] Tests: 7 Dart test files under `mobile/test/` — see `mobile/README.md`

**No client-side security logic** — the backend remains authoritative
(SECURITY_RULES.md). The QR scanner is deliberately out of scope (Phase 17,
`PROJECT_SPECIFICATION §6.11`).

See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-011 (hand-scaffolded
project; platform folders gitignored; `flutter test` runs in CI, not locally).

**Commit:** `feat(mobile): initialize QRIVO mobile application`

---

## Phase 17: Mobile QR Attendance — ✅ COMPLETE

Mobile-only. No backend change — the student attendance endpoints
(`POST /api/v1/student/attendance/{qr/verify,challenge,verify}`) were built and
tested in Phases 11–12; this phase is the client that drives them.

- [x] QR scanner — `features/attendance/qr_scanner_screen.dart` (`mobile_scanner`,
      QR-only, torch, denied/unavailable-camera handling). Camera code is isolated
      from the flow logic.
- [x] scan → QR validation → challenge → challenge response → server verification
      → attendance result — `QrAttendanceController` (`ChangeNotifier`, camera-free)
      runs the steps in the exact order of `ATTENDANCE_ALGORITHM.md §4`:
      preflight `POST /qr/verify` (non-consuming) → `POST /challenge` →
      `POST /verify` (echoes the server nonce back with the scanned QR).
- [x] Error handling for all defined failure states — every
      `ATTENDANCE_ALGORITHM.md §4/§9` failure maps to a `QrFailureKind` for
      presentation only (icon + retry-vs-final). The server's generic message is
      shown verbatim; the app infers no security specifics: expired QR, tampered
      (bad signature), session unavailable/closed, not enrolled, QR/challenge
      replay, challenge expired, rate-limited, risk-blocked, re-auth, duplicate.
- [x] Network error handling — transport failures surface as a retryable
      "couldn't reach the server" state; `ApiClient`'s one-shot 401→refresh→retry
      still applies underneath.
- [x] **No security decision on the device.** The only local logic is a `qrivo.`
      prefix sniff so an unrelated barcode doesn't hit the attendance API; the
      server re-validates everything it accepts. See AD-012.
- [x] Tests: `features/qr_attendance_controller_test.dart` (happy path + each
      failure state + concurrency), `features/attendance_result_sheet_test.dart`,
      `core/attendance_models_test.dart`.

**Commit:** `feat(mobile): implement QRIVO QR attendance flow`

---

## Phase 18: Device Session Security — ✅ COMPLETE

Implements only the mechanisms the frozen `device_sessions` schema supports
(PROJECT_SPECIFICATION.md §6.13). **No migration** — every column already exists
(migration `001`).

- [x] Device registration / session identification —
      `Domain\Security\DeviceContext` derives `sha256(X-Device-Id | User-Agent)`
      server-side; `AuthService` persists `device_fingerprint` + `device_name`
      on login and refresh (`X-Device-Id` / `X-Device-Name` request headers)
- [x] Session expiration — existing absolute `expires_at` plus an optional
      server-side **idle timeout** over `last_active_at`
      (`security.device.idle_timeout_seconds`)
- [x] Logout — existing `revoked_at` (unchanged)
- [x] Suspicious device detection — `Application\Service\Security\DeviceSessionService`:
      `NEW_DEVICE` (LOW) when a user authenticates from an unseen fingerprint;
      `SUSPICIOUS_DEVICE` (HIGH) on a fingerprint mismatch against the session;
      `SUSPICIOUS_DEVICE` (LOW) on an IP change (flagged once)
- [x] Multiple device rules — `SUSPICIOUS_DEVICE` (MEDIUM) when a user exceeds
      `security.device.max_active_sessions` concurrently active sessions
- [x] Fingerprint binding — enforced (401) only when
      `security.device.enforce_fingerprint_binding` is on; otherwise log + risk
      only (AD-013)
- [x] **Authentication** integration — `AuthService::login/refresh/validateToken`;
      `BaseController::authenticate` builds the `DeviceContext` from the request
- [x] **Attendance** integration — `ChallengeService` step 12 gathers device
      signals (`DEVICE_MISMATCH`, `MULTIPLE_ACTIVE_DEVICES`, `NEW_DEVICE`) for the
      in-flight attempt
- [x] **Security events** integration — all detections via `SecurityLogService`
      using the existing `NEW_DEVICE` / `SUSPICIOUS_DEVICE` cases
- [x] **Risk scoring** integration — `RiskEvaluatorInterface::evaluate` gains a
      `$context` arg; `RiskEvaluationService` folds `device_signals` into the
      level/outcome (`DEVICE_MISMATCH` → HIGH/PENDING_REVIEW; others → MEDIUM),
      persisted to `risk_assessments.signals`
- [x] Security tests — `DeviceSessionServiceTest` (19), `AuthServiceDeviceSessionTest`
      (11), `DeviceSessionRoutesTest` (6, full HTTP stack), plus device-risk
      cases in `ChallengeServiceTest`

Config: `config/security.php` (`SECURITY_MAX_ACTIVE_SESSIONS`,
`SECURITY_ENFORCE_DEVICE_BINDING`, `SECURITY_SESSION_IDLE_TIMEOUT`). Thresholds
are configuration, never hard-coded (spec §6.14); a `system_settings` move is
Phase 19. See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-013.

**Commit:** `feat(security): implement QRIVO device session security`

---

## Phase 19: Risk Scoring — ✅ COMPLETE

The exact spec model (PROJECT_SPECIFICATION.md §6.14, ATTENDANCE_ALGORITHM.md §9,
SECURITY_RULES.md §8). Migration `008` adds `system_settings`.

- [x] **Centralised** engine — `Application\Service\Security\RiskScoringService`
      is the sole `RiskEvaluatorInterface` implementation; the Phase-12
      `RiskEvaluationService` is deleted. Every attendance attempt is scored here.
- [x] Signal catalogue = the spec's **ten**, fixed in `Domain\Enum\RiskSignal`:
      `EXPIRED_QR`, `REPLAY_ATTEMPT`, `INVALID_CHALLENGE`, `EXCESSIVE_RETRY`,
      `DUPLICATE_ATTENDANCE`, `NEW_DEVICE`, `MULTIPLE_DEVICE_ACTIVITY`,
      `SUSPICIOUS_IP`, `LOCATION_MISMATCH`, `UNAUTHORIZED_RELATIONSHIP`. Nothing
      invented.
- [x] Scoring mechanism — `score = Σ weight(signal)` capped at 100 →
      `RiskPolicy::levelForScore()` (MEDIUM/HIGH/BLOCKED thresholds) →
      `RiskLevel::toOutcome()` (fixed §9: LOW/MEDIUM→PRESENT, HIGH→PENDING_REVIEW,
      BLOCKED→no record).
- [x] Configurable, never hard-coded (§6.14) — `system_settings` row →
      `config/risk.php` (`.env` `RISK_*`) → `RiskSignal::defaultWeight()`.
- [x] Signal detection — device signals from `DeviceSessionService`;
      `EXCESSIVE_RETRY` from the challenge count; QR/challenge/duplicate/
      unauthorized from the caller's recent `security_events` (look-back window);
      `SUSPICIOUS_IP` from a config deny-list (empty default, OQ-010);
      `LOCATION_MISMATCH` only when supplied (OQ-003).
- [x] Integration with the attendance pipeline — `ChallengeService` step 13 calls
      the engine inside the transaction; the result is persisted to
      `risk_assessments`; BLOCKED → `deny()` (no record, challenge consumed).
- [x] Integration with security events — MEDIUM/HIGH → `RISK_ESCALATION`,
      BLOCKED → `BLOCKED_ATTENDANCE` (level→event mapping on `RiskLevel`).
- [x] Tests for every defined scenario — `RiskScoringServiceTest` (one case per
      signal + boundaries + cap + de-dup + windows + `system_settings`/config
      precedence + escalation events), `RiskPolicyTest` (pure maths), plus the
      reworked risk-outcome cases in `ChallengeServiceTest`.

See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-014 and
`OPEN_QUESTIONS.md` OQ-003 / OQ-010 (interim resolutions).

**Commit:** `feat(security): implement QRIVO risk scoring`

---

## Phase 20: Security Events & Audit — ✅ COMPLETE

Consolidates the trail that prior phases built, closes the redaction gap, and
adds the read side (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §10 / §11).

- [x] **Structured security event logging** — one enum (`SecurityEventType`),
      one choke point (`SecurityLogService`). Every §6.15 event is covered:
      auth (`LOGIN_FAILURE`, `SUSPICIOUS_AUTH`, `TOKEN_REUSE`), QR
      (`QR_REPLAY/INVALID/EXPIRED`), challenge (`CHALLENGE_INVALID/EXPIRED`),
      attendance (`DUPLICATE_ATTENDANCE`, `UNAUTHORIZED_ATTENDANCE`), authz
      (`UNAUTHORIZED_ACCESS`, `IDOR_ATTEMPT`, `PRIVILEGE_ESCALATION`), device
      (`NEW_DEVICE`, `SUSPICIOUS_DEVICE`), risk (`RISK_ESCALATION`,
      `BLOCKED_ATTENDANCE`).
- [x] **Audit trails** for all four required categories:
      - attendance changes → `ATTENDANCE_STATUS_CHANGED` / `ATTENDANCE_RECORDED`
        (actor, target, old/new state, reason, timestamp, ip)
      - administrative actions → `{ENTITY}_CREATED/UPDATED/DELETED`,
        `USER_ROLE_ATTACHED` (actor, target entity, old/new, timestamp, ip)
      - security events → `security_events` (type, actor, details, ip, timestamp)
      - authentication events → `LOGIN_SUCCESS`, `LOGOUT`, **`TOKEN_REFRESHED`**
        (new this phase)
- [x] **Safe logging** — `Domain\Security\LogSanitizer` is a single recursive
      redaction pass applied by **both** `SecurityLogService` (before every
      `details` / `old_value` / `new_value` is persisted) **and** `Logger`
      (before every file line). Redacts sensitive keys at any depth
      (`password`, `*token*`, `*secret*`, `authorization`, `private_key`,
      `nonce`, `signature`, `fingerprint`, …) and token-shaped **values** (PEM
      private-key blocks, JWTs, long bare hex / base64). Over-long strings
      truncated. `AuditQueryService` re-sanitizes on read.
- [x] **Read side** — `GET /api/v1/admin/security-events` (`security.event.view`)
      and `GET /api/v1/admin/audit-logs` (`audit.log.view`), paginated + filtered
      (event_type, severity/actor/entity/target, date range). Read-only; the
      tables are append-only. Fills the two previously-unused permissions.
- [x] Tests — `LogSanitizerTest`, `SecurityLogServiceTest` (no-secrets
      guarantee + fail-safe), `AuditTrailRoutesTest`, `AuditCoverageTest`
      (all four categories through the router + global no-secrets sweep),
      `AuthServiceTest` (auth-event audit rows), `LoggerTest` (delegation).

See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-015.

**Commit:** `feat(security): implement QRIVO audit and security events`

---

## Phase 21: Reporting — ✅ COMPLETE

The reports defined in PROJECT_SPECIFICATION.md §6.16. Read-only over existing
tables — **no migration**. One aggregation repository
(`Infrastructure\Repository\Report\AttendanceReportRepository`), one query/
validation base (`AbstractReportService`), one service per role.

- [x] **Student** — `GET /api/v1/student/reports/attendance` (`report.self.view`):
      own attendance history + summary. The `students.id` is resolved from the
      token; a `student_id` can never be supplied.
- [x] **Teacher** (`report.course.view`) — the relationship is verified
      server-side before any row is read (403 + `UNAUTHORIZED_ACCESS` otherwise):
      - `GET /teacher/reports/course/{id}` — per-session breakdown for a course
        the teacher teaches
      - `GET /teacher/reports/class/{id}` — per-student breakdown for a class the
        teacher is assigned to
      - `GET /teacher/reports/student/{id}` — a student's records **restricted to
        the teacher's own classes/courses**; a client course/class filter may
        only narrow that scope, never widen it. Other teachers' data is never
        returned.
- [x] **Admin** (`report.institution.view` — "only per assigned permissions") —
      `GET /admin/reports/{institution, department/{id}, course/{id},
      attendance-statistics}`: overall summary + breakdowns by department /
      program / class / status / source / day.
- [x] **Authorization enforced on every report** — permission gate in the
      controller + relationship check in the service. Cross-role access is 403;
      unauthenticated is 401.
- [x] **Pagination + filtering** — row-level lists (`sessions`, `students`,
      `records`) are paginated (`page`, `per_page` ≤ 100) with a `meta` block;
      bounded aggregate breakdowns are not. Filters are whitelisted per report
      (`course_id`, `class_id`, `academic_term_id`, `status`, `source`,
      `session_status`, `from`, `to`, institutional ids); bad input is 422.
- [x] Tests — `AttendanceReportRepositoryTest` (aggregation maths, grouping,
      pagination, list-scoping), `ReportRoutesTest` (the full authorization
      hierarchy, scoping, filter validation, pagination, 401/403/404 through the
      Router).

See [`ACCEPTED_DEVIATIONS.md`](ACCEPTED_DEVIATIONS.md) AD-016.

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
