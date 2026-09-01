# QRIVO — Open Questions

> **This file documents genuinely ambiguous, underspecified, or potentially contradictory requirements.**
> Nothing here is assumed or guessed. Each question must be resolved before the affected module is implemented.

---

## OQ-001: Attendance State — PENDING_REVIEW

**Source:** Attendance close flow and risk scoring

**Question:** The specification mentions two possible outcomes for `WAITING` students when a session is closed:

1. `ABSENT`
2. `PENDING_REVIEW`

The choice is determined by `system_settings`. However, `PENDING_REVIEW` also appears as a risk scoring outcome.

**Resolution:** ✅ **RESOLVED during database architecture design (2026-08-29)**

`PENDING_REVIEW` is a full `attendance_records.status` value. Rationale:

1. The session-close flow explicitly writes it to student records (per `system_settings`)
2. The risk scoring outcome `PENDING_REVIEW` maps to the same attendance status
3. Teachers can resolve it via manual attendance (changing to PRESENT/ABSENT/LATE/EXCUSED)

**Decision recorded in:** `database/docs/TABLES.md`, `database/docs/CONSTRAINTS.md`, `docs/ATTENDANCE_ALGORITHM.md`

**Status:** ✅ Resolved — no blocking ambiguity

---

## OQ-002: Notifications Module

**Source:** Development phase list

**Question:** Phase 26 in the original specification lists "Notifications" as a development phase. However, no detailed requirements are provided for this module.

- What types of notifications are expected? (e.g., attendance started, attendance recorded, security alerts)
- Delivery channels? (in-app, push notification, email, SMS)
- Which actors receive which notifications?

**Impact:** Backend module, mobile module, database tables

**Status:** ⏳ Awaiting user clarification

---

## OQ-003: Location Data

**Source:** Risk scoring

**Question:** Risk scoring includes "location mismatch" as a signal. However, the specification does not define:

- Whether GPS/location data is collected from the mobile device
- Whether room/building coordinates are stored
- What constitutes a "mismatch"
- Privacy implications of location tracking

**Impact:** Mobile app permissions, database schema, risk scoring service

**Interim resolution (Phase 19, 2026-08-30):** `LOCATION_MISMATCH` is a
first-class member of the fixed risk-signal catalogue
(`QRIVO\Domain\Enum\RiskSignal`) with a configurable weight
(`risk.weight.location_mismatch`, default 40). It is **data-gated**: the risk
engine only counts it when a caller explicitly passes
`location_mismatch: true` in the evaluation context. No component supplies that
today (no GPS collection, no room coordinates), so the signal is inert. When a
location pipeline is specified, only the detection side is added — the scoring
model already accommodates it. Recorded in `ACCEPTED_DEVIATIONS.md` AD-014.

**Still open:** whether to collect device GPS, whether to store room/building
coordinates, the mismatch radius/definition, and the privacy model.

**Status:** 🟡 Interim resolution in place — signal wired but inert; location
data-collection policy still awaiting user clarification

---

## OQ-004: Student Account Creation

**Source:** Academic structure management

**Question:** The specification defines admin-managed entities including Students. However, it does not specify:

- How student accounts are created (admin manual entry, bulk import, self-registration?)
- How student passwords are initially set (admin-set, email invite, default?)
- Whether students can self-register or if accounts are always admin-provisioned

**Impact:** Authentication module, admin module, onboarding flow

**Interim resolution (Phase 8, 2026-08-30):** The `teachers` / `students`
endpoints create a **profile that links an existing `users` account** (which
must be active and approved). On creation the non-privileged `TEACHER` /
`STUDENT` role is attached to that account (audited as `USER_ROLE_ATTACHED`).
`user_id` is immutable after creation. This keeps Phase 8 scoped to the 11
structural entities.

**Still open:** how the underlying `users` rows are provisioned (admin form,
bulk import, invite e-mail, self-registration) and how initial passwords are
set. No `users` CRUD endpoint exists yet.

**Update (Phase 24, 2026-09-01):** local development is now unblocked.
`backend/scripts/seed.php` provisions the first `SUPER_ADMIN` plus an ADMIN, two
teachers and twelve students, computing every `password_hash` at runtime with
`PASSWORD_ARGON2ID` from `SEED_DEFAULT_PASSWORD` in the gitignored
`backend/.env`. It refuses to run unless `APP_ENV=local`, so it can never create
demo accounts in a shared or production database. See `docs/RUNBOOK.md` and
`ACCEPTED_DEVIATIONS.md` AD-017.

**Still open:** how accounts are provisioned in a real deployment — an admin
user-CRUD endpoint, bulk import, invite e-mail, or self-registration — and how
initial passwords are delivered and rotated. No `users` CRUD endpoint exists.

**Status:** 🟡 Local development resolved (Phase 24) — production provisioning flow still awaiting user clarification

**Source:** RBAC

**Question:** The specification defines both `SUPER_ADMIN` and `ADMIN` roles. The distinction is:

- SUPER_ADMIN: "Full system control"
- ADMIN: "Institution-level, permission-controlled"

