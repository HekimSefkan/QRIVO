# QRIVO — Accepted Deviations

> **Purpose:** This document records deliberate, reviewed deviations between the
> implementation and the literal wording of the source documents
> (`ORIGINAL_SPECIFICATION.md`, `docs/PROJECT_SPECIFICATION.md`, and the
> `database/docs/` design).
>
> Nothing here changes the architecture, the attendance algorithm, or the
> security model. `ORIGINAL_SPECIFICATION.md` remains unmodified and authoritative.
> Each entry states what changed, why it is acceptable, and where it is enforced.

---

## AD-001: Database migrations are incremental (per feature/domain), not one monolithic phase

**Source expectation:** `docs/DEVELOPMENT_PLAN.md` Phase 4 ("Database Migrations")
describes creating the complete schema, seeders, and `MIGRATION_GUIDE.md` as a
single up-front phase, before backend feature work.

**What was done instead:** Migrations are authored **per feature phase**, in the
same commit as the backend code that depends on them. The first migration,
`database/migrations/001_create_auth_tables.sql`, creates only the tables the
Authentication phase needs (`users`, `roles`, `permissions`, `role_permissions`,
`user_roles`, `login_attempts`, `device_sessions`, `security_events`,
`audit_logs`) and seeds the four default roles.

**Why this is acceptable:**

- The **full schema is already designed and frozen** in `database/docs/`
  (`ER_DIAGRAM.md`, `TABLES.md`, `RELATIONSHIPS.md`, `INDEXES.md`,
  `CONSTRAINTS.md`, `DATABASE_DECISIONS.md`). No table, column, key, or
  constraint is being invented ad hoc — each migration is a transcription of the
  frozen design for one domain group.
- Numbered, ordered migration files (`001_`, `002_`, …) still produce a
  deterministic, dependency-respecting build of the same final schema.
- Each phase stays independently testable and reviewable, which is the stated
  goal of the development plan.
