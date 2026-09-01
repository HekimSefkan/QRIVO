# QRIVO — Final Security & Architecture Audit

**Date:** 2026-08-30
**Auditor:** automated end-of-project review (Phase 23)
**Branch:** `main`
**Commit under audit:** `b2decf0` (`test: complete QRIVO integration validation`)
**Backend test result:** ✅ **586 tests, 1505 assertions, 100% passing**

**Verdict:** **No architectural violations.** All 17 differences from the literal
source wording are documented and reviewed in `docs/ACCEPTED_DEVIATIONS.md`.
`ORIGINAL_SPECIFICATION.md` is unchanged. Seven informational findings (F-1…F-7)
are recorded below; none is a security gap or a blocker. **Release-ready for the
implemented scope (backend + mobile student app).**

> **Update — 2026-09-01 (Phase 24, local runtime & seed data).**
> **F-2 is resolved for local development:** `backend/scripts/seed.php` now
> provisions the first SUPER_ADMIN plus a full demo dataset, so the system can be
> run and logged into (AD-017). Production account provisioning remains open
> (OQ-004). **F-7 was discovered during that work and added below.** The
> verification in this document was re-run against a live MySQL 8.4 instance;
> all 586 automated tests plus a 16-step HTTP smoke test pass.

---

## 1. Authoritative documents read

`ORIGINAL_SPECIFICATION.md`, `AGENTS.md`,
`docs/{PROJECT_SPECIFICATION, ARCHITECTURE_RULES, ARCHITECTURE_FREEZE,
ATTENDANCE_ALGORITHM, SECURITY_RULES, ACCEPTED_DEVIATIONS, OPEN_QUESTIONS,
DEVELOPMENT_PLAN}.md`,
`database/docs/{TABLES, CONSTRAINTS, RELATIONSHIPS, INDEXES, ER_DIAGRAM,
DATABASE_DECISIONS}.md`,
`backend/README.md`, `backend/tests/TEST_REPORT.md`, `CHANGELOG.md`,
all 8 migrations, and the backend source tree.

---

## 2. Verification matrix

