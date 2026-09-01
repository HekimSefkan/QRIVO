# Changelog

All notable changes to the QRIVO project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Conventional Commits](https://www.conventionalcommits.org/).

---

## [Unreleased]

### Added (Local Runtime & Seed Data — Phase 24)

The backend and mobile code were complete but had never been executed by a human:
no migration runner, no seed data, no account to log in with (FINAL_AUDIT F-2 /
OQ-004). This phase makes the project runnable. **No schema shape, algorithm or
security control was changed.**

**Migration runner — `backend/scripts/migrate.php`**

- Reads DB settings from `backend/.env` through the existing `Config` class.
- Creates the database if absent (`utf8mb4` / `utf8mb4_unicode_ci`).
- Applies `database/migrations/*.sql` in filename order, one transaction per file.
- Records each file in the new `schema_migrations` ledger (filename, SHA-256,
  statement count, duration) so **re-running is a no-op**; `--status` reports
  applied/pending, `--fresh` rebuilds (refuses unless `APP_ENV=local`).
- New `Infrastructure\Database\SqlScriptSplitter` — a quote-aware statement
  splitter. Required because the shipped migrations contain semicolons *inside
  string literals* (`COMMENT='Core identity record; password_hash is …'`), which
  a naive `explode(';')` corrupts. **No migration file was edited.**
- New migration `000_create_schema_migrations.sql` (infrastructure table, no FKs,
  no personal data — deliberately outside the 31 domain tables, like
  `qr_used_nonces`).

**Demo seeder — `backend/scripts/seed.php` + `database/seeders/demo_dataset.php`**

- 1 school → faculty → department → program, 1 academic year, 1 **ACTIVE** term,
  2 rooms, 3 courses, 1 class of 12 students, with `class_courses`,
  `teacher_courses`, `teacher_class_assignments`, `student_class_assignments`,
  the derived `student_courses` (DD-005) and `course_schedules` fully wired.
- One schedule is **re-centred on the current time on every run**, so a teacher
  can press *start attendance* immediately after seeding.
- 16 accounts: 1 SUPER_ADMIN, 1 ADMIN, 2 TEACHER, 12 STUDENT — all
  `is_active = 1`, `is_approved = 1`, printed as a table at the end.
- **Security:** every `password_hash` is computed at runtime with
  `PASSWORD_ARGON2ID`; no hash and no plaintext password exists in the
  repository. The demo password comes from `SEED_DEFAULT_PASSWORD` in the
  gitignored `backend/.env`, and the script **refuses to run unless
  `APP_ENV=local`**.
- Fully idempotent — a second run reports `rows inserted: 0`.

**Smoke test — `backend/scripts/smoke_test.php`**

16 steps over real HTTP against a running server: health → admin login →
discover the live schedule → teacher login → start attendance → dynamic QR →
student login → QR preflight → challenge → challenge response (**asserts
PRESENT/QR**) → replay rejected (409) → teacher live list → manual override
(LATE/MANUAL + audit id) → student override rejected (403) → close (every
WAITING → ABSENT) → post-close submission rejected → audit/security trail present
with no password or raw token in it. Fails loudly with the HTTP status and body.

**Local runtime**

- `docker-compose.yml` — `db` (mysql:8.4, utf8mb4, named volume, healthcheck) and
  `api` (PHP 8.3 + `pdo_mysql`, serving `0.0.0.0:8000`, waits for the db to be
  healthy), plus `docker/api.Dockerfile` and `.env.docker.example`.
- `docs/RUNBOOK.md` — prerequisites, both the Docker and native (Laragon/XAMPP)
  paths, the seeded accounts, reset procedures, smoke test and troubleshooting.

**Verified against a live MySQL 8.4.3**: 9 migrations applied (33 tables),
re-run = 0 applied, seeder 102 rows then 0 on re-run, smoke test passed 4
consecutive times, and the full PHPUnit suite still green.

**Findings recorded (no code changed):**

- **AD-017** — the `schema_migrations` ledger table and the dev tooling above.
- **F-7** (`docs/FINAL_AUDIT.md`) — `QrService::validateAndConsume()` has no
  callers, so `qr_used_nonces` is never written and its `REPLAYED` branch cannot
  fire. **Not an exploitable hole** (replay is still blocked by the per-student
  `qr_nonce` guard DD-004, challenge single-use DD-003 and `UNIQUE(session,
  student)` C-001, all covered by passing tests), but the cross-student case is
  unguarded and the docblock is inaccurate. Fixing it changes the algorithm, so
  it is documented for approval rather than changed silently.
- **F-2 / OQ-004** — resolved for local development; production provisioning
  remains open.

### Added (Final Security & Architecture Audit — Phase 23)

- `docs/FINAL_AUDIT.md` — end-of-project review against every authoritative
  document. 24-area verification matrix (architecture / algorithm / auth / RBAC
  / resource authz / QR / nonce / HMAC / challenge-response / replay / duplicate
  / transactions / device-session / risk / security-events / audit / DB
  constraints / API / mobile / secrets / errors / logging / tests).
- **Verdict: no architectural violations.** All 16 differences from the literal
  source wording are documented and reviewed in `ACCEPTED_DEVIATIONS.md`; none
  touches a locked decision, the algorithm, the security model, or the schema
  shape. `ORIGINAL_SPECIFICATION.md` unchanged.
- Six informational findings recorded (F-1 unwired middleware classes /
  F-2 no user-provisioning path, OQ-004 / F-3 tests live under `backend/tests/` /
  F-4 permissive CORS default / F-5 web client not yet built, OQ-006 /
  F-6 notifications not yet built, OQ-002). None is a security gap or a blocker.
- Backend suite re-run for the audit: **586 tests, 1505 assertions, 100%
  passing**.
- Release readiness: backend + mobile student app are release-ready; web client
  and notifications remain future phases (API-ready, no architectural change
  needed). Operator prerequisites documented (seed first SUPER_ADMIN, set real
  CORS origins + `.env`, run migrations `001`–`008`).

### Added (Session Close/Cancel + Integration Validation — Phases 15 & 22)

**Session close / cancel (`ATTENDANCE_ALGORITHM.md` §7 — the one gap the
end-to-end validation surfaced; Phase 15 had been deferred):**

- `POST /api/v1/teacher/attendance/{id}/close` (`attendance.session.close`) —
  `ACTIVE → CLOSED` in one transaction: still-`WAITING` records resolve to
  `ABSENT` or `PENDING_REVIEW` per `system_settings.attendance.close.waiting_default_status`
  (default `ABSENT`, OQ-001); `end_time` stamped; `ATTENDANCE_SESSION_CLOSED`
  audit with the resolved count.
- `POST /api/v1/teacher/attendance/{id}/cancel` (`attendance.session.cancel`) —
  `ACTIVE → CANCELLED`; attendance records left untouched; optional `reason`
  audited (`ATTENDANCE_SESSION_CANCELLED`).
- Ownership verified server-side (mismatch → 403 + `IDOR_ATTEMPT`).
- Concurrency-safe: `AttendanceSessionRepository::transitionStatus()` is an
  atomic `UPDATE … WHERE status = :from`; a second concurrent close/cancel
  changes zero rows and is rejected (409) without corrupting state.
- QR generation and challenge/verify already reject any non-`ACTIVE` session, so
  §7 steps 2–3 ("QR validations become invalid", "new submissions blocked")
  needed no change.
- Migration `008` seeds the new setting. Config: `attendance.session.close` /
  `.cancel` permissions (already in the RBAC map).

**End-to-end integration validation (Phase 22):**

- `tests/Unit/Integration/FullFlowIntegrationTest` — the complete approved flow
  (Authentication → Authorization → Academic structure → Course → Schedule →
  Attendance session → Dynamic QR → QR scan → Challenge → Challenge response →
  Security validation → Risk evaluation → Attendance transaction → Live
  attendance → Manual attendance → Session closing → Reporting), 70 assertions,
  dispatched through the real HTTP `Router` at every step.
- `tests/Unit/Integration/SecurityFailurePathsTest` — every failure path in
  `SECURITY_RULES.md` §12 (auth: invalid login / rate limit / token reuse /
  post-logout; authz: IDOR / BOLA / privilege escalation / unassigned session;
  QR: malformed / tampered / expired / nonce replay / duplicate scan; challenge:
  wrong nonce / wrong student / reuse; attendance: duplicate / non-enrolled /
  non-member manual / cancelled-session manual; generic: SQL injection / XSS /
  concurrent session start / challenge rate-limit abuse), 22 tests.
- `tests/TEST_REPORT.md` — full flow + failure-path coverage tables, the one gap
  found and fixed (FIX-1), and the assurances (no control weakened, no
  validation bypassed).
- Regression: `SessionCloseServiceTest` (10 tests).

**No security control was weakened and no validation step was bypassed.**
Backend suite: **586 tests, 1505 assertions, 100% passing**.
`ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Attendance Reporting — Phase 21)

The reports defined in PROJECT_SPECIFICATION.md §6.16. Read-only over existing
tables — **no migration**.

**Structure**

- `Infrastructure/Repository/Report/AttendanceReportRepository` — one set of
  parameterised aggregation queries (scalar summary, grouped summary over
  course / class / department / program / student / day / status / source /
  session-status, paginated sessions / students / records). Takes an
  already-authorized, whitelisted filter map — it makes no authorization
  decision.
- `Application/Service/Report/AbstractReportService` — shared query parsing:
  whitelisted filters, positive-integer ids, enum validation
  (`AttendanceStatus` / `AttendanceSource` / `SessionStatus`), date
  normalisation to a full `Y-m-d H:i:s` bound, `page` / `per_page` (≤ 100). Bad
  input → 422.
- one service per role: `TeacherReportService`, `AdminReportService`,
  `StudentReportService`.

**Endpoints** (every one authenticated + permission-gated + relationship-scoped):

| role | endpoint | permission |
|---|---|---|
| student | `GET /api/v1/student/reports/attendance` | `report.self.view` |
| teacher | `GET /api/v1/teacher/reports/course/{id}` | `report.course.view` |
| teacher | `GET /api/v1/teacher/reports/class/{id}` | `report.course.view` |
| teacher | `GET /api/v1/teacher/reports/student/{id}` | `report.course.view` |
| admin | `GET /api/v1/admin/reports/institution` | `report.institution.view` |
| admin | `GET /api/v1/admin/reports/department/{id}` | `report.institution.view` |
| admin | `GET /api/v1/admin/reports/course/{id}` | `report.institution.view` |
| admin | `GET /api/v1/admin/reports/attendance-statistics` | `report.institution.view` |

**Authorization hierarchy** (enforced server-side, before any row is read):

- **student** — `students.id` resolved from the token; every record returned is
  the caller's. A `student_id` can never be supplied.
- **teacher** — the teacher→course / →class relationship is verified; a request
  outside scope is 403 + `UNAUTHORIZED_ACCESS`. The student-history report is
  additionally restricted to the teacher's own classes/courses (`class_ids IN
  (…) AND course_ids IN (…)`) so **other teachers' data is never returned**; a
  client filter may only narrow that scope.
- **admin** — `report.institution.view` only; institution-wide (no per-admin
  partition). Non-holders get 403.

**Pagination + filtering** — row-level lists carry a `meta` block
(`page`, `per_page`, `total`, `total_pages`); filters: `course_id`, `class_id`,
`academic_term_id`, `status`, `source`, `session_status`, `from`, `to`, and the
institutional ids for admin reports.

**Tests** — `AttendanceReportRepositoryTest` (aggregation maths, grouping,
pagination, list-scoping), `ReportRoutesTest` (the full authorization hierarchy,
scoping, filter validation 422, pagination, 401/403/404 through the Router).
Backend suite: **553 tests, 1274 assertions, 100% passing**. Deviation **AD-016**.
`ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Security Events & Audit — Phase 20)

