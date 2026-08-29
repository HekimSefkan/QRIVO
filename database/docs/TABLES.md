# QRIVO — Database Tables Reference

> All tables are derived from `ORIGINAL_SPECIFICATION.md` requirements.
> Engine: InnoDB. Charset: utf8mb4. Collation: utf8mb4_unicode_ci.

---

## Domain Groups

1. [Identity & Access Control](#group-1-identity--access-control)
2. [Academic Structure](#group-2-academic-structure)
3. [People Profiles](#group-3-people-profiles)
4. [Course Assignments & Scheduling](#group-4-course-assignments--scheduling)
5. [Attendance (Core)](#group-5-attendance-core)
6. [QR & Challenge](#group-6-qr--challenge)
7. [Security & Risk](#group-7-security--risk)
8. [System](#group-8-system)

---

## Group 1: Identity & Access Control

---

### Table: `users`

**Purpose:** Core identity record for every actor in the system (teachers, students, admins).

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | Primary key, auto-increment |
| `uuid` | `CHAR(36)` | NO | — | UUIDv4, unique; used for external references |
| `email` | `VARCHAR(255)` | NO | — | Unique; login identifier |
| `password_hash` | `VARCHAR(255)` | NO | — | Argon2id hash — NEVER plaintext |
| `first_name` | `VARCHAR(100)` | NO | — | — |
| `last_name` | `VARCHAR(100)` | NO | — | — |
| `is_active` | `TINYINT(1)` | NO | `0` | Account active status; validated on login |
| `is_approved` | `TINYINT(1)` | NO | `0` | Approval status; validated on login |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | — |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | On update |
| `deleted_at` | `DATETIME` | YES | `NULL` | Soft delete |

**Keys:** PK(`id`), UNIQUE(`uuid`), UNIQUE(`email`)
**Indexes:** `email`, `uuid`

---

### Table: `roles`

**Purpose:** System roles (SUPER_ADMIN, ADMIN, TEACHER, STUDENT).

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `INT UNSIGNED` | NO | — | Primary key |
| `name` | `VARCHAR(50)` | NO | — | Unique; e.g. `SUPER_ADMIN` |
| `display_name` | `VARCHAR(100)` | NO | — | Human-readable label |
| `created_at` | `DATETIME` | NO | — | — |
| `updated_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`), UNIQUE(`name`)

---

### Table: `permissions`

**Purpose:** Granular permission definitions for RBAC.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `INT UNSIGNED` | NO | — | Primary key |
| `name` | `VARCHAR(100)` | NO | — | Unique; e.g. `attendance.session.start` |
| `display_name` | `VARCHAR(150)` | NO | — | Human-readable label |
| `created_at` | `DATETIME` | NO | — | — |
| `updated_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`), UNIQUE(`name`)

---

### Table: `role_permissions`

**Purpose:** Many-to-many mapping of roles to permissions.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `role_id` | `INT UNSIGNED` | NO | FK → `roles.id` |
| `permission_id` | `INT UNSIGNED` | NO | FK → `permissions.id` |

**Keys:** PK(`role_id`, `permission_id`)
**FK:** `role_id` → `roles(id)` ON DELETE CASCADE; `permission_id` → `permissions(id)` ON DELETE CASCADE

---

### Table: `user_roles`

**Purpose:** Many-to-many assignment of roles to users.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `user_id` | `BIGINT UNSIGNED` | NO | FK → `users.id` |
| `role_id` | `INT UNSIGNED` | NO | FK → `roles.id` |
| `created_at` | `DATETIME` | NO | When role was assigned |

**Keys:** PK(`user_id`, `role_id`)
**FK:** `user_id` → `users(id)` ON DELETE CASCADE; `role_id` → `roles(id)` ON DELETE CASCADE

---

### Table: `login_attempts`

**Purpose:** Track authentication attempts for rate limiting and security event logging.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | Primary key |
| `user_id` | `BIGINT UNSIGNED` | YES | NULL | FK → `users.id`; NULL if user not found |
| `email_attempted` | `VARCHAR(255)` | NO | — | What was submitted |
| `ip_address` | `VARCHAR(45)` | NO | — | IPv4 or IPv6 |
| `user_agent` | `TEXT` | YES | NULL | — |
| `success` | `TINYINT(1)` | NO | `0` | 1 = successful login |
| `created_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`)
**FK:** `user_id` → `users(id)` ON DELETE SET NULL
**Indexes:** `(ip_address, created_at)`, `(user_id, created_at)`, `(email_attempted, created_at)`

---

### Table: `device_sessions`

**Purpose:** Track authenticated device sessions for session management, token storage, and device-based risk signals.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | Primary key |
| `uuid` | `CHAR(36)` | NO | — | Unique session identifier |
| `user_id` | `BIGINT UNSIGNED` | NO | — | FK → `users.id` |
| `device_fingerprint` | `VARCHAR(255)` | YES | NULL | Derived from UA + device identifiers |
| `device_name` | `VARCHAR(255)` | YES | NULL | — |
| `ip_address` | `VARCHAR(45)` | YES | NULL | — |
| `user_agent` | `TEXT` | YES | NULL | — |
| `access_token_hash` | `VARCHAR(255)` | YES | NULL | Hashed; NEVER plaintext token |
| `refresh_token_hash` | `VARCHAR(255)` | YES | NULL | Hashed; NEVER plaintext token |
| `expires_at` | `DATETIME` | NO | — | Session expiry |
| `last_active_at` | `DATETIME` | YES | NULL | — |
| `revoked_at` | `DATETIME` | YES | NULL | Non-null = explicitly revoked (logout) |
| `created_at` | `DATETIME` | NO | — | — |
| `updated_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`), UNIQUE(`uuid`)
**FK:** `user_id` → `users(id)` ON DELETE CASCADE
**Indexes:** `(user_id, expires_at)`, `(access_token_hash)`, `(refresh_token_hash)`, `(expires_at)`

---

## Group 2: Academic Structure

---

### Table: `schools`

**Purpose:** Top-level institutional unit.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `name` | `VARCHAR(255)` | NO | — |
| `code` | `VARCHAR(50)` | NO | UNIQUE |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

---

### Table: `faculties`

**Purpose:** Faculty within a school.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `school_id` | `INT UNSIGNED` | NO | FK → `schools.id` |
| `name` | `VARCHAR(255)` | NO | — |
| `code` | `VARCHAR(50)` | NO | UNIQUE within school |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Unique:** `(school_id, code)`

---

### Table: `departments`

**Purpose:** Department within a faculty.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `faculty_id` | `INT UNSIGNED` | NO | FK → `faculties.id` |
| `name` | `VARCHAR(255)` | NO | — |
| `code` | `VARCHAR(50)` | NO | UNIQUE within faculty |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Unique:** `(faculty_id, code)`

---

### Table: `programs`

**Purpose:** Degree program within a department (e.g. "Computer Engineering BSc").

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `department_id` | `INT UNSIGNED` | NO | FK → `departments.id` |
| `name` | `VARCHAR(255)` | NO | — |
| `code` | `VARCHAR(50)` | NO | UNIQUE within department |
| `duration_years` | `TINYINT UNSIGNED` | NO | — |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Unique:** `(department_id, code)`

---

### Table: `academic_years`

**Purpose:** Academic year (e.g. "2025-2026"), scoped to a school.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `school_id` | `INT UNSIGNED` | NO | FK → `schools.id` |
| `name` | `VARCHAR(50)` | NO | e.g. `2025-2026` |
| `start_date` | `DATE` | NO | — |
| `end_date` | `DATE` | NO | — |
| `is_active` | `TINYINT(1)` | NO | `0` |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |

**Unique:** `(school_id, name)`

---

### Table: `academic_terms`

**Purpose:** Term within an academic year (Fall / Spring / Summer).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `academic_year_id` | `INT UNSIGNED` | NO | FK → `academic_years.id` |
| `name` | `VARCHAR(100)` | NO | e.g. `Fall 2025` |
| `term_number` | `TINYINT UNSIGNED` | NO | 1, 2, 3 |
| `start_date` | `DATE` | NO | — |
| `end_date` | `DATE` | NO | — |
| `is_active` | `TINYINT(1)` | NO | `0` |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |

**Indexes:** `(is_active)`, `(academic_year_id)`

---

### Table: `classes`

**Purpose:** A specific class group of students in a program for a given term.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `program_id` | `INT UNSIGNED` | NO | FK → `programs.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | FK → `academic_terms.id` |
| `name` | `VARCHAR(100)` | NO | e.g. `CE-3A` |
| `grade_level` | `TINYINT UNSIGNED` | NO | Year of study |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

---

### Table: `rooms`

**Purpose:** Physical room or lecture hall.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `school_id` | `INT UNSIGNED` | NO | FK → `schools.id` |
| `name` | `VARCHAR(100)` | NO | e.g. `Lecture Hall A` |
| `code` | `VARCHAR(50)` | NO | e.g. `LH-A` |
| `capacity` | `SMALLINT UNSIGNED` | YES | NULL | Optional |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Unique:** `(school_id, code)`

---

### Table: `courses`

**Purpose:** A course definition (e.g. "Data Structures", 4 credits).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `department_id` | `INT UNSIGNED` | NO | FK → `departments.id` |
| `name` | `VARCHAR(255)` | NO | — |
| `code` | `VARCHAR(50)` | NO | — |
| `credit_hours` | `TINYINT UNSIGNED` | YES | NULL | — |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Unique:** `(department_id, code)`

---

## Group 3: People Profiles

---

### Table: `teachers`

**Purpose:** Teacher profile, linked to a `users` record.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `user_id` | `BIGINT UNSIGNED` | NO | UNIQUE FK → `users.id` |
| `department_id` | `INT UNSIGNED` | NO | FK → `departments.id` |
| `employee_number` | `VARCHAR(50)` | NO | UNIQUE |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Keys:** UNIQUE(`user_id`), UNIQUE(`employee_number`)

---

### Table: `students`

**Purpose:** Student profile, linked to a `users` record.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `BIGINT UNSIGNED` | NO | PK |
| `user_id` | `BIGINT UNSIGNED` | NO | UNIQUE FK → `users.id` |
| `program_id` | `INT UNSIGNED` | NO | FK → `programs.id` |
| `student_number` | `VARCHAR(50)` | NO | UNIQUE |
| `enrollment_year` | `YEAR` | NO | — |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |
| `deleted_at` | `DATETIME` | YES | Soft delete |

**Keys:** UNIQUE(`user_id`), UNIQUE(`student_number`)

---

## Group 4: Course Assignments & Scheduling

---

### Table: `class_courses`

**Purpose:** Which courses are offered in a given class for a given term.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `class_id` | `INT UNSIGNED` | NO | FK → `classes.id` |
| `course_id` | `INT UNSIGNED` | NO | FK → `courses.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | FK → `academic_terms.id` |
| `created_at` | `DATETIME` | NO | — |

**Unique:** `(class_id, course_id, academic_term_id)`

---

### Table: `teacher_courses`

**Purpose:** Which courses a teacher is responsible for in a given term.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `teacher_id` | `INT UNSIGNED` | NO | FK → `teachers.id` |
| `course_id` | `INT UNSIGNED` | NO | FK → `courses.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | FK → `academic_terms.id` |
| `created_at` | `DATETIME` | NO | — |

**Unique:** `(teacher_id, course_id, academic_term_id)`

---

### Table: `teacher_class_assignments`

**Purpose:** Teacher assigned to teach a specific course to a specific class in a term. This is the **authorization basis** for attendance session creation.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `teacher_id` | `INT UNSIGNED` | NO | FK → `teachers.id` |
| `class_id` | `INT UNSIGNED` | NO | FK → `classes.id` |
| `course_id` | `INT UNSIGNED` | NO | FK → `courses.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | FK → `academic_terms.id` |
| `created_at` | `DATETIME` | NO | — |

**Unique:** `(teacher_id, class_id, course_id, academic_term_id)`

---

### Table: `student_class_assignments`

**Purpose:** Student enrolled in a class for a given term. Used to initialize attendance records.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `BIGINT UNSIGNED` | NO | PK |
| `student_id` | `BIGINT UNSIGNED` | NO | FK → `students.id` |
| `class_id` | `INT UNSIGNED` | NO | FK → `classes.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | FK → `academic_terms.id` |
| `enrolled_at` | `DATETIME` | NO | — |

**Unique:** `(student_id, class_id, academic_term_id)`

---

### Table: `student_courses`

**Purpose:** Student enrolled in a specific course (derived from class enrollment, stored for direct lookup during attendance validation).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `BIGINT UNSIGNED` | NO | PK |
| `student_id` | `BIGINT UNSIGNED` | NO | FK → `students.id` |
| `course_id` | `INT UNSIGNED` | NO | FK → `courses.id` |
| `class_id` | `INT UNSIGNED` | NO | FK → `classes.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | FK → `academic_terms.id` |
| `created_at` | `DATETIME` | NO | — |

**Unique:** `(student_id, course_id, academic_term_id)`
**Index:** `(student_id, course_id)` — used in challenge-response validation (step 8 in algorithm)

---

### Table: `course_schedules`

**Purpose:** When and where a class/course combination is taught. Used for schedule and time validation in attendance session creation.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `teacher_class_assignment_id` | `INT UNSIGNED` | NO | FK → `teacher_class_assignments.id` |
| `room_id` | `INT UNSIGNED` | NO | FK → `rooms.id` |
| `day_of_week` | `TINYINT UNSIGNED` | NO | 0=Mon … 6=Sun |
| `start_time` | `TIME` | NO | — |
| `end_time` | `TIME` | NO | — |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |

**Index:** `(teacher_class_assignment_id, day_of_week)` — for schedule validation lookup

---

## Group 5: Attendance (Core)

---

### Table: `attendance_sessions`

**Purpose:** An active QR attendance session. Created by a teacher, scoped to a course + class + term. Contains the `session_secret` used for HMAC-SHA256 QR signing.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | PK |
| `uuid` | `CHAR(36)` | NO | — | UNIQUE; safe external identifier |
| `course_id` | `INT UNSIGNED` | NO | — | FK → `courses.id` |
| `class_id` | `INT UNSIGNED` | NO | — | FK → `classes.id` |
| `teacher_id` | `INT UNSIGNED` | NO | — | FK → `teachers.id` |
| `room_id` | `INT UNSIGNED` | NO | — | FK → `rooms.id` |
| `academic_term_id` | `INT UNSIGNED` | NO | — | FK → `academic_terms.id` |
| `start_time` | `DATETIME` | NO | — | When session was started |
| `end_time` | `DATETIME` | YES | NULL | Set on close/cancel |
| `expires_at` | `DATETIME` | NO | — | Session expiry |
| `status` | `ENUM('ACTIVE','CLOSED','CANCELLED')` | NO | `'ACTIVE'` | Session lifecycle state |
| `session_secret` | `VARCHAR(255)` | NO | — | Server-generated; used for HMAC-SHA256 QR signing; **never exposed in API** |
| `created_at` | `DATETIME` | NO | — | — |
| `updated_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`), UNIQUE(`uuid`)
**Indexes:**
- `(status, teacher_id)` — find active session for teacher
- `(status, class_id)` — duplicate active session check
- `(teacher_id, status)` — teacher dashboard queries
- `(class_id, course_id, status)` — concurrency protection

---

### Table: `attendance_records`

**Purpose:** Individual student attendance status within a session. The `UNIQUE(attendance_session_id, student_id)` constraint is the database-level duplicate attendance prevention mechanism.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | PK |
| `attendance_session_id` | `BIGINT UNSIGNED` | NO | — | FK → `attendance_sessions.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | FK → `students.id` |
| `status` | `ENUM('WAITING','PRESENT','ABSENT','LATE','EXCUSED','PENDING_REVIEW')` | NO | `'WAITING'` | Current attendance state. `PENDING_REVIEW` is assigned by session-close when `system_settings` dictates it, and also by risk scoring when outcome is PENDING_REVIEW |
| `source` | `ENUM('SYSTEM','QR','MANUAL')` | NO | `'SYSTEM'` | How status was set: SYSTEM=initialized, QR=via challenge-response, MANUAL=teacher override |
| `marked_at` | `DATETIME` | YES | NULL | When status changed from WAITING |
| `created_at` | `DATETIME` | NO | — | — |
| `updated_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`), **UNIQUE(`attendance_session_id`, `student_id`)**

> **OQ-001 Resolved:** `PENDING_REVIEW` is included in the `status` ENUM. Source: session-close algorithm (WAITING → ABSENT or PENDING_REVIEW per system_settings) and risk scoring outcome. It is a terminal attendance state resolvable by the teacher to PRESENT/ABSENT/LATE/EXCUSED via manual attendance.
**Indexes:**
- `(attendance_session_id, status)` — live attendance counters
- `(student_id, attendance_session_id)` — student history queries

---

## Group 6: QR & Challenge

---

### Table: `qr_challenges`

**Purpose:** Single-use challenge tokens issued during challenge-response attendance. `used_at` enforces single-use replay protection.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | PK |
| `uuid` | `CHAR(36)` | NO | — | UNIQUE; this is the `challenge_id` sent to mobile |
| `attendance_session_id` | `BIGINT UNSIGNED` | NO | — | FK → `attendance_sessions.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | FK → `students.id` |
| `nonce` | `VARCHAR(255)` | NO | — | UNIQUE; server-generated, globally unique nonce for this challenge |
| `qr_nonce` | `VARCHAR(255)` | NO | — | The nonce extracted from the scanned QR payload |
| `expires_at` | `DATETIME` | NO | — | Challenge expiry |
| `used_at` | `DATETIME` | YES | NULL | NULL = unused; set atomically on successful use |
| `created_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`), UNIQUE(`uuid`), UNIQUE(`nonce`)
**Indexes:**
- `(attendance_session_id, student_id)` — lookup challenge for session+student
- `(expires_at)` — cleanup of expired challenges
- `(qr_nonce)` — QR nonce replay check

---

## Group 7: Security & Risk

---

### Table: `risk_assessments`

**Purpose:** Record the risk evaluation result for each attendance attempt. Linked to the challenge that triggered the attendance flow.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `BIGINT UNSIGNED` | NO | — | PK |
| `qr_challenge_id` | `BIGINT UNSIGNED` | NO | — | FK → `qr_challenges.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | FK → `students.id` |
| `attendance_session_id` | `BIGINT UNSIGNED` | NO | — | FK → `attendance_sessions.id` |
| `risk_level` | `ENUM('LOW','MEDIUM','HIGH','BLOCKED')` | NO | — | Computed risk level |
| `risk_score` | `SMALLINT UNSIGNED` | NO | `0` | Numeric score |
| `signals` | `JSON` | YES | NULL | Which risk signals fired; **must not contain passwords or tokens** |
| `outcome` | `ENUM('PRESENT','PENDING_REVIEW','BLOCKED')` | NO | — | Attendance outcome |
| `created_at` | `DATETIME` | NO | — | — |

**Keys:** PK(`id`)
**Indexes:**
- `(attendance_session_id)` — session-level risk review
- `(student_id, created_at)` — student risk history
- `(risk_level)` — filtering

---

### Table: `security_events`

**Purpose:** Structured log of all security-sensitive events. Append-only. Used for security monitoring and incident investigation.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `BIGINT UNSIGNED` | NO | PK |
| `event_type` | `VARCHAR(100)` | NO | e.g. `LOGIN_FAILURE`, `QR_REPLAY`, `DUPLICATE_ATTENDANCE` |
| `severity` | `ENUM('LOW','MEDIUM','HIGH','CRITICAL')` | NO | — |
| `user_id` | `BIGINT UNSIGNED` | YES | FK → `users.id`; NULL if unauthenticated |
| `attendance_session_id` | `BIGINT UNSIGNED` | YES | FK → `attendance_sessions.id`; nullable |
| `ip_address` | `VARCHAR(45)` | YES | — |
| `user_agent` | `TEXT` | YES | — |
| `details` | `JSON` | YES | Structured context — **must never contain passwords, tokens, or private keys** |
| `created_at` | `DATETIME` | NO | — |

**Keys:** PK(`id`)
**Indexes:**
- `(event_type, created_at)` — query by event type over time
- `(user_id, created_at)` — user-level security timeline
- `(severity, created_at)` — filter by severity
- `(created_at)` — general chronological queries

---

### Table: `audit_logs`

**Purpose:** Audit trail for administrative and state-changing actions. Append-only. Records old/new state, actor, target, and reason.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `BIGINT UNSIGNED` | NO | PK |
| `event_type` | `VARCHAR(100)` | NO | e.g. `ATTENDANCE_STATUS_CHANGED`, `USER_DEACTIVATED` |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | FK → `users.id`; who performed the action |
| `target_entity` | `VARCHAR(100)` | NO | e.g. `attendance_record`, `user` |
| `target_id` | `BIGINT UNSIGNED` | YES | ID of the affected record |
| `old_value` | `JSON` | YES | State before change; **no secrets** |
| `new_value` | `JSON` | YES | State after change; **no secrets** |
| `reason` | `TEXT` | YES | Reason for manual change |
| `ip_address` | `VARCHAR(45)` | YES | — |
| `created_at` | `DATETIME` | NO | — |

**Keys:** PK(`id`)
**Indexes:**
- `(actor_user_id, created_at)` — actor audit trail
- `(target_entity, target_id)` — entity change history
- `(event_type, created_at)` — event type filtering
- `(created_at)` — chronological queries

---

## Group 8: System

---

### Table: `system_settings`

**Purpose:** Configurable system parameters including risk scoring thresholds and attendance behavior (e.g. WAITING → ABSENT or PENDING_REVIEW on session close).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `INT UNSIGNED` | NO | PK |
| `key` | `VARCHAR(100)` | NO | UNIQUE; e.g. `attendance.close.waiting_default_status` |
| `value` | `TEXT` | NO | String-encoded value |
| `type` | `VARCHAR(50)` | NO | `string`, `integer`, `boolean`, `json` |
| `description` | `TEXT` | YES | Human-readable explanation |
| `created_at` | `DATETIME` | NO | — |
| `updated_at` | `DATETIME` | NO | — |

**Keys:** PK(`id`), UNIQUE(`key`)

---

## Table Count Summary

| Group | Tables | Count |
|-------|--------|-------|
| Identity & Access | users, roles, permissions, role_permissions, user_roles, login_attempts, device_sessions | 7 |
| Academic Structure | schools, faculties, departments, programs, academic_years, academic_terms, classes, rooms, courses | 9 |
| People Profiles | teachers, students | 2 |
| Assignments & Schedule | class_courses, teacher_courses, teacher_class_assignments, student_class_assignments, student_courses, course_schedules | 6 |
| Attendance | attendance_sessions, attendance_records | 2 |
| QR & Challenge | qr_challenges | 1 |
| Security & Risk | risk_assessments, security_events, audit_logs | 3 |
| System | system_settings | 1 |
| **TOTAL** | | **31** |
