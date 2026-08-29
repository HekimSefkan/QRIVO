# QRIVO — Database Constraints

> All constraints derived from `ORIGINAL_SPECIFICATION.md` requirements.
> These are database-level enforcements — not application-layer validations.

---

## 1. Critical Integrity Constraints

### C-001 — Duplicate Attendance Prevention

**Table:** `attendance_records`
**Constraint:** `UNIQUE(attendance_session_id, student_id)`
**Source:** `ORIGINAL_SPECIFICATION.md` — explicitly required
**Effect:** A student can appear at most once in any given attendance session. Any attempt to insert a second record for the same (session, student) pair will fail at the database level, regardless of what the application layer does.
**Why database-level:** The application layer uses transactions and checks, but the database constraint is the absolute last line of defence against concurrency races, bugs, or bypasses.

---

### C-002 — Challenge Nonce Uniqueness

**Table:** `qr_challenges`
**Constraint:** `UNIQUE(nonce)`
**Source:** Attendance algorithm — nonce must be globally unique per challenge
**Effect:** No two challenges can share a nonce. Guarantees that the single-use replay protection mechanism is sound.

---

### C-003 — QR Challenge UUID Uniqueness

**Table:** `qr_challenges`
**Constraint:** `UNIQUE(uuid)` — this is the `challenge_id` sent to mobile
**Effect:** Every challenge identifier is globally unique; prevents ID collisions that could cause challenge ownership confusion.

---

### C-004 — User Email Uniqueness

**Table:** `users`
**Constraint:** `UNIQUE(email)`
**Effect:** No two accounts can share an email address. Email is the login identifier.

---

### C-005 — User UUID Uniqueness

**Table:** `users`
**Constraint:** `UNIQUE(uuid)`
**Effect:** External references to users via UUID are unambiguous.

---

### C-006 — One Profile Per User

**Tables:** `teachers`, `students`
**Constraint:** `UNIQUE(user_id)` on both tables
**Effect:** A user record maps to at most one teacher profile and at most one student profile. A teacher cannot accidentally be created twice.

---

### C-007 — Unique Employee Number

**Table:** `teachers`
**Constraint:** `UNIQUE(employee_number)`
**Effect:** Institutional employee identifiers are unique.

---

### C-008 — Unique Student Number

**Table:** `students`
**Constraint:** `UNIQUE(student_number)`
**Effect:** Institutional student identifiers are unique.

---

### C-009 — Unique Role Name

**Table:** `roles`
**Constraint:** `UNIQUE(name)`
**Effect:** System roles (SUPER_ADMIN, ADMIN, TEACHER, STUDENT) are unique by identifier.

---

### C-010 — Unique Permission Name

**Table:** `permissions`
**Constraint:** `UNIQUE(name)`
**Effect:** Permission identifiers are unique.

---

### C-011 — Unique Setting Key

**Table:** `system_settings`
**Constraint:** `UNIQUE(key)`
**Effect:** System configuration keys are unique; prevents ambiguous setting lookups.

---

## 2. Uniqueness Constraints (Composite)

### C-012 — No Duplicate Role Assignment

**Table:** `user_roles`
**Constraint:** PK(`user_id`, `role_id`)
**Effect:** A user cannot have the same role assigned twice.

---

### C-013 — No Duplicate Permission Assignment to Role

**Table:** `role_permissions`
**Constraint:** PK(`role_id`, `permission_id`)
**Effect:** A permission cannot be granted to the same role twice.

---

### C-014 — Unique Class-Course per Term

**Table:** `class_courses`
**Constraint:** `UNIQUE(class_id, course_id, academic_term_id)`
**Effect:** The same course cannot be assigned to the same class twice in the same term.

---

### C-015 — Unique Teacher-Course per Term

**Table:** `teacher_courses`
**Constraint:** `UNIQUE(teacher_id, course_id, academic_term_id)`
**Effect:** A teacher cannot be assigned to the same course twice in the same term.

---

### C-016 — Unique Teacher-Class Assignment

**Table:** `teacher_class_assignments`
**Constraint:** `UNIQUE(teacher_id, class_id, course_id, academic_term_id)`
**Effect:** A teacher cannot be assigned to teach the same course to the same class twice in the same term. This is the authorization record for attendance session creation.

---

### C-017 — Unique Student-Class Enrollment

**Table:** `student_class_assignments`
**Constraint:** `UNIQUE(student_id, class_id, academic_term_id)`
**Effect:** A student cannot be enrolled in the same class twice in the same term.

---

### C-018 — Unique Student-Course Enrollment

**Table:** `student_courses`
**Constraint:** `UNIQUE(student_id, course_id, academic_term_id)`
**Effect:** A student cannot be enrolled in the same course twice in the same term.

