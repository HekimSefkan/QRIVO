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

**Status:** ⏳ Awaiting user clarification

---

## OQ-004: Student Account Creation

**Source:** Academic structure management

**Question:** The specification defines admin-managed entities including Students. However, it does not specify:

- How student accounts are created (admin manual entry, bulk import, self-registration?)
- How student passwords are initially set (admin-set, email invite, default?)
- Whether students can self-register or if accounts are always admin-provisioned

**Impact:** Authentication module, admin module, onboarding flow

**Status:** ⏳ Awaiting user clarification

---

## OQ-005: Super Admin vs Admin Scope

**Source:** RBAC

**Question:** The specification defines both `SUPER_ADMIN` and `ADMIN` roles. The distinction is:

- SUPER_ADMIN: "Full system control"
- ADMIN: "Institution-level, permission-controlled"

**Is SUPER_ADMIN intended to bypass the permission system entirely?** Or does SUPER_ADMIN simply have all permissions assigned?

**Impact:** Authorization middleware, permission checks

**Status:** ⏳ Awaiting user clarification

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

**Status:** ⏳ Awaiting user clarification

---

## OQ-009: Multiple Courses per Session

**Source:** Attendance session

**Question:** The session model has both `course_id` and `class_id`. The specification implies one session = one course + one class. Can a teacher have multiple active sessions for different courses simultaneously? Or is there a limit of one active session per teacher?

**Impact:** Active session validation logic

**Status:** ⏳ Awaiting user clarification

---

## OQ-010: IP Address for Risk Scoring

**Source:** Risk scoring, audit logging

**Question:** Both risk scoring and audit logging reference IP addresses. In a university setting:

- Many students may share the same IP (campus WiFi)
- IP-based risk signals may produce false positives

**Should IP-based risk scoring be weighted lower in campus environments, or is this acceptable?**

**Impact:** Risk scoring configuration

**Status:** ⏳ Awaiting user clarification