Consolidates the trail prior phases built, closes the redaction gap, adds the
read side (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §9 / §10 / §11).
No migration.

**Safe logging — one redaction pass**

`Domain/Security/LogSanitizer` recursively redacts:
- **keys** whose name marks them sensitive, at any depth — `password`, any
  `*token*`, `*secret*`, `authorization`, `credential`, `private_key`, `nonce`,
  `signature`, `fingerprint`, `otp`, `bearer`;
- **values** that look like credentials regardless of key — PEM private-key
  blocks, JWTs, ≥40-char bare hex / base64url tokens;
- over-long strings are truncated.

It is now applied by **`SecurityLogService`** before every `details` /
`old_value` / `new_value` is persisted (previously the caller's array was
json-encoded verbatim — the gap), and by **`Logger`** before every file line
(replacing a shallow top-level-key redactor). `AuditQueryService` re-sanitizes
on read.

**Audit trail — all four required categories**

| category | events |
|---|---|
| attendance changes | `ATTENDANCE_STATUS_CHANGED`, `ATTENDANCE_RECORDED` |
| administrative actions | `{ENTITY}_CREATED/UPDATED/DELETED`, `USER_ROLE_ATTACHED` |
| security events | `security_events` rows (`SecurityEventType`) |
| authentication events | `LOGIN_SUCCESS`, `LOGOUT`, **`TOKEN_REFRESHED`** (new) |

**Read side** — `GET /api/v1/admin/security-events` (`security.event.view`) and
`GET /api/v1/admin/audit-logs` (`audit.log.view`), paginated + filterable
(event_type, severity / actor / entity / target_id, `from`/`to` date range),
newest first. Read-only — both tables stay append-only. Fills the two
previously-unused view permissions.

**Tests** — `LogSanitizerTest` (key + value redaction, nesting, depth guard),
`SecurityLogServiceTest` (nothing sensitive reaches the DB; fail-safe recording),
`AuditTrailRoutesTest` (authz + filters + pagination + read-side re-sanitization),
`AuditCoverageTest` (all four categories through the Router + global no-secrets
sweep), `AuthServiceTest` (auth-event audit rows; no raw token in any row),
`LoggerTest` (delegation). Backend suite: **528 tests, 1189 assertions, 100%
passing**. `ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Risk Scoring — Phase 19)

The exact spec model — PROJECT_SPECIFICATION.md §6.14, ATTENDANCE_ALGORITHM.md §9,
SECURITY_RULES.md §8. Migration `008` adds `system_settings`.

**Centralised engine**

`Application/Service/Security/RiskScoringService` is the **single**
`RiskEvaluatorInterface` implementation — every attendance attempt is scored
there and nowhere else. The Phase-12 `Attendance/RiskEvaluationService` is
**removed**; its `attendance.risk.*` config keys move to `config/risk.php`.

**Signals — exactly the ten in the spec** (`Domain/Enum/RiskSignal`, nothing
added): `EXPIRED_QR`, `REPLAY_ATTEMPT`, `INVALID_CHALLENGE`, `EXCESSIVE_RETRY`,
`DUPLICATE_ATTENDANCE`, `NEW_DEVICE`, `MULTIPLE_DEVICE_ACTIVITY`, `SUSPICIOUS_IP`,
`LOCATION_MISMATCH`, `UNAUTHORIZED_RELATIONSHIP`.

Detection: device signals from `DeviceSessionService`; `EXCESSIVE_RETRY` from the
challenge count in the window; QR / challenge / duplicate / unauthorized from the
user's recent `security_events` (configurable look-back); `SUSPICIOUS_IP` from a
config deny-list (empty by default — OQ-010); `LOCATION_MISMATCH` only when a
caller supplies it (OQ-003).

**Scoring** (`Domain/Risk/RiskPolicy`): `score = Σ weight(signal)`, capped at 100
→ level by the MEDIUM / HIGH / BLOCKED thresholds → outcome by the **fixed** §9
table (`RiskLevel::toOutcome()`: LOW/MEDIUM → `PRESENT`, HIGH → `PENDING_REVIEW`,
BLOCKED → no record). MEDIUM/HIGH also record `RISK_ESCALATION`; BLOCKED records
`BLOCKED_ATTENDANCE`. Every result is persisted to `risk_assessments`.

**Configurable, never hard-coded** (§6.14): resolution order
`system_settings` row → `config/risk.php` (`.env` `RISK_*`) →
`RiskSignal::defaultWeight()`. Migration `008` creates + seeds `system_settings`
with all risk keys.

**Integration:** `ChallengeService` step 13 calls the engine inside the
attendance transaction and persists the assessment; step 12 device signals feed
straight in.

**Tests:** `RiskScoringServiceTest` (one scenario per signal + score→level
boundaries + 100-cap + signal de-duplication + detection windows +
`system_settings`/config precedence + escalation-event emission),
`RiskPolicyTest` (pure maths), reworked risk-outcome cases in
`ChallengeServiceTest`. Backend suite: **494 tests, 1079 assertions, 100%
passing**. `ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Device Session Security — Phase 18)