---

### C-019 — Unique Academic Year Name per School

**Table:** `academic_years`
**Constraint:** `UNIQUE(school_id, name)`
**Effect:** Year names are unique per school.

---

### C-020 — Unique School Code

**Table:** `schools`
**Constraint:** `UNIQUE(code)`
**Effect:** School codes are globally unique.

---

### C-021 — Unique Faculty Code per School

**Table:** `faculties`
**Constraint:** `UNIQUE(school_id, code)`

---

### C-022 — Unique Department Code per Faculty

**Table:** `departments`
**Constraint:** `UNIQUE(faculty_id, code)`

---

### C-023 — Unique Program Code per Department

**Table:** `programs`
**Constraint:** `UNIQUE(department_id, code)`

---

### C-024 — Unique Room Code per School

**Table:** `rooms`
**Constraint:** `UNIQUE(school_id, code)`

---

### C-025 — Unique Course Code per Department

**Table:** `courses`
**Constraint:** `UNIQUE(department_id, code)`

---

### C-026 — Attendance Session UUID Uniqueness

**Table:** `attendance_sessions`
**Constraint:** `UNIQUE(uuid)`
**Effect:** Session UUIDs used in QR payloads are unambiguous.

---

## 3. Foreign Key Constraints

All foreign keys use InnoDB and are enforced at the storage engine level.

| FK | From Table | Column | To Table | Column | On Delete |
|----|-----------|--------|----------|--------|-----------|
| FK-01 | `user_roles` | `user_id` | `users` | `id` | CASCADE |
| FK-02 | `user_roles` | `role_id` | `roles` | `id` | CASCADE |
| FK-03 | `role_permissions` | `role_id` | `roles` | `id` | CASCADE |
| FK-04 | `role_permissions` | `permission_id` | `permissions` | `id` | CASCADE |
| FK-05 | `login_attempts` | `user_id` | `users` | `id` | SET NULL |
| FK-06 | `device_sessions` | `user_id` | `users` | `id` | CASCADE |
| FK-07 | `faculties` | `school_id` | `schools` | `id` | RESTRICT |
| FK-08 | `departments` | `faculty_id` | `faculties` | `id` | RESTRICT |
| FK-09 | `programs` | `department_id` | `departments` | `id` | RESTRICT |
| FK-10 | `academic_years` | `school_id` | `schools` | `id` | RESTRICT |
| FK-11 | `academic_terms` | `academic_year_id` | `academic_years` | `id` | RESTRICT |
| FK-12 | `classes` | `program_id` | `programs` | `id` | RESTRICT |
| FK-13 | `classes` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-14 | `rooms` | `school_id` | `schools` | `id` | RESTRICT |
| FK-15 | `courses` | `department_id` | `departments` | `id` | RESTRICT |
| FK-16 | `teachers` | `user_id` | `users` | `id` | RESTRICT |
| FK-17 | `teachers` | `department_id` | `departments` | `id` | RESTRICT |
| FK-18 | `students` | `user_id` | `users` | `id` | RESTRICT |
| FK-19 | `students` | `program_id` | `programs` | `id` | RESTRICT |
| FK-20 | `class_courses` | `class_id` | `classes` | `id` | RESTRICT |
| FK-21 | `class_courses` | `course_id` | `courses` | `id` | RESTRICT |
| FK-22 | `class_courses` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-23 | `teacher_courses` | `teacher_id` | `teachers` | `id` | RESTRICT |
| FK-24 | `teacher_courses` | `course_id` | `courses` | `id` | RESTRICT |
| FK-25 | `teacher_courses` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-26 | `teacher_class_assignments` | `teacher_id` | `teachers` | `id` | RESTRICT |
| FK-27 | `teacher_class_assignments` | `class_id` | `classes` | `id` | RESTRICT |
| FK-28 | `teacher_class_assignments` | `course_id` | `courses` | `id` | RESTRICT |
| FK-29 | `teacher_class_assignments` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-30 | `student_class_assignments` | `student_id` | `students` | `id` | RESTRICT |
| FK-31 | `student_class_assignments` | `class_id` | `classes` | `id` | RESTRICT |
| FK-32 | `student_class_assignments` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-33 | `student_courses` | `student_id` | `students` | `id` | RESTRICT |
| FK-34 | `student_courses` | `course_id` | `courses` | `id` | RESTRICT |
| FK-35 | `student_courses` | `class_id` | `classes` | `id` | RESTRICT |
| FK-36 | `student_courses` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-37 | `course_schedules` | `teacher_class_assignment_id` | `teacher_class_assignments` | `id` | RESTRICT |
| FK-38 | `course_schedules` | `room_id` | `rooms` | `id` | RESTRICT |
| FK-39 | `attendance_sessions` | `course_id` | `courses` | `id` | RESTRICT |
| FK-40 | `attendance_sessions` | `class_id` | `classes` | `id` | RESTRICT |
| FK-41 | `attendance_sessions` | `teacher_id` | `teachers` | `id` | RESTRICT |
| FK-42 | `attendance_sessions` | `room_id` | `rooms` | `id` | RESTRICT |
| FK-43 | `attendance_sessions` | `academic_term_id` | `academic_terms` | `id` | RESTRICT |
| FK-44 | `attendance_records` | `attendance_session_id` | `attendance_sessions` | `id` | RESTRICT |
| FK-45 | `attendance_records` | `student_id` | `students` | `id` | RESTRICT |
| FK-46 | `qr_challenges` | `attendance_session_id` | `attendance_sessions` | `id` | RESTRICT |
| FK-47 | `qr_challenges` | `student_id` | `students` | `id` | RESTRICT |
| FK-48 | `risk_assessments` | `qr_challenge_id` | `qr_challenges` | `id` | RESTRICT |
| FK-49 | `risk_assessments` | `student_id` | `students` | `id` | RESTRICT |
| FK-50 | `risk_assessments` | `attendance_session_id` | `attendance_sessions` | `id` | RESTRICT |
| FK-51 | `security_events` | `user_id` | `users` | `id` | SET NULL |
| FK-52 | `security_events` | `attendance_session_id` | `attendance_sessions` | `id` | SET NULL |
| FK-53 | `audit_logs` | `actor_user_id` | `users` | `id` | SET NULL |

