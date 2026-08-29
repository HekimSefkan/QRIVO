# QRIVO — Architecture Freeze

> **This document is the frozen architecture reference.**
> It defines every major component's responsibility, inputs, outputs, dependencies, and security boundary.
> Nothing in this document may change during implementation without explicit user approval.
> Source: `ORIGINAL_SPECIFICATION.md`, `docs/ARCHITECTURE_RULES.md`, `docs/ATTENDANCE_ALGORITHM.md`

---

## 1. System Architecture Overview

```
                    QRIVO
                       │
          ┌────────────┴────────────┐
          │                         │
      Web Client               Mobile Client
     (Browser)              (Flutter: Android/iOS)
          │                         │
          └────────────┬────────────┘
                       │
                    REST API
                  /api/v1/...
                       │
               PHP 8.3+ Backend
              (Clean Architecture)
                       │
            ┌──────────┴──────────┐
            │                     │
         MySQL 8              Security Layer
        (InnoDB)            (Auth/AuthZ/Risk)
            │                     │
            └──────────┬──────────┘
                       │
             Attendance Engine
                       │
        ┌──────────────┼──────────────┐
        │              │              │
    Dynamic QR    Challenge       Risk
    Generation     Response       Scoring
```

---

## 2. Component Catalogue

---

### 2.1 PHP Backend

| Property | Value |
|----------|-------|
| **Responsibility** | All business logic, security enforcement, data access |
| **Input** | HTTP requests (JSON) from web client and mobile client |
| **Output** | HTTP responses (JSON) |
| **Dependencies** | MySQL 8, Composer packages, environment config |
| **Security boundary** | All authorization and authentication decisions happen here. Clients are never trusted. |

**Internal layers (locked):**
- `Presentation` — Controllers, request parsing, response formatting
- `Application` — Services, Use Cases, DTOs
- `Infrastructure` — Repositories, database adapters, external integrations
- `Domain` — Entities, value objects, domain rules

---

### 2.2 MySQL Database

| Property | Value |
|----------|-------|
| **Responsibility** | Persistent storage, referential integrity, uniqueness enforcement, transaction safety |
| **Input** | SQL queries via PDO from the backend |
| **Output** | Result sets, affected rows |
| **Dependencies** | None (standalone) |
| **Security boundary** | Enforces `UNIQUE(attendance_session_id, student_id)`, foreign keys, constraints. Database-level integrity is the last line of defence against duplicate attendance. |

---

### 2.3 Authentication Module

| Property | Value |
|----------|-------|
| **Responsibility** | Verify identity; issue and manage sessions/tokens |
| **Input** | Credentials (email + password), refresh tokens, logout requests |
| **Output** | Access token, refresh token, session state |
| **Dependencies** | `users`, `device_sessions`, `login_attempts`, `security_events` tables |
| **Security boundary** | Argon2id password verification; rate limiting enforced server-side; token reuse detection; all failed attempts logged as security events |

---

### 2.4 Authorization Module (RBAC + Resource)

| Property | Value |
|----------|-------|
| **Responsibility** | Verify that an authenticated actor may perform a specific action on a specific resource |
| **Input** | Authenticated user, action, target resource |
| **Output** | Allow / Deny |
| **Dependencies** | `roles`, `permissions`, `role_permissions`, `user_roles`; `teachers`, `teacher_class_assignments`, `teacher_courses`; `students`, `student_class_assignments`, `student_courses` |
| **Security boundary** | Role alone is NOT sufficient. Resource ownership and relationship verification are mandatory. Frontend visibility is never a security boundary. |

---

### 2.5 Academic Structure Module

| Property | Value |
|----------|-------|
| **Responsibility** | Manage schools, faculties, departments, programs, academic years, terms, classes, rooms, courses |
| **Input** | Admin CRUD requests |
| **Output** | Entity records |
| **Dependencies** | `schools`, `faculties`, `departments`, `programs`, `academic_years`, `academic_terms`, `classes`, `rooms`, `courses` |
| **Security boundary** | All operations authorized via RBAC + permission checks |

---

### 2.6 Course/Schedule Assignment Module

| Property | Value |
|----------|-------|
| **Responsibility** | Manage which teacher teaches which course to which class in which room at what time |
| **Input** | Admin assignment requests |
| **Output** | Assignment records |
| **Dependencies** | `class_courses`, `teacher_courses`, `teacher_class_assignments`, `student_class_assignments`, `student_courses`, `course_schedules` |
| **Security boundary** | Only admins may create assignments. Assignments are the basis for attendance authorization. |

---

### 2.7 Attendance Session Module

| Property | Value |
|----------|-------|
| **Responsibility** | Create, manage, and close attendance sessions |
| **Input** | Teacher start/close requests |
| **Output** | `attendance_session` record; initialized `attendance_record` rows for all class students |
| **Dependencies** | `attendance_sessions`, `attendance_records`, `teacher_class_assignments`, `course_schedules`, `academic_terms` |
| **Security boundary** | 10-step validation before creation. Transaction-wrapped creation. Duplicate active session prevention. |