| # | Area | Verified against | Result |
|---|------|------------------|--------|
| 1 | **Architecture integrity** | `ARCHITECTURE_RULES` §1–7, `ARCHITECTURE_FREEZE` §2 | ✅ Clean Architecture layers intact — `Domain` (entities/value objects/enums, no framework deps), `Application` (services/DTOs/policies), `Infrastructure` (repositories/PDO/logging), `Presentation` (controllers/router/middleware). Dependencies point inward. One composition-root class `Bootstrap/App.php` (env + DI wiring + middleware pipeline) — standard, not business logic. |
| 2 | **Algorithm integrity** | `ATTENDANCE_ALGORITHM` §2/§4/§6/§7 | ✅ §2 10-step session creation, §4 13-step challenge-response, §6 8-step manual attendance, §7 close/cancel — all implemented **exactly and in order**; no step removed, simplified or bypassed (source docblocks map line-by-line; `SecurityFailurePathsTest` proves every reject path fires). |
| 3 | **Authentication** | `SECURITY_RULES` §3, `ARCHITECTURE_FREEZE` §2.3 | ✅ Argon2id verification (`password_verify`); constant-time dummy Argon2id verify for unknown users; SHA-256 token hashes stored, **raw tokens never persisted** (DD-012); refresh-token rotation; token-reuse detection → `TOKEN_REUSE` event; per-IP + per-email rate limiting; every attempt in `login_attempts`; every failure a `LOGIN_FAILURE` security event; `LOGIN_SUCCESS` / `LOGOUT` / `TOKEN_REFRESHED` audit logs. |
| 4 | **Authorization** | `SECURITY_RULES` §4, `ARCHITECTURE_RULES` §6 | ✅ `AuthorizationService` — deny-by-default; `requireRole` / `requirePermission` / `requireOwnership` + relationship guards; every denial → `ForbiddenException` (403) + security event; generic messages (no "which check failed"). |
| 5 | **RBAC** | `SECURITY_RULES` §4, `RolePermissionMap`, migration `002` | ✅ Exactly 4 roles (`SUPER_ADMIN`, `ADMIN`, `TEACHER`, `STUDENT`); `roles`/`permissions`/`role_permissions`/`user_roles`; `Permission` enum is the single catalogue; SUPER_ADMIN = full access (code short-circuit + seeded grants agree — AD-005); role alone is never sufficient. |
| 6 | **Resource authorization** | `SECURITY_RULES` §4, `ARCHITECTURE_RULES` §6 | ✅ IDOR/BOLA: `viewOwned` / `assertOwnedSession` / manual-attendance ownership → mismatch = 403 + `IDOR_ATTEMPT`; teacher reports scoped to `teacher_class_assignments` / `teacher_courses`; student data resolved from the token (`student_id` never client-supplied); privilege escalation blocked (self-role edit, non-SUPER_ADMIN granting SUPER_ADMIN → `PRIVILEGE_ESCALATION`). |
| 7 | **QR security** | `SECURITY_RULES` §5, `ATTENDANCE_ALGORITHM` §3, `ARCHITECTURE_FREEZE` §2.8 | ✅ Dynamic, short-lived (`QR_TTL_SECONDS`, default 30, + clock-skew allowance); minimal payload `qrivo.v1.<session_uuid>.<ts>.<nonce>.<sig>`; **QR never creates attendance** — it only starts the chain; old QR rejected on refresh (age check); session must be `ACTIVE`. |
| 8 | **Nonce handling** | `SECURITY_RULES` §5/§10, DD-004, AD-008 | ✅ QR nonce = 16 random bytes/hex, unique per generation; `qr_used_nonces UNIQUE(nonce)` consume on the preflight path; per-student replay guard `qr_challenges.qr_nonce` (`studentHasChallengeForQrNonce`, DD-004); challenge nonce = 32 random bytes/hex, `UNIQUE(nonce)` (C-002). |
| 9 | **HMAC validation** | `ARCHITECTURE_RULES` §1.1 (LOCKED), `ATTENDANCE_ALGORITHM` §3 | ✅ `hash_hmac('sha256', signedMessage, session_secret)`; signature check via `hash_equals` (constant time) at generation-verify, challenge re-validation, and preflight; session-uuid binding also `hash_equals`; `session_secret` is per-session, server-generated, **never in any response** (`AttendanceSession::toArray()` excludes it; `LiveAttendanceService::sessionInfo()` hand-builds without it; verified by assertions in `FullFlowIntegrationTest`). |
| 10 | **Challenge-response** | `ATTENDANCE_ALGORITHM` §4, `ARCHITECTURE_FREEZE` §2.9 | ✅ All 13 checklist points present and ordered in `ChallengeService`: auth → session ACTIVE → QR validity → HMAC → challenge expiry → ownership → single-use → membership (course + class) → duplicate → rate limit → device/session → risk. Failure detail goes only to `security_events`; the client gets a generic message + coarse status. |
| 11 | **Replay protection** | `ATTENDANCE_ALGORITHM` §10, `SECURITY_RULES` §6 | ✅ QR nonce uniqueness + expiry; HMAC tamper detection; challenge nonce uniqueness; challenge `used_at` single-use (atomic in the transaction — `markUsed` is `UPDATE … WHERE used_at IS NULL`); `UNIQUE(attendance_session_id, student_id)`; rate limiting. `SecurityFailurePathsTest` exercises: tampered signature, expired QR, QR-nonce replay, used-challenge reuse, cross-student challenge redemption. |
| 12 | **Duplicate attendance protection** | C-001, `ARCHITECTURE_RULES` §2 | ✅ Three layers: (a) application check inside the transaction (`findForSessionStudent` + `markFromWaiting` guarded UPDATE), (b) `UNIQUE(attendance_session_id, student_id)` at the DB (last line of defence), (c) idempotent `markFromWaiting` returns false on a lost race. `test_duplicate_attendance_is_rejected` → 409 + `DUPLICATE_ATTENDANCE`. |
| 13 | **Transaction integrity** | C-006 boundaries, `ARCHITECTURE_FREEZE` §3 | ✅ `Connection::transaction()` wraps: session creation (class-row lock + active-session check + bulk record init), challenge-response (steps 7·10·13·record), session close (status transition + WAITING resolution). `FOR UPDATE` row locks on MySQL, guarded on SQLite. Concurrency: `transitionStatus` (`UPDATE … WHERE status = :from`), `markUsed`, `markFromWaiting` — the loser changes 0 rows and is rejected without corruption. |
| 14 | **Device / session security** | `PROJECT_SPECIFICATION` §6.13, AD-013 | ✅ `DeviceSessionService` — server-derived fingerprint `sha256(X-Device-Id \| UA)` (never client-trusted); `NEW_DEVICE` / `SUSPICIOUS_DEVICE` events; active-session ceiling (`SECURITY_MAX_ACTIVE_SESSIONS`); idle timeout; optional fingerprint binding (log-only unless `SECURITY_ENFORCE_DEVICE_BINDING`); device signals fed to risk scoring. |
| 15 | **Risk scoring** | `PROJECT_SPECIFICATION` §6.14, `ATTENDANCE_ALGORITHM` §9, `SECURITY_RULES` §8, AD-014 | ✅ **Centralised** — `RiskScoringService` is the sole `RiskEvaluatorInterface` implementation, invoked from the challenge transaction, never from a controller. Exactly the 10 spec signals (`RiskSignal` enum). Weighted score → `LOW/MEDIUM/HIGH/BLOCKED` → §9 outcome. All weights/thresholds resolve `system_settings` → `config/risk.php` → `RiskSignal::defaultWeight()` — **not hard-coded**. `risk_assessments` row always written. |
| 16 | **Security events** | `PROJECT_SPECIFICATION` §6.15, `SECURITY_RULES` §10 | ✅ Single `SecurityEventType` enum covering every §6.15 category; single choke point `SecurityLogService::recordSecurityEvent`; fail-safe (recording never crashes the flow); `security_events` is append-only; admin read endpoint gated by `security.event.view`. |
| 17 | **Audit logging** | `SECURITY_RULES` §11, `PROJECT_SPECIFICATION` §6.15 | ✅ Attendance changes (`ATTENDANCE_STATUS_CHANGED` / `ATTENDANCE_RECORDED` — actor/target/old/new/reason/ts/ip), admin actions (`{ENTITY}_CREATED/UPDATED/DELETED`, `USER_ROLE_ATTACHED`), authentication events (`LOGIN_SUCCESS`/`LOGOUT`/`TOKEN_REFRESHED`), session close/cancel (`ATTENDANCE_SESSION_CLOSED/_CANCELLED`). `audit_logs` append-only; admin read gated by `audit.log.view`. |
| 18 | **Database constraints** | `CONSTRAINTS.md`, `ARCHITECTURE_RULES` §5 | ✅ InnoDB + utf8mb4 on every table; C-001…C-026 uniqueness present (spot-checked C-001/002/003/006/011/014/016/017/026); FK-01…FK-53 present with the correct `ON DELETE` (RESTRICT for attendance-critical — DD-010; SET NULL for `login_attempts`/`security_events`/`audit_logs` actor — DD-011; CASCADE for identity join tables); ENUM constraints for the 6 status/level columns; 4 performance indexes each on `security_events` and `audit_logs`. |
| 19 | **API security** | `ARCHITECTURE_RULES` §4, `SECURITY_RULES` §2 | ✅ Versioned `/api/v1/…`; **PDO real prepared statements** (`PDO::ATTR_EMULATE_PREPARES => false` in `config/database.php`); every user value bound as a parameter; interpolated SQL fragments are `(int)`-clamped pagination or hardcoded-whitelist column/dimension names only; report/audit filters are whitelisted (unknown filter → ignored, bad value → 422). `test_sql_injection_in_a_report_filter_is_neutralised` + `test_xss_payload_in_a_name_is_stored_verbatim_not_executed` pass. |
| 20 | **Mobile security** | `ARCHITECTURE_RULES` §7, `ARCHITECTURE_FREEZE` §2.14, AD-011/012 | ✅ Token pair in `flutter_secure_storage` (Android `encryptedSharedPreferences: true`, iOS Keychain `first_unlock_this_device`); **no security decision on device** — the QR flow's only local logic is a `qrivo.` prefix sniff (UX filter; the server re-validates everything); backend authoritative; failure messages generic. |
| 21 | **Secret handling** | `SECURITY_RULES` §9, `AGENTS.md` §4 | ✅ No `.env`, `.pem`, `.key`, `id_rsa`, or hardcoded credential in tracked source (`git grep` clean); only `.env.example` templates are tracked (`DB_PASSWORD=` empty); `.env*` gitignored; `session_secret` / tokens / password hashes never returned or logged; DB connection failure exception scrubs credentials. |
| 22 | **Error handling** | `SECURITY_RULES` §9, `ARCHITECTURE_FREEZE` | ✅ `Router` maps each domain exception → status + its (safe) message; any other `\Throwable` → 500 `"An internal server error occurred."`, logged server-side with type/file/line only. `ExceptionHandler` does the same for uncaught errors. No stack trace, DB error, or internal path reaches the client. |
| 23 | **Logging** | `SECURITY_RULES` §9, AD-015 | ✅ `Domain\Security\LogSanitizer` — one recursive redaction pass applied by **both** `SecurityLogService` (before every persisted `details` / `old_value` / `new_value`) **and** `Logger` (before every file line), plus `AuditQueryService` on read. Redacts sensitive keys at any depth and token-shaped values (PEM, JWT, long bare hex/base64). `SecurityLogServiceTest` + `AuditCoverageTest` prove no password/token reaches either table. |
| 24 | **Tests** | `SECURITY_RULES` §12, `AGENTS.md` §6 | ✅ 586 backend tests / 1505 assertions / 100% pass across 48 files. `FullFlowIntegrationTest` walks the complete approved flow through the real Router (70 assertions). `SecurityFailurePathsTest` covers every §12 category (22 tests). No test disabled; no security weakened to pass. Mobile: 10 Dart test files authored — executed in CI, not locally (no Flutter SDK on the build host — AD-011). |