Implements PROJECT_SPECIFICATION.md §6.13 using only the frozen `device_sessions`
schema — **no migration** (every column already exists in `001`).

**New**

| unit | file |
|---|---|
| value object | `Domain/Security/DeviceContext` — server-derived `sha256(X-Device-Id \| User-Agent)` fingerprint; a client never sends or is trusted for the fingerprint (SECURITY_RULES.md §1) |
| service | `Application/Service/Security/DeviceSessionService` — registration, new-device / multi-device / fingerprint-mismatch / IP-change detection, idle timeout, and the attendance risk signals |
| config | `config/security.php` — `max_active_sessions`, `enforce_fingerprint_binding`, `idle_timeout_seconds` (spec §6.14: configuration, never hard-coded) |

**Integration**

- **Authentication** — `AuthService::login` / `refresh` persist
  `device_fingerprint` + `device_name` and emit `NEW_DEVICE` /
  `SUSPICIOUS_DEVICE` events; `AuthService::validateToken` (+ `BaseController`)
  enforce the idle timeout and fingerprint binding on every authenticated
  request and record activity. New request headers: `X-Device-Id`,
  `X-Device-Name` (both optional).
- **Attendance** — `ChallengeService` step 12 now gathers device risk signals
  (`DEVICE_MISMATCH`, `MULTIPLE_ACTIVE_DEVICES`, `NEW_DEVICE`) for the in-flight
  attempt.
- **Security events** — all detections flow through `SecurityLogService` using
  the existing `NEW_DEVICE` / `SUSPICIOUS_DEVICE` types; details never contain
  tokens or secrets.
- **Risk scoring** — `RiskEvaluatorInterface::evaluate` gains a `$context`
  argument; `RiskEvaluationService` folds `device_signals` into the level /
  outcome (`DEVICE_MISMATCH` → HIGH → `PENDING_REVIEW`; others → MEDIUM →
  `PRESENT` + `SECURITY_EVENT`) and records them in `risk_assessments.signals`.

**Behaviour**

Fingerprint binding is **log-only by default** — a mismatch always produces a
`SUSPICIOUS_DEVICE` event and a risk signal, and is rejected (401) only when
`SECURITY_ENFORCE_DEVICE_BINDING=true` (**AD-013**). The device fingerprint is
never an authorization input.

**Tests** — `DeviceSessionServiceTest`, `AuthServiceDeviceSessionTest`,
`DeviceSessionRoutesTest` (full HTTP stack), and device-risk cases in
`ChallengeServiceTest`. Backend suite: **451 tests, 989 assertions, 100%
passing**. `ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Mobile QR Attendance — Phase 17)

Mobile-only. **No backend change** — the student attendance endpoints
(`POST /api/v1/student/attendance/{qr/verify,challenge,verify}`) were built and
tested in Phases 11–12.

**`mobile/lib/features/attendance/`**

- `QrAttendanceController` (`ChangeNotifier`, camera-free) runs
  `ATTENDANCE_ALGORITHM.md §4` in order:
  `validating` (preflight `POST /qr/verify`, non-consuming) → `challenging`
  (`POST /challenge`) → `verifying` (`POST /verify` — the server's nonce echoed
  back with the scanned QR) → `success` / `failure`.
- `qr_scanner_screen.dart` — `mobile_scanner` camera (QR only), torch,
  reticle, and a graceful denied/unavailable-camera state. Camera code is fully
  isolated from the flow logic.
- `attendance_result_sheet.dart` — progress view → PRESENT / PENDING_REVIEW
  result → mapped failure with retry-vs-final affordance.
- `attendance_failure.dart` — `QrFailureKind` covering every defined failure
  state (expired QR, tampered/bad-signature, session unavailable/closed, not
  enrolled, QR/challenge replay, challenge expired, rate-limited, risk-blocked,
  re-auth required, duplicate) **and** network errors. Classification is
  presentation-only; the server's generic message is shown verbatim.
- `home_shell.dart` gains an always-present "Scan" action.

**`mobile/lib/core/`** — `StudentApi.preflightQr / requestChallenge /
submitAttendance`; models `QrPreflight`, `AttendanceChallenge`, `AttendanceResult`.

**No security decision on the device.** The only local logic is a `qrivo.`
prefix sniff so an unrelated barcode doesn't hit the attendance API; the server
re-validates everything it accepts (**AD-012**).

Deps: `mobile_scanner: ^5.2.3`. Tests:
`features/qr_attendance_controller_test.dart` (happy path + every failure state +
concurrency), `features/attendance_result_sheet_test.dart`,
`core/attendance_models_test.dart`. `ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Mobile Application Foundation — Phase 16)

**Backend — student self-service** (no migration; read-only over existing tables):

| layer | file |
|---|---|
| repository | `Infrastructure/Repository/StudentSelfRepository` — profile, own schedule, paginated attendance history, status summary; keyed by `students.id` |
| service | `Application/Service/Student/StudentSelfService` — resolves the caller's student id via `RelationshipRepository`, rejects non-students (403), aggregates the dashboard |
| controller | `Presentation/Http/Controller/Student/SelfController` — gated by `PROFILE_SELF_VIEW` / `SCHEDULE_SELF_VIEW` / `ATTENDANCE_HISTORY_SELF_VIEW` |

Routes: `GET /api/v1/student/dashboard`, `/student/profile`, `/student/schedule`,
`/student/attendance/history`. A student sees **only their own** data; teachers and
admins without the self-view permissions get 403.

Tests: `StudentSelfServiceTest`, `StudentSelfRoutesTest` — backend suite now
**415 tests, 924 assertions, 100% passing**.

**Mobile — `mobile/` (Flutter, foundation)**:

- `core/api` — `ApiClient` (`/api/v1` base, bearer header, `{data,meta,message}`
  envelope unwrap, one-shot 401→refresh→retry, `ApiException` mapping); typed
  `StudentApi` for the four endpoints. Base URL via `--dart-define=QRIVO_API_BASE_URL`.
- `core/auth` — `Session` (expiry / refresh-window logic), `SecureSessionStore`
  (iOS Keychain / Android EncryptedSharedPreferences + Keystore, single JSON blob),
  `AuthRepository` (bare `http.Client`, no refresh recursion), `AuthController`
  (`ChangeNotifier`: launch bootstrap + `/auth/me` validation, sign in/out,
  single-flight token refresh, secure-store wipe on hard 401).
- `features/` — login, bottom-nav shell, dashboard, schedule, attendance history
  (infinite scroll), profile. **No QR scanner** (Phase 17).
- 7 Dart test files under `mobile/test/` (MockClient + in-memory store).

**No client-side security logic** — the backend remains authoritative. The only
secret persisted on device is the token pair, in the platform secure store.

Deviation **AD-011**: `mobile/` was hand-scaffolded (no local Flutter SDK);
generated platform folders + `pubspec.lock` are gitignored (`flutter create .`
locally); `flutter test` runs in CI. `ORIGINAL_SPECIFICATION.md` unchanged.

### Added (Manual Attendance — Phase 14)

No migration — writes `attendance_records` + `audit_logs`.

**Service — `Application/Service/Attendance/ManualAttendanceService`**
Implements ATTENDANCE_ALGORITHM.md §6 exactly and in order:

| step | check |
|---|---|
| 1 | teacher authentication (`BaseController::authenticate`) |
| 2 | teacher authorization — `attendance.record.update` (TEACHER); **students never hold it** |
| 3 | attendance ownership — caller must be the TEACHER who owns the session (else 403 + `IDOR_ATTEMPT`) |
| 4 | student membership — the student must be enrolled in the session's class (else 404 + `UNAUTHORIZED_ATTENDANCE`) |
| 5 | status validation — body `status` ∈ `{WAITING, PRESENT, ABSENT, LATE, EXCUSED}` (`PENDING_REVIEW` / unknown → 422) |
| 6 | transition validation — session not `CANCELLED` (409); new status ≠ current (422 no-op) |
| 7 | update — `status` + `source = MANUAL` + `marked_at` |
| 8 | audit — `audit_logs` row |

Steps **7 and 8 run in one transaction** (`Connection::transaction`) — the record
change and its audit commit or roll back together. `SecurityLogService::writeAuditLog()`
(new) is the throwing, id-returning variant used here; `AuditLogRepository::create()`
now returns the new id.

