# Changelog

All notable changes to the QRIVO project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Conventional Commits](https://www.conventionalcommits.org/).

---

## [Unreleased]

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