**Is SUPER_ADMIN intended to bypass the permission system entirely?** Or does SUPER_ADMIN simply have all permissions assigned?

**Impact:** Authorization middleware, permission checks

**Interim resolution (Phase 7, 2026-08-30):** SUPER_ADMIN is treated as **full
system access** — `AuthorizationService::hasPermission()` short-circuits to
`true` for SUPER_ADMIN, and `002_seed_rbac_permissions.sql` also grants
SUPER_ADMIN every permission row (both agree). ADMIN is strictly
permission-controlled. This is the literal reading of `SECURITY_RULES.md` §4.
Recorded in `docs/ACCEPTED_DEVIATIONS.md` AD-005.

**Still open:** whether SUPER_ADMIN should also bypass *permission-management*
constraints (e.g. editing `role_permissions`, or protections around the last
SUPER_ADMIN account). Does not block current work.

**Status:** 🟡 Interim resolution in place — finer question awaiting user clarification

---

## OQ-006: Web Client Technology

**Source:** Technology stack

**Question:** The specification defines "Web-based dashboard" for the web client but does not specify a frontend framework or technology. The backend is PHP.

Options include:
- Server-rendered PHP templates (Blade-like)
- Separate SPA (React, Vue, etc.)
- Simple HTML/CSS/JS with AJAX

**Which approach is intended?** This affects project structure and build tooling.

**Impact:** Frontend architecture, project structure, build pipeline

**Status:** ⏳ Awaiting user clarification

---

## OQ-007: Deployment Architecture

**Source:** Phase 28 (Deployment)

**Question:** No deployment requirements are specified. Questions include:

- Target hosting environment (VPS, cloud, university infrastructure?)
- Docker usage?
- Reverse proxy (Nginx, Apache)?
- SSL/TLS requirements?
- Database hosting (same server, managed service?)
- Mobile app distribution (Play Store, App Store, enterprise distribution?)

**Impact:** Configuration, environment setup, CI/CD

**Status:** ⏳ Awaiting user clarification

---

## OQ-008: QR Refresh Interval

**Source:** Dynamic QR algorithm

**Question:** The specification states QR codes should refresh "at regular intervals" and be "short-lived" with a TTL. However, the exact refresh interval is not specified.

- The `.env.example` currently has `QR_TTL_SECONDS=30` — is this the intended default?
- Should the refresh interval be configurable via `system_settings`?

**Impact:** QR generation service, teacher dashboard, mobile scanner timing

**Interim resolution (Phase 11, 2026-08-30):** QR TTL / refresh / clock-skew are
read from `config/attendance.php` (`QR_TTL_SECONDS` default **30**,
`QR_REFRESH_SECONDS`, `QR_CLOCK_SKEW_SECONDS`). A move to `system_settings` is
deferred. Recorded in `docs/ACCEPTED_DEVIATIONS.md` AD-008.

**Status:** 🟡 Interim resolution in place — `system_settings` migration still awaiting user clarification

---

## OQ-009: Multiple Courses per Session

**Source:** Attendance session

**Question:** The session model has both `course_id` and `class_id`. The specification implies one session = one course + one class. Can a teacher have multiple active sessions for different courses simultaneously? Or is there a limit of one active session per teacher?

**Impact:** Active session validation logic

**Interim resolution (Phase 10, 2026-08-30):** Step 10 is enforced as **one
ACTIVE session per `(class_id, course_id, academic_term_id)`** (transaction +
`classes` row lock). No per-teacher cap is applied — a teacher may hold
concurrent ACTIVE sessions for different classes. `AttendanceSessionRepository::activeSessionCountForTeacher()`
is available if a cap is later required. Recorded in `docs/ACCEPTED_DEVIATIONS.md` AD-007.

**Status:** 🟡 Interim resolution in place — per-teacher concurrency policy still awaiting user clarification

---

## OQ-010: IP Address for Risk Scoring

**Source:** Risk scoring, audit logging

**Question:** Both risk scoring and audit logging reference IP addresses. In a university setting:

- Many students may share the same IP (campus WiFi)
- IP-based risk signals may produce false positives

**Should IP-based risk scoring be weighted lower in campus environments, or is this acceptable?**

**Impact:** Risk scoring configuration

**Interim resolution (Phase 19, 2026-08-30):** `SUSPICIOUS_IP` fires **only** for
IPs explicitly listed in `risk.ip.suspicious_list` (`system_settings` /
`config/risk.php` / `RISK_SUSPICIOUS_IPS`). The list is **empty by default**, so
shared campus WiFi never produces a false positive. No "IP differs from a
previous IP" heuristic is applied. An institution that wants IP-based risk adds
specific addresses (e.g. known VPN exit nodes) to the list; its weight
(`risk.weight.suspicious_ip`, default 15) keeps a single hit below the MEDIUM
threshold. Recorded in `ACCEPTED_DEVIATIONS.md` AD-014.

**Still open:** whether campus-network topology should feed a smarter IP model
(per-subnet trust, geo-velocity) in a later phase.

**Status:** 🟡 Interim resolution in place — deny-list only, inert by default