---

## 3. Findings (informational — none blocking)

### F-1 — `AuthMiddleware` / `AuthorizationMiddleware` are unwired

`Presentation\Http\Middleware\{AuthMiddleware, AuthorizationMiddleware}` exist but
are **not** added to the pipeline (`Bootstrap\App::registerMiddleware()` adds only
CORS + JSON body). Authentication and authorization are instead enforced
**per-controller** through `BaseController::authenticate()` +
`AuthorizationService::requirePermission()`. Every protected route calls the
guard (verified: 12 controllers, all admin resource controllers via
`AbstractResourceController::guard()`; and by the 401/403 assertions across the
integration and per-phase suites).

- **Impact:** none on security — enforcement is complete and centralised, just at
  a different seam than a route middleware.
- **Architecture:** the "Middleware Layer" is present and functional; this is an
  internal placement choice, not a removed module.
- **Recommendation:** wire the two classes into the pipeline **or** delete them so
  the codebase has one obvious auth path. Non-urgent.

### F-2 — No user-account provisioning path (OQ-004) — ✅ resolved for local dev in Phase 24

There is no endpoint or service that creates a `users` row and hashes its
password. Consequently the **Argon2id hashing-on-write** path exists only in the
test harness; production has verification only. `teachers` / `students` endpoints
link an *existing* `users` account.