- `DATABASE_DECISIONS.md` DD-013 ("migration order must respect the FK dependency
  chain") is still honoured — within and across migration files.

**Constraints going forward:**

- Every new table must match its definition in `database/docs/TABLES.md` exactly
  (engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`, FKs,
  indexes, unique constraints, `created_at` / `updated_at`).
- Migration files are append-only and numbered in dependency order.
- A consolidated `database/docs/MIGRATION_GUIDE.md` will be added when the schema
  is substantially complete; until then each migration file documents its own
  scope in a header comment.

**Enforced in:** `database/migrations/`, `docs/DEVELOPMENT_PLAN.md` (Phase 4 note).

---

## AD-002: Login validation performs password verification before account-state checks

**Source wording:** `docs/PROJECT_SPECIFICATION.md` §6.1 and
`docs/ATTENDANCE_ALGORITHM.md` context list the authentication checks in the
order: user existence → active account → approval status → password verification.

**What was done instead:** `AuthService::login()`
(`backend/src/Application/Service/AuthService.php`) performs the checks in this
order:

1. Rate-limit check (IP + email)
2. User lookup by email
3. **Argon2id password verification** (with a constant-time dummy verify when the
   user does not exist)
4. Active-account check
5. Approval-status check
6. Token issuance

**Why this is acceptable — and why it is a security improvement:**

- Revealing "account inactive" or "account pending approval" **before** the
  caller has proven knowledge of the password is an **account-state enumeration**
  weakness. An attacker could learn which email addresses correspond to real
  (but not-yet-approved / disabled) accounts without any valid credential.
- With this ordering, all pre-authentication failures return the same generic
  `Invalid credentials.` response, and the constant-time dummy verify keeps the
  timing of "user not found" indistinguishable from "wrong password".
- Every failure path (rate-limited, invalid credentials, inactive, unapproved)
  is still recorded in `login_attempts` and raised as a `security_events` entry,
  so operational visibility is unchanged.
- All functional requirements of §6.1 are still satisfied: inactive and
  unapproved accounts are still refused a token; the only change is the point in
  the sequence at which that decision is surfaced.

**This deviation must not be reverted** unless a concrete functional or security
reason is identified and documented here.

**Covered by tests:**
`backend/tests/Unit/Application/Service/AuthServiceTest.php`
— `test_login_wrong_password_does_not_reveal_email_exists`,
`test_login_nonexistent_user_does_not_reveal_absence`,
`test_login_inactive_account_throws_unauthorized`,
`test_login_unapproved_account_throws_unauthorized`.

**Enforced in:** `backend/src/Application/Service/AuthService.php`.

---

## AD-003: Course-schedule table name is `course_schedules` (plural)

**Source wording:** `docs/PROJECT_SPECIFICATION.md` §6.4 refers to
`course_schedule` (singular) in its list of assignment/scheduling tables.

**What was done instead:** The frozen database design
(`database/docs/TABLES.md`, `RELATIONSHIPS.md`, `INDEXES.md`, `CONSTRAINTS.md`,
`ER_DIAGRAM.md`) and `docs/ARCHITECTURE_FREEZE.md` §2.6 all use
`course_schedules` (plural), consistent with every other table name in the
schema (`class_courses`, `teacher_courses`, `attendance_sessions`, …).

**Why this is acceptable:**

- It is a naming-convention normalisation only — no change to columns,
  relationships, or semantics.
- The plural form is already the single consistent name across all six database
  design documents and the architecture freeze; the singular form appears only
  once, in prose, in the specification summary.
- No code references this table yet (scheduling is a later phase), so there is no
  migration or query churn.

**Status:** The database documentation is internally consistent and is treated as
authoritative for table naming. `ORIGINAL_SPECIFICATION.md` is unchanged.

**Enforced in:** `database/docs/`, `docs/ARCHITECTURE_FREEZE.md`.

---

## AD-004: `PENDING_REVIEW` is a first-class attendance status

**Status:** Previously identified and **formally resolved** — see
`docs/OPEN_QUESTIONS.md` OQ-001 (resolved 2026-08-29) and the `PENDING_REVIEW`
row added to `docs/ATTENDANCE_ALGORITHM.md` §5.

Recorded here only for completeness. `PENDING_REVIEW` is a full
`attendance_records.status` value: it is written to student records on session
close (per `system_settings`), it is the mapped outcome of a `HIGH` risk score,
and a teacher can resolve it via manual attendance. No further action required.

**Enforced in:** `database/docs/TABLES.md`, `database/docs/CONSTRAINTS.md`,
`docs/ATTENDANCE_ALGORITHM.md`, `backend/src/Domain/Enum/AttendanceStatus.php`.

---

## AD-005: SUPER_ADMIN = full system access; permission catalogue derived from spec §6

**Source wording:** `PROJECT_SPECIFICATION.md` §6.2 requires "permission-based
authorization" and names the tables `roles`, `permissions`, `role_permissions`,
`user_roles`, but does **not** enumerate the individual permission names, and
`OPEN_QUESTIONS.md` OQ-005 flags the SUPER_ADMIN vs ADMIN boundary as
unresolved.

**What was done (Phase 7):**

1. **SUPER_ADMIN is treated as full system access.**
   `AuthorizationService::hasPermission()` returns `true` for any permission when
   the actor holds the `SUPER_ADMIN` role, and `002_seed_rbac_permissions.sql`
   also grants SUPER_ADMIN every row in `permissions` (both agree). This is the
   literal reading of `SECURITY_RULES.md` §4 ("SUPER_ADMIN | Full system
   access").

2. **The permission catalogue is derived from `PROJECT_SPECIFICATION.md` §6**
   functional requirements — e.g. §6.3 → `academic.*.manage`, §6.5/§6.10 →
   `attendance.session.*`, §6.9 → `attendance.record.update`, §6.16 →
   `report.*.view`. The canonical list lives in
   `backend/src/Domain/Enum/Permission.php` and the role → permission map in
   `backend/src/Domain/Authorization/RolePermissionMap.php`; the migration is
   their SQL projection.

**Why this is acceptable:**

- The specification asks for a permission system without dictating names; any
  concrete instantiation must choose names. They are namespaced, systematic, and
  each maps to a stated requirement.
- Full-access SUPER_ADMIN does not preclude the finer OQ-005 question (does
  SUPER_ADMIN bypass *permission management* constraints?) — that remains open in
  `OPEN_QUESTIONS.md` and can be tightened later without changing the vocabulary.
- ADMIN remains strictly permission-controlled (no attendance-run permissions,
  no `iam.role.assign`), matching "institution-level, permission-controlled".

**Enforced in:** `backend/src/Domain/Enum/Permission.php`,
`backend/src/Domain/Authorization/RolePermissionMap.php`,
`backend/src/Application/Service/AuthorizationService.php`,
`database/migrations/002_seed_rbac_permissions.sql`. Open question tracked in
`docs/OPEN_QUESTIONS.md` OQ-005.

---

## AD-006: Course-scheduling integrity rules (Phase 9)

**Source wording:** `database/docs/RELATIONSHIPS.md` §4 describes
`class_courses`, `teacher_courses`, `teacher_class_assignments` as independent
M:N tables with only composite-uniqueness constraints (C-014..C-016).
`course_schedules` has no conflict constraint in the frozen design.

**What was done (Phase 9):**

1. **`teacher_class_assignments` requires coherent prerequisites.** To assign a
   teacher to teach course *C* to class *K* in term *T*, there must already be:
   - a `class_courses(K, C, T)` row (the course is offered to that class), and
   - a `teacher_courses(teacher, C, T)` row (the teacher is responsible for the course).
   Missing either → HTTP 422 with a clear message.

2. **`course_schedules` rejects double-booking.** A new/updated slot is refused
   (HTTP 409) when it overlaps, on the same `day_of_week`, with:
   - another slot in the same room, or
   - another slot belonging to the same teacher (any of their assignments).
   Overlap = `existing.start < new.end AND existing.end > new.start`.

3. **`student_courses` is read-only over the API.** It is a derived table
   (DD-005): rows are materialised from `class_courses` when a student is
   enrolled in a class and pruned on unenrollment / course removal. Only `GET`
   endpoints are routed; the service rejects direct writes with HTTP 409.

**Why this is acceptable:**

- The specification (§6.4) states the system must *determine* "which teacher
  teaches which course, to which class, in which room, at what time" — a
  `teacher_class_assignment` that contradicts `class_courses` / `teacher_courses`
  would make that determination incoherent. Rule 1 keeps the intersection valid.
- `INDEXES.md` explicitly lists the `course_schedules.room_id` index "for room
  conflict checking", so conflict detection is anticipated by the design.
  Rule 2 implements it; it changes no schema.
- DD-005 already assigns the application responsibility for keeping
  `student_courses` in sync. Rule 3 is the cleanest way to honour that without a
  second, divergent write path.
- None of these rules touch the frozen schema, the attendance algorithm, or the
  security model. They are additive integrity checks with 422/409 semantics.

**Enforced in:**
`backend/src/Application/Service/Schedule/TeacherClassAssignmentService.php`,
`.../CourseScheduleService.php`, `.../StudentCourseService.php`,
`backend/src/Infrastructure/Repository/ScheduleRepository.php`.

---

## AD-007: Attendance session `end_time`, `expires_at`, and duplicate-session scope (Phase 10)

**Source wording:** `ATTENDANCE_ALGORITHM.md` §2 lists the session fields
including `end_time | Session end` and `expires_at | Session expiration`. It does
not state whether `end_time` is populated at creation, nor the value of
`expires_at`, nor the exact scope of "prevent duplicates" (step 10).

**What was done (Phase 10):**

1. **`end_time` is NULL at creation.** `database/docs/CONSTRAINTS.md` §4 is
   explicit — `attendance_sessions.end_time` is "NULL until session is
   closed/cancelled" — and `TABLES.md` says "Set on close/cancel". The
   close/cancel flow (Phase 15) will set it. The algorithm's field list names the
   column; the frozen nullability rule governs its value at creation.

2. **`expires_at` = the scheduled meeting end.** It is set to the date of the
   start time combined with the covering `course_schedules.end_time` (e.g. a
   Monday 09:00–11:00 slot started at 09:40 → `expires_at` = that Monday
   11:00:00). This is the natural "the session is valid until class ends"
   semantics and needs no new configuration.

3. **Duplicate-active-session scope = one ACTIVE session per
   `(class_id, course_id, academic_term_id)`.** Step 10 ("prevent duplicates")
   plus `INDEXES.md` (`(status, class_id)` — "Prevent duplicate active session
   for a class") make per-class/course/term the operative unit. Enforced inside
   the creation transaction after a `SELECT … FOR UPDATE` on the `classes` row
   (CONSTRAINTS.md §6). Whether a *single teacher* may hold multiple concurrent
   ACTIVE sessions for different classes is **OQ-009** and is left open — the
   repository exposes `activeSessionCountForTeacher()` for when that is decided.

**Why this is acceptable:**

- (1) follows the frozen schema exactly; the close flow depends on `end_time`
  being NULL beforehand.
- (2) and (3) instantiate under-specified details using the scheduling data the
  spec already requires, change no schema, and use standard 409 semantics.
- None of this touches the attendance algorithm's steps, ordering, transaction
  requirement, or security model.

**Enforced in:**
`backend/src/Application/Service/Attendance/AttendanceSessionService.php`,
`backend/src/Infrastructure/Repository/Attendance/AttendanceSessionRepository.php`,
`database/migrations/005_create_attendance_sessions.sql`. OQ-009 tracked in
`docs/OPEN_QUESTIONS.md`.

---

## AD-008: Dynamic-QR nonce store, wire format, and TTL config (Phase 11)

**Source wording:** `ATTENDANCE_ALGORITHM.md` §3 specifies the QR payload
(`session_id`, `timestamp`, `nonce`, `signature`), HMAC-SHA256 signing, refresh,
and "old QR codes become invalid". It does not fix a wire encoding, a nonce
store, or a refresh interval. `ARCHITECTURE_FREEZE.md` §2.8 names the nonce store
as "in-memory or `qr_used_nonces`". OQ-008 flags the TTL as unresolved.

**What was done (Phase 11):**

1. **Nonce store = `qr_used_nonces`** (migration `006`). `UNIQUE(nonce)`; a QR
   nonce is written the first time that QR is consumed, so a second consumption
   is impossible even under concurrency. This is the durable option explicitly
   offered by the architecture freeze. **It is not one of the 31 domain tables**
   in `database/docs/TABLES.md` — it is an implementation table for the frozen
   "Dynamic QR Module" component, added under the freeze's own wording.

2. **Wire format** — the string encoded into the QR image is
   `qrivo.v1.<session_uuid>.<unix_ts>.<nonce_hex>.<hmac_sha256_hex>`. The
   signature covers `qrivo.v1.<session_uuid>.<unix_ts>.<nonce_hex>`. `session_id`
   is the session **UUID** (the "safe external identifier"), never the internal
   numeric id. No other field is present (SECURITY_RULES.md §5).

3. **TTL / refresh** — `config/attendance.php` reads `QR_TTL_SECONDS` (default
   **30**, matching `.env.example`), `QR_REFRESH_SECONDS`, and
   `QR_CLOCK_SKEW_SECONDS`. This is the interim resolution of **OQ-008**; a move
   to `system_settings` is deferred.

**Why this is acceptable:** all three instantiate details the spec leaves open,
using the mechanisms the spec/freeze already name, and change no domain schema,
no algorithm step, and no security rule. The signature algorithm (HMAC-SHA256),
the per-session key (`session_secret`, DD-002), server-side expiry, and
nonce+expiration replay protection are implemented exactly as specified.

**Enforced in:** `backend/src/Domain/Attendance/QrPayload.php`,
`backend/src/Application/Service/Attendance/QrService.php`,
`backend/src/Infrastructure/Repository/Attendance/QrNonceRepository.php`,
`backend/config/attendance.php`, `database/migrations/006_create_qr_used_nonces.sql`.
OQ-008 tracked in `docs/OPEN_QUESTIONS.md`.

---

## AD-009: Challenge-response — QR-nonce replay scope, basic risk evaluator, challenge TTL (Phase 12)

**Source wording:** `ATTENDANCE_ALGORITHM.md` §4 specifies the challenge flow,
the 13-point checklist, single-use (`used_at`), and "risk scoring evaluation" as
step 13. DD-004 says the QR nonce is "stored in `qr_challenges.qr_nonce` when a
challenge is issued" to "detect if a **student** attempts to reuse an old QR
nonce". Neither the challenge TTL nor the risk thresholds are fixed. The full
risk engine is Phase 19.

**What was done (Phase 12):**

1. **QR-nonce replay at challenge issuance is per-student.** Before issuing a
   challenge, `qr_challenges` is checked for an existing row with the same
   `(student_id, qr_nonce)` — a repeat → 409 + `QR_REPLAY`. This is exactly
   DD-004 and lets many students scan the same displayed QR frame (each gets one
   challenge). Phase 11's global `qr_used_nonces` store (AD-008) is **not** used
   by the challenge flow — it remains available for stricter modes.

2. **The Phase 12 risk evaluator is basic.** It measures retry pressure (count of
   `qr_challenges` for the `(student, session)` in a window). Thresholds are
   configuration (`config/attendance.php` → `attendance.risk.*`), never
   hard-coded (spec §6.14). It **always runs** and **always writes a
   `risk_assessments` row** inside the verification transaction — the pipeline
   step is never skipped. LOW→PRESENT, MEDIUM→PRESENT + `RISK_ESCALATION`,
   HIGH→PENDING_REVIEW, BLOCKED→no attendance record (challenge still consumed).
   The full engine (device / IP / location / multi-device, `system_settings`) is
   Phase 19; it plugs in via `Domain\Contract\RiskEvaluatorInterface`.

3. **Challenge TTL / rate limits are configuration.** `config/attendance.php` →
   `attendance.challenge.{ttl_seconds (120), max_per_window (10), window_seconds}`.

4. **Step distribution across two endpoints.** `POST .../challenge` runs
   auth + QR validation (2-4) + membership (8-9) + QR-nonce replay + rate limit
   (11) and issues the challenge. `POST .../verify` runs the challenge checks
   (5-7) + challenge-response match + QR re-validation (3-4) + session re-check
   (2) + membership re-check (8-9) + device/session hook (12) + the transaction
   (7-atomic, 10, 13, attendance). Together they cover all 13 checklist items;
   nothing is bypassed.

**Why this is acceptable:** every mechanism the spec names (challenge nonce,
single-use `used_at`, ownership, expiry, replay, duplicate `UNIQUE`, transaction,
risk step) is implemented exactly; the choices above instantiate under-specified
details using the spec's own tables/config and change no schema, no algorithm
step, and no security rule. Failed attempts return a generic message + coarse
HTTP status; the specific reason goes only to `security_events` (§4).

**Enforced in:** `backend/src/Application/Service/Attendance/ChallengeService.php`,
`RiskEvaluationService.php`, `backend/src/Domain/Contract/RiskEvaluatorInterface.php`,
`backend/src/Infrastructure/Repository/Attendance/QrChallengeRepository.php`,
`backend/config/attendance.php`, `database/migrations/007_create_qr_challenges.sql`.
Full risk engine tracked as Phase 19.

---

## AD-010: Live attendance realtime = AJAX polling (Phase 13)

**Source wording:** `ATTENDANCE_ALGORITHM.md` §8 and `PROJECT_SPECIFICATION.md`
§6.8: "**Preferred:** WebSocket (if stable and reliable). **Fallback:** AJAX
polling (2-5 second intervals)."

**What was done (Phase 13):** the **AJAX polling** path — the spec's explicit
fallback — is implemented. The frozen technology stack (`ARCHITECTURE_RULES.md`
§1.1: PHP 8.3+, MySQL, PDO, Composer, **REST API / JSON**) contains no
persistent-process / WebSocket server, and `ARCHITECTURE_FREEZE.md` §4 lists only
REST endpoints. Standing up a WebSocket server (Ratchet / Swoole / an external
broker) would be a new infrastructure component — an architecture change
requiring approval.

Endpoints (`attendance.live.view`, TEACHER, session-owner only):
- `GET /api/v1/teacher/attendance/{id}/live` — full snapshot for the initial render
- `GET .../live/counters` — the 2-5 s poll payload: counters + `session_status` +
  `remaining_seconds` + `students_version` (a cheap change signal) + `poll_interval_ms`
- `GET .../live/students` — the roster, re-fetched when `students_version` changes;
  `search` / `status` / `updated_since` filters

**Why this is acceptable:** it is precisely the mechanism the spec names as the
fallback; it uses only the frozen REST/JSON stack; the `students_version` signal
keeps the poll cheap; and the endpoint contract (`{ session, qr, counters,
students }`) is WebSocket-ready — a future WS layer would push the same payloads.

**Enforced in:** `backend/src/Application/Service/Attendance/LiveAttendanceService.php`,
`backend/src/Presentation/Http/Controller/Teacher/LiveAttendanceController.php`.
WebSocket upgrade tracked as a possible future enhancement; `OQ-006` (web client
technology) is the related open question.

---

## AD-011: Mobile project hand-scaffolded; platform folders gitignored (Phase 16)

**Source wording:** `PROJECT_SPECIFICATION.md` §6.10–6.11 and `DEVELOPMENT_PLAN.md`
Phase 16: "Flutter project structure", "Authentication with secure token
storage", "Student Dashboard, Profile, Schedule, Attendance History". The mobile
client is explicitly a **consumer** of the authoritative backend
(`SECURITY_RULES.md`: "The backend is the single source of truth").

**What was done (Phase 16):**

1. The Flutter project under `mobile/` was authored by hand (`pubspec.yaml`,
   `analysis_options.yaml`, `lib/`, `test/`) rather than via `flutter create`,
   because the development machine has no Flutter/Dart SDK.
2. The generated platform folders (`android/`, `ios/`, `linux/`, `macos/`,
   `windows/`, `web/`) and `pubspec.lock` are **gitignored**. A developer runs
   `flutter create .` + `flutter pub get` once to materialise them locally.
3. `flutter test` is therefore **not run locally**. The seven Dart test files in
   `mobile/test/` are authored to run in CI / on a developer workstation. The
   backend endpoints the client depends on
   (`GET /api/v1/student/{dashboard,profile,schedule,attendance/history}`) are
   fully covered by the PHPUnit suite.

**Why this is acceptable:** no architecture, algorithm, security, or database
decision is affected — the mobile app holds no security logic and persists only
the token pair in the platform secure store. Gitignoring generated platform
folders is the Flutter-idiomatic default for a fresh app. The approach is
reversible: committing the platform folders later is a no-op for the app code.

**Enforced in:** `mobile/.gitignore`, `mobile/README.md`,
`backend/src/{Infrastructure/Repository/StudentSelfRepository,Application/Service/Student/StudentSelfService,Presentation/Http/Controller/Student/SelfController}.php`.

---

## AD-012: Mobile QR scanner uses `mobile_scanner`; local prefix sniff is UX-only (Phase 17)

**Source wording:** `ATTENDANCE_ALGORITHM.md §4` ("Scans QR code → QR data parsed
→ Mobile app requests challenge from backend → Mobile app submits challenge
response + verification request") and `PROJECT_SPECIFICATION.md §6.11`
("QR scanner", "QR → Challenge → Verification → Result flow"). `SECURITY_RULES.md`:
"The backend is the single source of truth … the mobile client performs no
security validation."

**What was done (Phase 17):**

1. Camera capture / barcode decoding uses the **`mobile_scanner`** Flutter
   package (`^5.2.3`) — the stack docs freeze the *backend* technology, not the
   mobile app's UI packages. It is confined to `qr_scanner_screen.dart`; the flow
   logic (`QrAttendanceController`) has no camera dependency.
2. The client sends the **raw decoded QR string** to the backend unchanged. It
   does **not** parse `session_id / timestamp / nonce / signature` for any
   decision. The "QR data parsed" step in the algorithm is satisfied server-side
   by `QrPayload::decode()` inside `POST /student/attendance/qr/verify`,
   `/challenge` and `/verify`.
3. One local check exists: `rawQr.startsWith('qrivo.')`. This is a **UX filter**
   so scanning an unrelated barcode (a URL, a product code) shows "not a QRIVO
   code" instead of firing three API calls. Anything that passes the sniff is
   still fully validated server-side (shape, HMAC-SHA256 signature, expiry,
   session state, enrolment, replay, rate limit, risk, duplicates). The sniff can
   only *reject* early — it can never cause an invalid code to be accepted.
4. Failure presentation: the backend returns deliberately generic messages
   (§4 "Failed attempts must NOT expose technical security details"). The client
   shows those verbatim and additionally classifies each into a `QrFailureKind`
   **only** to choose an icon and whether a "Try again" button is shown. No
   security state is inferred from the classification.

**Why this is acceptable:** no architecture, attendance-algorithm, security, or
database decision changes. The server remains the sole authority for every
attendance verdict; the app is a thin transport + presentation layer over the
Phase 11–12 endpoints.

**Enforced in:** `mobile/lib/features/attendance/` (`qr_attendance_controller.dart`,
`qr_scanner_screen.dart`, `attendance_failure.dart`, `attendance_result_sheet.dart`),
`mobile/lib/core/api/student_api.dart`, `mobile/pubspec.yaml`.

---

## Change protocol

New deviations may be added here **only** after review. A deviation that touches
a locked decision in `docs/ARCHITECTURE_RULES.md`, `docs/ATTENDANCE_ALGORITHM.md`,
or `docs/SECURITY_RULES.md` still requires the stop-and-approve process in
`AGENTS.md` §2 and `docs/ARCHITECTURE_RULES.md` §8 — this file does not bypass it.