---

## 4. Nullability Rules

| Table | Column | Nullable | Reason |
|-------|--------|----------|--------|
| `login_attempts` | `user_id` | YES | User may not exist (unknown email attempted) |
| `device_sessions` | `device_fingerprint`, `device_name`, `ip_address`, `user_agent` | YES | Not always available from client |
| `device_sessions` | `revoked_at` | YES | NULL = active session |
| `device_sessions` | `last_active_at` | YES | NULL = never updated since creation |
| `attendance_sessions` | `end_time` | YES | NULL until session is closed/cancelled |
| `attendance_records` | `marked_at` | YES | NULL = still WAITING |
| `qr_challenges` | `used_at` | YES | NULL = not yet consumed; SET atomically on use |
| `risk_assessments` | `signals` | YES | May be empty for low-risk assessments |
| `security_events` | `user_id`, `attendance_session_id`, `ip_address`, `user_agent`, `details` | YES | Not all events have all context |
| `audit_logs` | `actor_user_id`, `target_id`, `old_value`, `new_value`, `reason`, `ip_address` | YES | System actions may lack some fields |

---

## 5. ENUM Constraints

| Table | Column | Allowed Values |
|-------|--------|---------------|
| `attendance_sessions` | `status` | `'ACTIVE'`, `'CLOSED'`, `'CANCELLED'` |
| `attendance_records` | `status` | `'WAITING'`, `'PRESENT'`, `'ABSENT'`, `'LATE'`, `'EXCUSED'`, `'PENDING_REVIEW'` |
| `attendance_records` | `source` | `'SYSTEM'`, `'QR'`, `'MANUAL'` |
| `risk_assessments` | `risk_level` | `'LOW'`, `'MEDIUM'`, `'HIGH'`, `'BLOCKED'` |
| `risk_assessments` | `outcome` | `'PRESENT'`, `'PENDING_REVIEW'`, `'BLOCKED'` |
| `security_events` | `severity` | `'LOW'`, `'MEDIUM'`, `'HIGH'`, `'CRITICAL'` |

> **OQ-001 Resolution:** `PENDING_REVIEW` is a valid `attendance_records.status` value. It is assigned:
> 1. On session close when `system_settings.attendance.close.waiting_default_status = PENDING_REVIEW`
> 2. When the risk scoring outcome is `PENDING_REVIEW`
> It is a resolvable state: teachers can change it to any other status via manual attendance.

---

## 6. Transaction Boundaries

| Operation | Tables Involved | Isolation Required |
|-----------|-----------------|-------------------|
| Session creation | `attendance_sessions` + bulk INSERT into `attendance_records` | Serializable or SELECT FOR UPDATE on active session check |
| Challenge response (attendance) | `qr_challenges` (used_at) + `attendance_records` (status) + `risk_assessments` | Serializable |
| Session close | `attendance_sessions` (status) + `attendance_records` (WAITING → ABSENT/PENDING_REVIEW) | REPEATABLE READ minimum |
| Manual attendance | `attendance_records` (status) + `audit_logs` | READ COMMITTED minimum |