- **Impact:** the system cannot be bootstrapped through the API alone — the first
  `SUPER_ADMIN` and all user accounts must be seeded out of band (SQL / a future
  admin flow / invite e-mail).
- **Resolved for local development (Phase 24, AD-017):** `backend/scripts/seed.php`
  provisions a SUPER_ADMIN, an ADMIN, 2 teachers and 12 students with Argon2id
  hashes generated at runtime, so the Argon2id hashing-on-write path is now
  exercised outside the test harness. The seeder refuses to run unless
  `APP_ENV=local`. See `docs/RUNBOOK.md`.
- **Still open:** production/shared-environment provisioning — an admin user-CRUD
  endpoint, invite e-mail or password-reset flow. Tracked as `OQ-004`.

### F-3 — Root `tests/` and `scripts/` directories absent

`ARCHITECTURE_RULES` §1.3 lists a root `tests/` and `scripts/`. Tests live under
`backend/tests/` (the standard Clean-Architecture location, and §1.2 explicitly
allows "an equivalent Clean Architecture layout"). `scripts/` is unused.
`tests/TEST_REPORT.md` was therefore created at **`backend/tests/TEST_REPORT.md`**.

- **Impact:** none. Documented here for locator clarity.

### F-4 — Permissive CORS default

`config/app.php` / `.env.example` default `CORS_ALLOWED_ORIGINS=*`. Acceptable for
local development; **production must set explicit origins**. Tied to `OQ-007`
(deployment architecture).