---

### 2.8 Dynamic QR Module

| Property | Value |
|----------|-------|
| **Responsibility** | Generate and validate short-lived HMAC-SHA256 signed QR codes |
| **Input** | Active `attendance_session` |
| **Output** | QR payload (`session_id`, `timestamp`, `nonce`, `signature`) |
| **Dependencies** | `attendance_sessions` (reads `session_secret`); nonce store (in-memory or `qr_used_nonces`) |
| **Security boundary** | QR does NOT directly create attendance. Signature verification required. Expiry enforced server-side. Old QRs rejected. |

---

### 2.9 Challenge-Response Module

| Property | Value |
|----------|-------|
| **Responsibility** | Generate single-use challenges; validate challenge responses; create attendance records |
| **Input** | QR payload from student; challenge response |
| **Output** | `qr_challenges` record; `attendance_records` update (inside transaction) |
| **Dependencies** | `qr_challenges`, `attendance_sessions`, `attendance_records`, `students`, `student_courses`, `student_class_assignments`, `risk_assessments` |
| **Security boundary** | 13-point server-side validation checklist (see `ATTENDANCE_ALGORITHM.md` §4). Challenge is single-use (`used_at`). Entire operation in a transaction. |

---

### 2.10 Risk Scoring Module

| Property | Value |
|----------|-------|
| **Responsibility** | Evaluate risk signals and produce risk level + attendance outcome |
| **Input** | Risk signals from attendance pipeline |
| **Output** | Risk level (`LOW`/`MEDIUM`/`HIGH`/`BLOCKED`) + attendance outcome |
| **Dependencies** | `risk_assessments`, `security_events`, `system_settings` |
| **Security boundary** | Risk rules defined in `system_settings`, not hard-coded. Risk scoring is centralized — not in controllers. |

---

### 2.11 Security Events & Audit Module

| Property | Value |
|----------|-------|
| **Responsibility** | Record all security-sensitive events and administrative audit trails |
| **Input** | Events from all modules |
| **Output** | `security_events` and `audit_logs` records |
| **Dependencies** | `security_events`, `audit_logs` |
| **Security boundary** | Logs must NOT contain passwords, raw tokens, private keys, or unnecessary personal data. |

---

### 2.12 Live Attendance Module

| Property | Value |
|----------|-------|
| **Responsibility** | Deliver real-time attendance updates to teacher screen |
| **Input** | Teacher polling/WebSocket subscription |
| **Output** | Current attendance state for active session |
| **Dependencies** | `attendance_records`, `attendance_sessions` |
| **Security boundary** | Teacher sees only students in their active session. Authorization enforced on every update request. |

---

### 2.13 Reporting Module

| Property | Value |
|----------|-------|
| **Responsibility** | Provide attendance reports per actor authorization scope |
| **Input** | Report requests |
| **Output** | Paginated, filtered report data |
| **Dependencies** | `attendance_records`, `attendance_sessions`, `courses`, `classes`, `students` |
| **Security boundary** | Students see only own data. Teachers see only assigned courses/classes. Admins see only authorized scope. |

---

### 2.14 Mobile Client (Flutter)

| Property | Value |
|----------|-------|
| **Responsibility** | Student-facing UI: authentication, QR scanning, attendance submission, history |
| **Input** | User interactions |
| **Output** | REST API calls to backend |
| **Dependencies** | Backend REST API; secure local token storage |
| **Security boundary** | No security decisions on device. Backend is authoritative. Failed attendance must not expose security details. |

---

### 2.15 Web Client

| Property | Value |
|----------|-------|
| **Responsibility** | Teacher and admin-facing dashboard: session management, live attendance, manual override, reports |
| **Input** | User interactions |
| **Output** | REST API calls to backend |
| **Dependencies** | Backend REST API |
| **Security boundary** | Frontend visibility is never a security boundary. All authorization backend-enforced. |

---

## 3. Decisions That Must NOT Change During Implementation

| Decision | Rationale |
|----------|-----------|
| PHP 8.3+ backend | Locked by specification |
| MySQL 8 + InnoDB + utf8mb4 | Locked by specification |
| PDO for database access | Locked by specification |
| HMAC-SHA256 for QR signing | Locked by specification |
| Argon2id for passwords | Locked by specification |
| Flutter for mobile | Locked by specification |
| `UNIQUE(attendance_session_id, student_id)` | Core duplicate prevention |
| QR does NOT create attendance directly | Core algorithm design |
| All security decisions server-side | Core security principle |
| Transaction wrapping attendance creation | Core integrity requirement |
| Challenge `used_at` single-use enforcement | Core replay protection |
| 10-step attendance session validation | Core algorithm |
| 13-step challenge-response validation | Core algorithm |
| Risk scoring via `system_settings` (not hard-coded) | Core security design |

---

## 4. Architecture Freeze Status

| Status | Value |
|--------|-------|
| Freeze date | 2026-08-29 |
| Approved by | User (implicit — derived from ORIGINAL_SPECIFICATION.md) |
| Next step | Database architecture (Phase 1 of implementation) |