- Teacher can override a QR-submitted status.
- `CLOSED` sessions still accept manual changes (to resolve `PENDING_REVIEW`); `CANCELLED` do not.
- Explicit self-modification guard: a user who is both a teacher and a student in
  the same class cannot set their own attendance (`UNAUTHORIZED_ATTENDANCE`) —
  SECURITY_RULES.md §7.

**Audit record** (`ATTENDANCE_STATUS_CHANGED`) carries every mandatory field from
the spec's audit-data table: **actor** (`actor_user_id`), **target**
(`target_entity` / `target_id` = the `attendance_records` id), **previous state**
(`old_value` = `{status, source}`), **new state** (`new_value` = `{status,
source: MANUAL, teacher_id, student_id, attendance_session_id, old_status,
new_status, marked_at}`), **timestamp** (`created_at`), **reason** (when given),
**ip** (`ip_address`).

**Repository**
- `AttendanceRecordRepository` — `setStatusManual()`, `insertManual()`
- `RelationshipRepository` — `studentIdEnrolledInClass()`, `findUserIdForStudent()`

**API**
- `PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}` —
  `{ status, reason? }` → `{ previous_status, previous_source, status, source, reason, marked_at, audit_id }`

**Tests (27 new — 404 total, 880 assertions, 100% passing)**
- `ManualAttendanceServiceTest` — WAITING→PRESENT, QR override + reason, PENDING_REVIEW
  resolution, all assignable states, **full audit-field assertions**, reason NULL when
  omitted, atomicity, **student actor forbidden** (record untouched, no audit),
  **teacher-cannot-modify-own-attendance**, non-owner (+`IDOR_ATTEMPT`), no-profile teacher,
  404 session, student-not-in-session (+`UNAUTHORIZED_ATTENDANCE`), missing/unknown/
  PENDING_REVIEW status (422), no-op (422, no audit), CANCELLED (409), CLOSED (allowed)
- `ManualAttendanceRoutesTest` — Router-dispatched: owner 200 + audited, student 403
  (record untouched), 401, other-teacher 403 (+`IDOR_ATTEMPT`), 422 / 404 / 409, non-numeric route → 404

### Added (Teacher Live Attendance — Phase 13)

No migration — read-only over existing tables.

**Service — `Application/Service/Attendance/LiveAttendanceService`**
- `snapshot()` — session info (course / class / room / status / start / expiry /
  `remaining_seconds`) + current QR (when ACTIVE, via `QrService::generate`) +
  live counters + the filtered student roster
- `counters()` — the lightweight poll payload: `counters`, `session_status`,
  `remaining_seconds`, `students_version`, `server_time`
- `students()` — roster only, for a delta refresh
- **`requireOwnedSession()` runs on every call** — the caller must be the TEACHER
  who owns that session (else 403 + `IDOR_ATTEMPT`); missing session → 404. Only
  this session's students are ever returned; `session_secret` is never in a response.
- Live counters: `TOTAL` + one per attendance state — `WAITING, PRESENT, ABSENT,
  LATE, EXCUSED, PENDING_REVIEW` (ATTENDANCE_ALGORITHM.md §8)

**Repository — `AttendanceRecordRepository`**
- `liveRoster()` — one row per student (name / number / status / source / marked time);
  `search` (number + first/last name, `LIKE`), `status`, `updated_since` filters
- `rosterVersion()` — `"{total}:{marked}:{maxUpdatedAt}"` change signal for delta polling

**Realtime architecture — AJAX polling** (the spec's documented fallback; the
frozen stack has no WebSocket server — see `ACCEPTED_DEVIATIONS.md` AD-010).
Clients poll `counters` every `poll_interval_ms` and re-fetch `students` when
`students_version` changes. The endpoint contract is WebSocket-ready.

**API** (`attendance.live.view`, TEACHER)
- `GET /api/v1/teacher/attendance/{id}/live`
- `GET /api/v1/teacher/attendance/{id}/live/counters`
- `GET /api/v1/teacher/attendance/{id}/live/students`

**Tests (17 new — 377 total, 812 assertions, 100% passing)**
- `LiveAttendanceServiceTest` — snapshot shape, all counters, no `session_secret`,
  no QR block when not ACTIVE, ownership enforced on **every** method (3× `IDOR_ATTEMPT`),
  no-profile teacher, 404, search / status filters, no cross-session leakage,
  version changes on transition, and an end-to-end run of the challenge-response
  flow that shows a student flipping `WAITING → PRESENT` in the live view
- `LiveAttendanceRoutesTest` — Router-dispatched: owner 200 on all 3 endpoints,
  unauthenticated 401 on all 3, student 403 on all 3, other-teacher 403 on all 3
  (+ `IDOR_ATTEMPT`), HTTP filters

**Not implemented:** responsive UI (web-client / OQ-006); WebSocket transport
(AD-010).

### Added (Challenge-Response Attendance — Phase 12)

**Migration**
- `database/migrations/007_create_qr_challenges.sql` — `qr_challenges`
  (`UNIQUE(uuid)` C-003, `UNIQUE(nonce)` C-002, `used_at` nullable per DD-003,
  `qr_nonce` per DD-004), `risk_assessments` (level / score / signals JSON /
  outcome); FK `ON DELETE RESTRICT` (FK-46..FK-50)

**Domain**
- `Entity/Attendance/QrChallenge` — `toArray()` omits the challenge `nonce`
- `Attendance/RiskAssessment`, `Enum/RiskLevel`, `Enum/RiskOutcome`,
  `Enum/ChallengeFailureReason` (every failure path → its `security_events` type)
- `Contract/RiskEvaluatorInterface` — the step-13 seam (Phase 19 swaps the impl)

**Repositories**
- `Repository/Attendance/QrChallengeRepository` — `create`, `findByUuid(lock:)`,
  `markUsed()` (atomic `WHERE used_at IS NULL` — DD-003),
  `studentHasChallengeForQrNonce()` (per-student QR-nonce replay — DD-004),
  `countForStudentSessionSince()` (rate limit / risk)
- `Repository/Attendance/RiskAssessmentRepository`
- `AttendanceRecordRepository` — `findForSessionStudent(lock:)`, `markFromWaiting()`,
  `insertViaQr()`

**Services**
- `Application/Service/Attendance/ChallengeService` — ATTENDANCE_ALGORITHM.md §4,
  exactly and in order:
  - `requestChallenge()` — auth → QR validation (2-4: session ACTIVE, not expired,
    HMAC-SHA256) → membership (8-9) → per-student QR-nonce replay → rate limit (11)
    → issue `{ challenge_id, nonce, expires_at }`
  - `verify()` — load challenge → ownership (6) → single-use pre-check (7) →
    expiry (5) → challenge-response nonce match (constant time) → QR re-validation
    + binding to `challenge.qr_nonce` / session (3-4) → session still ACTIVE (2) →
    membership re-check (8-9) → device/session hook (12) → **transaction**
    { locked re-check + atomic single-use (7) · duplicate check (10) · risk
    evaluation + `risk_assessments` write (13) · attendance record } (CONSTRAINTS.md §6)
  - failures: generic client message + coarse HTTP status; specific reason → `security_events` only
  - MEDIUM risk → `RISK_ESCALATION` event; BLOCKED → no record, challenge still consumed, `BLOCKED_ATTENDANCE`
- `Application/Service/Attendance/RiskEvaluationService` — basic (retry-pressure)
  evaluator; thresholds from `config/attendance.php`, never hard-coded (spec §6.14)

**Config / env** — `attendance.challenge.{ttl_seconds, max_per_window, window_seconds}`,
`attendance.risk.{soft_retry_threshold, high_retry_threshold, retry_window_seconds}`

**API** (`attendance.qr.submit`, STUDENT)
- `POST /api/v1/student/attendance/challenge` — `{ qr }` → `{ challenge_id, nonce, expires_at }`
- `POST /api/v1/student/attendance/verify` — `{ challenge_id, nonce, qr }` → `{ status, source, risk }`