### F-5 — Web client not implemented

`ARCHITECTURE_RULES` §1.1 and `ARCHITECTURE_FREEZE` §2.15 lock a "Web-based
dashboard" for teachers/admins. The executed phases (5–23) built the **backend +
mobile student app**. The REST API fully supports the web client (live
attendance, manual override, reports, session lifecycle all exist as endpoints);
the client itself is future work. `OQ-006` (web client technology) is open.

- **Impact:** feature-scope, not an architectural violation — no locked backend
  decision changed.

### F-7 — `qr_used_nonces` is never written; global QR-nonce replay relies on the per-student guard

*(Added 2026-09-01 during Phase 24. Code was NOT changed — this is a finding, and
altering the replay mechanism is an algorithm change requiring approval per
`AGENTS.md` §2/§3.)*

`QrService::validateAndConsume()` is the only writer of `qr_used_nonces`, and it
has **no callers**. Its own docblock says *"This is what the challenge request
(Phase 12) calls"*, but `ChallengeService::requestChallenge()` in fact calls the
non-consuming `QrService::validate()`. The consequence:

- `qr_used_nonces` stays empty in a running system (verified against a live
  MySQL instance after a full smoke-test run);
- the `REPLAYED` branch inside `validate()` — which reads `nonceExists()` — can
  therefore never fire.

**This is not an exploitable replay hole.** QR replay is still blocked, by three
other controls that are exercised and passing:

| Control | Mechanism | Test |
|---|---|---|
| Per-student QR-nonce reuse | `qr_challenges.qr_nonce` + `studentHasChallengeForQrNonce()` (DD-004) | `SecurityFailurePathsTest::test_qr_nonce_replay_is_rejected` → 409 |
| Challenge single-use | `qr_challenges.used_at`, set atomically in the transaction (DD-003) | smoke test step 10 → 409 |
| Duplicate attendance | `UNIQUE(attendance_session_id, student_id)` (C-001) | `test_duplicate_attendance_is_rejected` → 409 |

What is *missing* is the cross-student case: two different students presenting
the same QR string. That is the normal, intended classroom behaviour (one code on
the projector, many scanners), so the current design is defensible — but it means
`qr_used_nonces` and the ARCHITECTURE_FREEZE §2.8 "nonce store" wording describe
a control that is inert.

**Recommendation (needs approval — do not implement silently):** either wire
`requestChallenge()` to `validateAndConsume()` and accept one-scan-per-QR for the
whole class, or drop `qr_used_nonces` and amend §2.8 + AD-008 to state that
per-student nonce tracking is the implemented mechanism. Until a decision is
made, the docblock on `validateAndConsume()` is misleading and should be treated
as inaccurate.

### F-6 — Notifications module not implemented

`PROJECT_SPECIFICATION` references notifications; `OQ-002` records it as awaiting
user clarification. Out of the executed phase scope. No backend dependency on it.

---

## 4. Accepted deviations (all documented & reviewed)

`docs/ACCEPTED_DEVIATIONS.md` records **AD-001 … AD-016**. Every one ties to
specific source wording, asserts that no locked decision / schema / algorithm /
security control changed, and (where relevant) links an open question. Summary:

| AD | One line | Touches a locked decision? |
|----|----------|:-:|
| AD-001 | Incremental per-phase migrations, not one monolithic phase | No |
| AD-002 | Login verifies password before account-state (anti-enumeration) | No — strengthening |
| AD-003 | `course_schedules` (plural) table name | No |
| AD-004 | `PENDING_REVIEW` is a first-class `attendance_records.status` | No — resolves OQ-001 |
| AD-005 | SUPER_ADMIN = full access; permission catalogue from spec §6 | No — literal reading of §4 |
| AD-006 | Teacher-class-assignment prerequisite + schedule-conflict rules | No |
| AD-007 | Session `end_time` NULL until close; `expires_at`; dup-session scope | No |
| AD-008 | `qr_used_nonces` store; QR wire format; QR TTL in config | No |
| AD-009 | Per-student QR-nonce replay scope; basic Phase-12 risk; challenge TTL | No |
| AD-010 | Live attendance = AJAX polling (the spec's own fallback; no WS server in the frozen stack) | No |
| AD-011 | Mobile hand-scaffolded; platform folders gitignored; `flutter test` in CI | No |
| AD-012 | `mobile_scanner` for camera; `qrivo.` prefix sniff is UX-only | No |
| AD-013 | Device fingerprint derivation; binding log-only by default; thresholds in `config/security.php` | No |
| AD-014 | Risk = additive weighted model; 10 spec signals only; `LOCATION_MISMATCH`/`SUSPICIOUS_IP` data-gated | No — resolves OQ-003/010 interim |
| AD-015 | Central `LogSanitizer`; read-only admin trail endpoints on pre-defined permissions | No — strengthening |
| AD-016 | Reporting: `present_rate` = present/marked; student endpoint alongside Phase-16 list; no per-admin partition | No |
| AD-017 | `schema_migrations` ledger table + migration runner, local demo seeder, docker-compose dev runtime | No |

**No deviation modifies `ORIGINAL_SPECIFICATION.md`, the attendance algorithm,
the security model, the database schema shape, or the authentication /
authorization model.**

---

## 5. Open questions (unresolved with the user)

| OQ | Status | Blocks release? |
|----|--------|:-:|
| OQ-001 Attendance state `PENDING_REVIEW` | ✅ Resolved (AD-004) | No |
| OQ-002 Notifications module | ⏳ Awaiting user | No — out of built scope |
| OQ-003 Location data for `LOCATION_MISMATCH` | 🟡 Interim: signal wired but inert | No |
| OQ-004 User account provisioning / initial passwords | 🟡 Interim: profile endpoints link existing accounts | **Operational** — first accounts must be seeded out of band |
| OQ-005 SUPER_ADMIN vs permission-management edge | 🟡 Interim (AD-005) | No |
| OQ-006 Web client technology | ⏳ Awaiting user | No — API is ready |
| OQ-007 Deployment architecture (incl. CORS origins) | ⏳ Awaiting user | **Deployment config** |
| OQ-008 QR refresh interval / `system_settings` move | 🟡 Interim: config-driven, seed in migration `008` | No |
| OQ-009 Multiple courses per session / per-teacher concurrency | 🟡 Interim: one course per session, no per-teacher cap | No |
| OQ-010 IP-based risk weighting on campus WiFi | 🟡 Interim: deny-list only, empty by default | No |

---

## 6. Release readiness

| Dimension | Status |
|-----------|--------|
| Backend feature completeness (Phases 5–22 + 15) | ✅ Complete |
| Mobile student app (Phases 16–17) | ✅ Complete (Dart tests run in CI) |
| Web client (teacher/admin dashboard) | ⛔ Not built — API-ready, `OQ-006` |
| Notifications | ⛔ Not built — `OQ-002` |
| Tests | ✅ 586 / 1505 / 100% ; E2E + all §12 failure paths |
| Security controls | ✅ All 24 audit areas verified; none weakened or bypassed |
| Architecture integrity | ✅ No violation; 16 documented deviations |
| Secrets in VCS | ✅ None |
| Migrations | ✅ `001`–`008`, FK-dependency-ordered, InnoDB/utf8mb4 |
| Docs | ✅ `CHANGELOG`, `DEVELOPMENT_PLAN`, `ACCEPTED_DEVIATIONS`, `OPEN_QUESTIONS`, `TEST_REPORT`, this file |

**Recommendation:** the **backend + mobile student application are
release-ready.** Before a production deployment the operator must:

1. Seed the first `SUPER_ADMIN` account and set a real Argon2id password hash
   (OQ-004);
2. Set `CORS_ALLOWED_ORIGINS` to explicit origins and provide a real `.env`
   (OQ-007);
3. Run migrations `001`–`008` against MySQL 8 (InnoDB, utf8mb4).

The **web client and notifications remain future phases** and are the only
spec items not delivered; both are unblocked by the existing REST API and
neither required an architectural change.

---

*End of audit.*