**Tests (32 new — 360 total, 749 assertions, 100% passing)**
- `ChallengeServiceTest` — happy paths + **every failure path**: non-student, missing/malformed
  QR, expired QR, tampered/forged QR signature, closed session, not enrolled (course/class),
  QR-nonce replay, rate limit, unknown challenge, wrong owner, wrong challenge-response nonce,
  expired challenge, mismatched/forged QR at verify, session closed after issuance, unenrolled
  after issuance, single-use replay, duplicate attendance, MEDIUM/HIGH/BLOCKED risk outcomes,
  no-detail-leak in messages — each asserting the matching `security_events` row
- `ChallengeRoutesTest` — Router-dispatched full scan→challenge→verify, 401 / 403 / 409 / 422

**Not implemented (per instruction / roadmap):** the full risk-scoring engine (Phase 19),
device-session security (Phase 18), live counters (Phase 13).

### Added (Dynamic QR System — Phase 11)

**Migration**
- `database/migrations/006_create_qr_used_nonces.sql` — `qr_used_nonces`
  (`UNIQUE(nonce)`, FK RESTRICT to `attendance_sessions`) — the QR-layer replay
  store named in `ARCHITECTURE_FREEZE.md` §2.8

**Config**
- `config/attendance.php` + `.env.example`: `QR_TTL_SECONDS` (default 30 —
  OQ-008), `QR_REFRESH_SECONDS`, `QR_CLOCK_SKEW_SECONDS`; `Config` now loads it

**Domain**
- `Attendance/QrPayload` — the four spec fields (`session_id` = session **UUID**,
  `timestamp`, `nonce`, `signature`); `encode()` / `decode()` for the wire format
  `qrivo.v1.<uuid>.<ts>.<nonce>.<sig>`; nothing else in the payload
- `Attendance/QrValidationResult`, `Enum/QrValidationReason`
  (VALID / MALFORMED / SESSION_NOT_FOUND / SESSION_NOT_ACTIVE / WRONG_SESSION /
  EXPIRED / BAD_SIGNATURE / REPLAYED → `QR_INVALID` / `QR_EXPIRED` / `QR_REPLAY` events)

**Service — `Application/Service/Attendance/QrService`**
- `generate()` — `nonce` = 16 random bytes hex, unique per call; `signature` =
  `hash_hmac('sha256', 'qrivo.v1.<uuid>.<ts>.<nonce>', session_secret)`
  (DD-002 — `session_secret` is the key and is never returned); returns
  `ttl_seconds` / `refresh_seconds` / `expires_at`
- `currentQrForOwnedSession()` — teacher must own the session (else `IDOR_ATTEMPT` + 403);
  session must be ACTIVE (else 409)
- `validate()` — non-consuming: shape → `WRONG_SESSION` if expected mismatch →
  session ACTIVE → server-side expiry (age vs TTL ± skew) → HMAC-SHA256 with
  `hash_equals()` → replay (`qr_used_nonces`)
- `validateAndConsume()` — validate then atomically `INSERT` the nonce; a
  repeat/concurrent consumption → `REPLAYED` (used by Phase 12's challenge request)
- `verify()` — the student preflight wrapper; logs bad outcomes at LOW severity,
  never consumes

**Repository**
- `Repository/Attendance/QrNonceRepository` — `nonceExists()`, `consume()` (throws on
  `UNIQUE(nonce)` violation → the race-safe replay guard)

**API**
- `GET /api/v1/teacher/attendance/{id}/qr` — current signed QR for the teacher's
  own ACTIVE session; `attendance.live.view`
- `POST /api/v1/student/attendance/qr/verify` — `{ qr, session_id? }` → `{ valid,
  reason, session_uuid }`; non-consuming; `attendance.qr.submit`

**Security properties**
- QR is dynamic and short-lived; old QRs stop validating once the timestamp ages past the TTL
- HMAC-SHA256 signing keyed per session; `session_secret` never appears in any response
- Tampering any field invalidates the signature (`BAD_SIGNATURE`)
- Replay protection = nonce (`qr_used_nonces`) + expiration
- A QR does **not** create attendance — validation only
- Minimal payload — session UUID + timestamp + nonce + signature, nothing else

**Tests (24 new — 327 total, 685 assertions, 100% passing)**
- `tests/Unit/Application/Service/Attendance/QrServiceTest.php` — payload shape / no secret,
  fresh nonce per generation, HMAC correctness; **valid**, **expired** (past + future-dated),
  **modified/tampered**, **forged / wrong-secret signature**, **malformed**, **wrong session**,
  unknown session, **closed session**, **replay** (+ `QR_REPLAY` event), verify logs
  `QR_EXPIRED` without consuming
- `tests/Unit/Presentation/Http/Controller/Attendance/QrRoutesTest.php` — Router-dispatched
  teacher generate (200 / 401 / 403 non-owner + `IDOR_ATTEMPT` / 409 closed), student verify
  (200 valid / 200 malformed / 403 teacher / 422)

**Not implemented (per instruction):** challenge-response (Phase 12), attendance creation.

### Added (Attendance Sessions — Phase 10)

**Migration**
- `database/migrations/005_create_attendance_sessions.sql` — `attendance_sessions`,
  `attendance_records`; InnoDB / utf8mb4; `UNIQUE(uuid)` (C-026),
  **`UNIQUE(attendance_session_id, student_id)`** (C-001 — the database-level
  duplicate-attendance guard); FK `ON DELETE RESTRICT` (FK-39..FK-45); status /
  source ENUMs; `end_time` nullable

**Domain**
- `Entity/Attendance/AttendanceSession` — `toArray()` deliberately omits
  `session_secret` (DD-002); `Entity/Attendance/AttendanceRecord`
- `Enum/AttendanceSource` (SYSTEM / QR / MANUAL)

**Repositories**
- `Repository/Attendance/AttendanceSessionRepository` — `lockClassRow()`
  (`SELECT … FOR UPDATE` on MySQL, no-op on SQLite),
  `findActiveForClassCourseTerm(lock:)`, `create()`, `findRow()`, `findByUuid()`,
  `activeSessionCountForTeacher()` (OQ-009)
- `Repository/Attendance/AttendanceRecordRepository` —
  `initialiseForClassEnrollment()` (one atomic `INSERT … SELECT` from
  `student_class_assignments`), `countByStatus()`, `countForSession()`, `forSession()`
- `Connection::driverName()` — guards driver-specific SQL

**Service — `Application/Service/Attendance/AttendanceSessionService`**
Implements ATTENDANCE_ALGORITHM.md §2 exactly and in order (not redesigned):

| step | check | mechanism |
|---|---|---|
| 1 | teacher authentication | `BaseController::authenticate()` (bearer token, DB-validated) |
| 2 | teacher authorization | `AttendanceEligibilityService` — TEACHER role + `teachers` profile |
| 3–4 | course + class assignment | `teacher_class_assignments` (C-016) |
| 5–7 | schedule / date / time | `course_schedules` row covering `day_of_week` + `start ≤ now ≤ end` |
| 8 | room | taken from the covering schedule; a supplied `room_id` must match it |
| 9 | academic term | resolved term must be `is_active = 1` |
| 10 | active session check | inside the transaction, after a `classes` row lock — no ACTIVE session may exist for `(class, course, term)` → 409 |

On success, in **one transaction** (CONSTRAINTS.md §6): `INSERT attendance_sessions`
(status `ACTIVE`, server-generated 64-hex `session_secret`, `end_time` NULL,
`expires_at` = scheduled meeting end) + one `WAITING`/`SYSTEM` `attendance_records`
row per enrolled student. `ATTENDANCE_SESSION_STARTED` audit log; every
unauthorized attempt → `UNAUTHORIZED_ATTENDANCE` security event.

**API**
- `POST /api/v1/teacher/attendance/start` — `{ class_id, course_id, [academic_term_id], [room_id] }`
  → `{ session, counts }`; `attendance.session.start` (TEACHER)
- `GET /api/v1/teacher/attendance/{id}` — view one of the caller's own sessions
  (cross-teacher access → `IDOR_ATTEMPT` + 403)

**Tests (23 new — 303 total, 632 assertions, 100% passing)**
- `tests/Unit/Application/Service/Attendance/AttendanceSessionServiceTest.php` — every
  step: non-teacher/unassigned (403 + event), wrong day / outside time / inactive term
  (409), room mismatch (403), duplicate active session (409, records initialised once),
  restart after CLOSED, WAITING/SYSTEM initialisation, `session_secret` never returned,
  audit, `viewOwned` ownership
- `tests/Unit/Presentation/Http/Controller/Attendance/AttendanceStartRoutesTest.php` —
  Router-dispatched 201 / 401 / 403 / 409 / 422

**Not implemented (per instruction):** dynamic QR; and close / cancel / manual
attendance / live counters (later phases).

### Added (Course Assignments & Scheduling — Phase 9)

**Migration**
- `database/migrations/004_create_course_scheduling.sql` — `class_courses`,
  `teacher_courses`, `teacher_class_assignments`, `student_class_assignments`,
  `student_courses`, `course_schedules`; InnoDB / utf8mb4; composite unique keys
  (C-014..C-018); FK `ON DELETE RESTRICT` (FK-20..FK-38); no soft delete

**Entities (`src/Domain/Entity/Schedule/`)**
- `ClassCourse`, `TeacherCourse`, `TeacherClassAssignment`, `StudentClassAssignment`,
  `StudentCourse`, `CourseSchedule` (+ `Domain/Enum/DayOfWeek`)

**Repositories**
- `AbstractCrudRepository` — `createdAtColumn()` / `updatedAtColumn()` overrides so
  the timestamp-light join tables are handled
- `ScheduleRepository` — teacher-attendance authorization lookup, room/teacher
  schedule-conflict detection, and the `student_courses` derivation (DD-005)
- 6 thin per-table repositories under `.../Repository/Schedule/`

**Services (`src/Application/Service/Schedule/`)**
- `ClassCourseService`, `TeacherCourseService`, `TeacherClassAssignmentService`,
  `StudentClassAssignmentService`, `StudentCourseService` (read-only),
  `CourseScheduleService`
- `AbstractAcademicService` — added an `afterDelete()` hook

**Relationships enforced (teachers ↔ students ↔ courses ↔ classes ↔ schedules ↔ rooms ↔ terms)**
- Every assignment row validates its parent references (exist + live) — 422
- Composite uniqueness on every join table — 422 (DB unique key is the backstop → 409)
- `teacher_class_assignments` additionally requires a matching `class_courses` +
  `teacher_courses` (AD-006) so the "teacher/course/class/term" intersection is coherent
- `course_schedules` rejects room and teacher double-booking on the same day (AD-006) — 409
- Enrolling a student derives one `student_courses` row per class course; unenrolling
  or removing a class course prunes them (DD-005)

**Attendance authorization determination (the Phase 9 keystone)**
- `src/Domain/Attendance/AttendanceEligibility.php` + `Domain/Enum/AttendanceEligibilityReason.php`
- `src/Application/Service/AttendanceEligibilityService::forTeacher()` — server-side:
  TEACHER role + profile → `teacher_class_assignments` (steps 3–4) → active/explicit
  term (step 9) → `course_schedules` covering the day + time (steps 5–8) → returns
  `authorized`, `reason`, `teacher_class_assignment_id`, `academic_term_id`, `room_id`, `schedule`
- Probing an unassigned class is logged as a low-severity `UNAUTHORIZED_ACCESS` event
- Phase 10 will call this and must not create a session on a non-`AUTHORIZED` result

**Authorization & API**
- Admin REST (`GET|POST` + `GET|PATCH|DELETE /{id}`) under `/api/v1/admin/`:
  `class-courses`, `teacher-courses`, `teacher-class-assignments`,
  `student-class-assignments`, `course-schedules`; `student-courses` is `GET`-only.
  `assignment.course.manage` / `assignment.schedule.manage` (ADMIN / SUPER_ADMIN)
- `GET /api/v1/teacher/attendance/eligibility?class_id=&course_id=&academic_term_id=&at=`
  — `attendance.session.start` (TEACHER); returns the eligibility result, creates nothing
- `Validator` — added the `time` rule (24h `HH:MM` / `HH:MM:SS`)

**Tests (37 new — 280 total, 585 assertions, 100% passing)**
- `tests/Unit/Application/Service/Schedule/CourseSchedulingServiceTest.php` — CRUD, refs,
  composite uniqueness, tca prerequisites, room/teacher conflict, `student_courses` sync,
  read-only rejection, delete guard
- `tests/Unit/Application/Service/AttendanceEligibilityServiceTest.php` — every eligibility path
- `tests/Unit/Presentation/Http/Controller/Schedule/SchedulingRoutesTest.php` — Router-dispatched
  admin chain, RBAC 401/403, eligibility endpoint
- `tests/Unit/Application/Validation/ValidatorAcademicRulesTest.php` — `time` rule

**Not implemented (per instruction):** dynamic QR, and Phase 10 attendance-session creation.

### Added (Academic & Institutional Structure — Phase 8)

**Entities (`src/Domain/Entity/Academic/`)**
- `School`, `Faculty`, `Department`, `Program`, `Room`, `Course`, `AcademicYear`,
  `AcademicTerm`, `ClassGroup` (table `classes`), `Teacher`, `Student` — immutable
  value objects with `fromRow()` / `toArray()`

**Repositories**
- `AbstractCrudRepository` — shared paged listing (filters + search), soft-delete-aware
  reads, uniqueness checks, `countChildren()` delete-guard helper
- `ReferenceRepository` — parent-existence checks (rejects soft-deleted parents that a
  raw FK cannot catch), `userIsUsable()`, `userHasProfile()`, idempotent `ensureUserRole()`
- 11 thin per-entity repositories under `.../Repository/Academic/`

**Services (`src/Application/Service/Academic/`)**
- `AbstractAcademicService` — validation orchestration, 404/409 semantics, pagination,
  audit logging of every write, DB unique-violation → 409 translation
- 11 per-entity services declaring rules, input mapping, resource shaping,
  cross-entity consistency checks, and blocking child relations

**Validation**
- `Validator` — added `date` (ISO `YYYY-MM-DD`) and `integer_range:min,max` rules
- Per-entity create/update rule sets; `AbstractAcademicService::optional()` derives
  PATCH rules; empty update bodies rejected

**Relationship enforcement (backend + database)**
- Database: migration `003` foreign keys use `ON DELETE RESTRICT` (CONSTRAINTS.md FK-07..FK-19)
- Application: `requireReference()` rejects create/update pointing at a missing or
  soft-deleted parent (422); `blockingChildren` rejects deleting a row that still has
  live children (409); teacher/student `user_id` must be an existing, active, approved user

**Authorization**
- `AbstractResourceController::guard()` — every action authenticates the bearer token
  (server-side, DB-checked) then requires the resource's `academic.*.manage` permission
  (ADMIN / SUPER_ADMIN). Frontend visibility is never trusted; a 403 never names the
  missing permission

**Controllers & API (`src/Presentation/Http/Controller/Admin/`)**
- `AbstractResourceController` + 11 concrete controllers
- `JsonResponse::paginated()` — list envelope with a `meta` block
- REST: `GET|POST /api/v1/admin/{resource}` and `GET|PATCH|DELETE /api/v1/admin/{resource}/{id}`
  for `schools`, `faculties`, `departments`, `programs`, `rooms`, `courses`,
  `academic-years`, `academic-terms`, `classes`, `teachers`, `students`

**Database migration**
- `database/migrations/003_create_academic_structure.sql` — 11 tables, InnoDB / utf8mb4,
  unique keys (C-006..C-025), FK RESTRICT, soft delete on structural entities (DD-009);
  `academic_years` / `academic_terms` are not soft-deleted (per TABLES.md)

**Teacher / Student profiles (OQ-004 interim)**
- Link an existing `users` account; attach the non-privileged `TEACHER` / `STUDENT`
  role on creation (audited `USER_ROLE_ATTACHED`); `user_id` immutable after creation
- Account provisioning itself remains out of scope — see `docs/OPEN_QUESTIONS.md` OQ-004

**Tests (40 new — 243 total, 511 assertions, 100% passing)**
- `tests/Unit/Application/Service/Academic/AcademicStructureServiceTest.php` — CRUD,
  validation, soft delete, audit, relationship enforcement, delete guards, pagination/filter,
  teacher/student linking + role attach
- `tests/Unit/Presentation/Http/Controller/Admin/AcademicAuthorizationTest.php` — dispatched
  through the real Router: 401 unauthenticated, 403 student/teacher, 200/201 admin & super
  admin, 422 validation, 409 delete-with-children, revoked-token rejection
- `tests/Unit/Application/Validation/ValidatorAcademicRulesTest.php` — `date` / `integer_range`
- `tests/Support/AcademicSchemaTrait.php` — in-memory SQLite schema (FKs on) mirroring 001+002+003

**Not implemented (per instruction):** QR attendance, and Phase 9 assignment/scheduling tables.

### Added (Authorization & RBAC — Phase 7)

**Domain Layer**
- `src/Domain/Enum/Permission.php` — 28-permission vocabulary; names derived from `PROJECT_SPECIFICATION.md` §6
- `src/Domain/Authorization/RolePermissionMap.php` — canonical role → permission map (single source of truth for the seed migration and the test suite)
- `src/Domain/Enum/SecurityEventType.php` — added `PRIVILEGE_ESCALATION`

**Application Layer**
- `src/Application/Service/AuthorizationService.php` — server-side authorization engine covering all four layers:
  - **role-based** — `hasRole` / `hasAnyRole` / `requireRole` (SUPER_ADMIN / ADMIN / TEACHER / STUDENT)
  - **permission-based** — `hasPermission` / `requirePermission`; permissions resolved from the database (`user_roles → role_permissions → permissions`), never from client input; SUPER_ADMIN = full access (SECURITY_RULES.md §4)
  - **resource ownership** — `ownsResource` / `requireOwnership` with optional role bypass; failures logged as `IDOR_ATTEMPT`
  - **relationship-based** — `teacherCanAccessClassCourse` / `teacherCanAccessClass` / `teacherCanAccessCourse` / `studentEnrolledInCourse` / `studentEnrolledInClass`; failures logged as `UNAUTHORIZED_ACCESS` / `UNAUTHORIZED_ATTENDANCE`
  - **privilege escalation** — `guardRoleAssignment` (no self-modification; only SUPER_ADMIN grants SUPER_ADMIN); failures logged as `PRIVILEGE_ESCALATION`
  - every denial raises a **generic** `ForbiddenException` (HTTP 403) — the failing check is never disclosed to the client
- `src/Application/Policy/SelfOwnedResourcePolicy.php` — `PolicyInterface` implementation for resource ownership (IDOR/BOLA)
- `src/Application/Policy/AttendanceAuthorizationPolicy.php` — `PolicyInterface` implementation composing role + permission + relationship + ownership for attendance abilities

**Infrastructure Layer**
- `src/Infrastructure/Repository/PermissionRepository.php` — RBAC read access (`getPermissionNamesForUser`, `getPermissionNamesForRoleNames`, `getRoleNamesForUser`)
- `src/Infrastructure/Repository/RelationshipRepository.php` — teacher/student relationship lookups against the assignment tables (per `RELATIONSHIPS.md` §8); **deny-by-default** (never fail-open) until the Phase 8 tables exist (AD-001)

**Presentation Layer**
- `src/Presentation/Http/BaseController.php` — added `authenticate()` (bearer token → validated actor context, re-checked against the database every request) and `authorization()` / `authServiceInstance()` factory helpers; the sanctioned server-side enforcement entry point for all future controllers
- `src/Presentation/Http/Middleware/AuthorizationMiddleware.php` — route-level role/permission gate; fails closed (401 without `auth_user`, 403 on unmet requirement)
- `src/Presentation/Http/Controller/Auth/AuthController.php` — added `GET /api/v1/auth/me` (identity derived only from the token); refactored service wiring onto `BaseController`
- `routes/api.php` — `GET /api/v1/auth/me` registered

**Database Migration**
- `database/migrations/002_seed_rbac_permissions.sql` — seeds the `permissions` catalogue and `role_permissions` mapping (idempotent); mirrors `RolePermissionMap`

**Security properties**
- All authorization decisions are server-side; frontend visibility is never a security boundary
- IDOR / BOLA: resource-scoped actions require an ownership **or** relationship check, not merely a role
- Privilege escalation: role/permission guards deny by default; role-assignment guard blocks self-escalation and non-SUPER_ADMIN granting SUPER_ADMIN
- Client-supplied role/permission/ownership claims are never trusted — roles and permissions are re-resolved from the database
- Denials are logged to `security_events` (`IDOR_ATTEMPT`, `UNAUTHORIZED_ACCESS`, `PRIVILEGE_ESCALATION`, `UNAUTHORIZED_ATTENDANCE`) and return a generic 403

**Tests (48 new — 203 total, 450 assertions, 100% passing)**
- `tests/Unit/Application/Service/AuthorizationServiceTest.php` — RBAC, permission checks, ownership/IDOR, relationship checks, privilege escalation, denial hygiene, security-event logging
- `tests/Unit/Application/Policy/AuthorizationPolicyTest.php` — `SelfOwnedResourcePolicy` and `AttendanceAuthorizationPolicy`
- `tests/Unit/Presentation/Http/Middleware/AuthorizationMiddlewareTest.php` — route-level gate (401/403/200 paths)
- `tests/Unit/Domain/Authorization/RolePermissionMapTest.php` — map integrity and least-privilege separation
- `tests/Support/RbacSchemaTrait.php` — shared in-memory RBAC schema seeded from `RolePermissionMap`

**Documentation**
- `docs/ACCEPTED_DEVIATIONS.md` — added **AD-005** (SUPER_ADMIN = full system access; permission names derived from spec §6 — interim resolution of OQ-005)
- `docs/OPEN_QUESTIONS.md` — OQ-005 given an interim resolution; finer permission-management question left open
- `docs/DEVELOPMENT_PLAN.md` — Phase 7 marked complete; status table updated (203 tests)

### Documentation (Authentication handoff — 2026-08-30)

- `docs/ACCEPTED_DEVIATIONS.md` — new; records four reviewed deviations from the literal source wording:
  - **AD-001** database migrations are incremental per feature/domain, not one monolithic Phase 4
  - **AD-002** login verifies the password before active/approved checks to prevent account-state enumeration (security improvement — must not be reverted without a documented reason)
  - **AD-003** the schedule table is named `course_schedules` (plural), consistent with the frozen database design
  - **AD-004** `PENDING_REVIEW` attendance status — already resolved via OQ-001 (recorded for completeness)
- `docs/DEVELOPMENT_PLAN.md` — synchronized with real project state: Phases 0–3 and 5 marked complete with commit hashes; Phase 4 reframed as incremental migrations; Phase 6 marked complete; added a "Current Status" table
- `docs/README.md` — index updated with `ACCEPTED_DEVIATIONS.md` and `ARCHITECTURE_FREEZE.md`
- `ORIGINAL_SPECIFICATION.md` unchanged (remains the authoritative source)
- Removed stale local `backend/.phpunit.result.cache` (git-ignored build artifact; unrelated to source)

### Added (Authentication — Phase 6)

**Domain Layer**
- `src/Domain/Entity/User.php` — immutable user entity; `toSafeArray()` never exposes `password_hash`
- `src/Domain/Entity/DeviceSession.php` — session entity with `isRevoked()`, `isExpired()`, `isValid()`
- `src/Domain/Contract/LoggerInterface.php` — logger contract decoupling services from concrete implementation

**Application Layer**
- `src/Application/DTO/Auth/LoginRequestDTO.php` — login credentials DTO; `toArray()` intentionally excludes raw password
- `src/Application/DTO/Auth/TokenResponseDTO.php` — token response DTO carrying raw tokens for single-use client delivery
- `src/Application/Service/AuthService.php` — full auth flow: login (Argon2id, constant-time), logout, refresh (token rotation), `validateToken()`; tokens stored as SHA-256 hashes only
- `src/Application/Service/LoginAttemptService.php` — server-side rate limiting by IP + email; threshold and window from config
- `src/Application/Service/SecurityLogService.php` — centralizes `security_events` and `audit_logs` recording; fail-safe (never crashes main flow)

**Infrastructure Layer**
- `src/Infrastructure/Repository/UserRepository.php` — `findByEmail()`, `findByUuid()`, `findUserById()`, `getRoleNames()`
- `src/Infrastructure/Repository/DeviceSessionRepository.php` — token hash lookups, session creation, revocation, last-active update
- `src/Infrastructure/Repository/LoginAttemptRepository.php` — failure counts by IP and email within time windows
- `src/Infrastructure/Repository/SecurityEventRepository.php` — append-only security event creation
- `src/Infrastructure/Repository/AuditLogRepository.php` — append-only audit log creation

**Presentation Layer**
- `src/Presentation/Http/Controller/Auth/AuthController.php` — POST `/api/v1/auth/login`, `/logout`, `/refresh`
- `src/Presentation/Http/Middleware/AuthMiddleware.php` — Bearer token validation on protected routes; attaches user context to request

**Security Features**
- Argon2id password hashing (never plaintext, never logged)
- Constant-time password verification (timing attack prevention)
- 64-byte cryptographically secure access and refresh tokens
- SHA-256 token hashing for database storage (raw tokens never stored)
- Token reuse detection (revoked refresh token → `TOKEN_REUSE` security event)
- Server-side rate limiting (IP + email thresholds)
- All failed logins → `login_attempts` + `security_events`
- All successful logins → `login_attempts` (success=1) + `audit_logs`
- Logout → revoke session; revoked sessions immediately rejected

**Configuration**
- `config/auth.php` — token TTLs and rate limiting thresholds from environment
- `.env.example` updated with `AUTH_*` variables

**Database Migration**
- `database/migrations/001_create_auth_tables.sql` — creates `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `login_attempts`, `device_sessions`, `security_events`, `audit_logs`; seeds default roles

**Routes**
- `routes/api.php` — POST `/api/v1/auth/login`, `/api/v1/auth/logout`, `/api/v1/auth/refresh` now active

**Tests (155 tests, 265 assertions — all passing)**
- `tests/Unit/Application/Service/AuthServiceTest.php` — 28 tests covering all authentication scenarios
- `tests/Unit/Application/Service/LoginAttemptServiceTest.php` — 9 tests covering rate limiting

### Added (Backend Foundation — Phase 5)

**Application Bootstrap**
- `backend/public/index.php` — sole entry point; bootstraps the application
- `backend/src/Bootstrap/App.php` — loads env, config, logger, DB, router, middleware pipeline

**Configuration & Environment**
- `backend/config/app.php` — application settings (name, env, debug, timezone, CORS)
- `backend/config/database.php` — MySQL PDO config (host, port, charset, options)
- `backend/config/logging.php` — Monolog logging settings (channel, level, path, rotation)
- `backend/src/Infrastructure/Config/Config.php` — dot-notation config loader from PHP files + `$_ENV`
- `backend/.env.example` — documented template for all environment variables

**Database Connection**
- `backend/src/Infrastructure/Database/Connection.php` — lazy PDO wrapper with transaction helpers (`transaction()`, `fetchAll()`, `fetchOne()`, `execute()`, `lastInsertId()`, `isConnected()`)

**Routing**
- `backend/routes/api.php` — FastRoute route definitions, versioned under `/api/v1/`
- `backend/src/Presentation/Http/Router.php` — dispatches to controllers, handles 404/405, maps all domain exceptions to HTTP status codes (401, 403, 404, 409, 422, 429, 500)

**Request / Response Handling**
- `backend/src/Presentation/Http/Request.php` — immutable HTTP request value object wrapping superglobals
- `backend/src/Presentation/Http/Response/JsonResponse.php` — standard QRIVO API envelope (`success()`, `error()`, `validationError()`, `created()`, `noContent()`)

**Exception Handling**
- `backend/src/Presentation/Http/ExceptionHandler.php` — global uncaught exception/error handler (logs server-side, never exposes stack traces to client)
- `backend/src/Domain/Exception/DomainException.php` — base domain exception
- `backend/src/Domain/Exception/UnauthorizedException.php` — HTTP 401
- `backend/src/Domain/Exception/ForbiddenException.php` — HTTP 403
- `backend/src/Domain/Exception/NotFoundException.php` — HTTP 404
- `backend/src/Domain/Exception/ConflictException.php` — HTTP 409
- `backend/src/Domain/Exception/ValidationException.php` — HTTP 422
- `backend/src/Domain/Exception/TooManyRequestsException.php` — HTTP 429

**Logging**
- `backend/src/Infrastructure/Logging/Logger.php` — Monolog wrapper with rotating file handler; automatically redacts sensitive keys (`password`, `token`, `secret`, `api_key`, `private_key`, `authorization`) from all log contexts

**Middleware**
- `backend/src/Presentation/Http/Middleware/MiddlewareInterface.php` — middleware contract
- `backend/src/Presentation/Http/Middleware/MiddlewarePipeline.php` — FIFO middleware pipeline with short-circuit support
- `backend/src/Presentation/Http/Middleware/CorsMiddleware.php` — CORS headers + OPTIONS preflight; origins configured via env
- `backend/src/Presentation/Http/Middleware/JsonBodyMiddleware.php` — validates JSON Content-Type on request body

**Validation**
- `backend/src/Application/Validation/Validator.php` — rule-based input validator supporting: `required`, `string`, `integer`, `numeric`, `boolean`, `email`, `min`, `max`, `min_length`, `max_length`, `in`, `uuid`

**Service Layer**
- `backend/src/Application/Service/BaseService.php` — abstract base for all application services

**Repository Layer**
- `backend/src/Infrastructure/Repository/BaseRepository.php` — abstract base with shared helpers: `exists()`, `findById()`, `insert()`, `update()`, `softDelete()`

**Domain Contracts & Enums**
- `backend/src/Domain/Contract/RepositoryInterface.php` — generic CRUD repository contract
- `backend/src/Domain/Contract/PolicyInterface.php` — authorization policy contract (RBAC + resource + relationship)
- `backend/src/Domain/Contract/ServiceInterface.php` — service layer marker interface
- `backend/src/Application/DTO/BaseDTO.php` — abstract base Data Transfer Object
- `backend/src/Domain/Enum/UserRole.php` — `SUPER_ADMIN`, `ADMIN`, `TEACHER`, `STUDENT` with hierarchy helpers
- `backend/src/Domain/Enum/AttendanceStatus.php` — `WAITING`, `PRESENT`, `ABSENT`, `LATE`, `EXCUSED`, `PENDING_REVIEW`
- `backend/src/Domain/Enum/SessionStatus.php` — `ACTIVE`, `CLOSED`, `CANCELLED`
- `backend/src/Domain/Enum/SecurityEventType.php` — all security event categories from the specification

**Controllers**
- `backend/src/Presentation/Http/BaseController.php` — shared controller infrastructure (DB, logger, config, response helpers)
- `backend/src/Presentation/Http/Controller/HealthController.php` — `GET /api/v1/health` with DB health check

**Tests (118 tests, 202 assertions — all passing)**
- `backend/tests/Unit/Infrastructure/Config/ConfigTest.php`
- `backend/tests/Unit/Infrastructure/Database/ConnectionTest.php`
- `backend/tests/Unit/Infrastructure/Logging/LoggerTest.php`
- `backend/tests/Unit/Presentation/Http/RequestTest.php`
- `backend/tests/Unit/Presentation/Http/Response/JsonResponseTest.php`
- `backend/tests/Unit/Presentation/Http/Middleware/CorsMiddlewareTest.php`
- `backend/tests/Unit/Presentation/Http/Middleware/MiddlewarePipelineTest.php`
- `backend/tests/Unit/Application/Validation/ValidatorTest.php`
- `backend/tests/Unit/Domain/Exception/DomainExceptionTest.php`
- `backend/tests/Unit/Domain/Enum/DomainEnumTest.php`

**Documentation**
- `backend/README.md` — setup guide, directory structure, API reference, architecture overview, security notes

### Added

- `docs/ARCHITECTURE_FREEZE.md` — frozen component catalogue with responsibilities, inputs, outputs, dependencies, and security boundaries for all 15 major components; lists 14 decisions that must not change during implementation
- `database/docs/ER_DIAGRAM.md` — complete Mermaid ER diagram for all 31 tables across 8 domain groups
- `database/docs/TABLES.md` — full column definitions, types, nullability, keys and indexes for all 31 tables
- `database/docs/RELATIONSHIPS.md` — complete cardinality documentation and attendance algorithm lookup path
- `database/docs/INDEXES.md` — all indexes with priority ratings and algorithm-backed justifications
- `database/docs/CONSTRAINTS.md` — 26 uniqueness constraints, 53 foreign keys with ON DELETE policies, ENUM values, transaction boundaries
- `database/docs/DATABASE_DECISIONS.md` — 13 explicit design decisions each tied to a specification requirement

### Changed

- `docs/ATTENDANCE_ALGORITHM.md` — added `PENDING_REVIEW` to attendance states table (OQ-001 resolution)
- `docs/OPEN_QUESTIONS.md` — OQ-001 resolved: `PENDING_REVIEW` is a full `attendance_records.status` value


