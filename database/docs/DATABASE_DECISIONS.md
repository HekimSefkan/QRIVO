# QRIVO — Database Design Decisions

> This document records design decisions made during database architecture design.
> Every decision is tied to a specific specification requirement.
> No business rules are invented here.

---

## DD-001: Separate `teachers` and `students` profile tables

**Decision:** Use dedicated `teachers` and `students` tables that FK-reference `users`, rather than embedding teacher/student data in `users`.

**Specification basis:** The spec defines distinct actors (Teacher, Student) with different attributes (employee_number, student_number, program, department). The `users` table is an identity record; the profile tables hold role-specific data.

**Effect:** A `users` record can be looked up for authentication without knowing whether it's a teacher or student. Profile-specific data is always in the correct table.

---

## DD-002: `session_secret` stored in `attendance_sessions`

**Decision:** A randomly generated secret is stored per attendance session and used as the HMAC-SHA256 key for QR payload signing.

**Specification basis:** `ORIGINAL_SPECIFICATION.md` and `ATTENDANCE_ALGORITHM.md` §3 require HMAC-SHA256 signed QR codes. The secret must be per-session to limit exposure if any single secret is compromised.

**Effect:** QR signatures can only be verified by the backend (holds the secret). Rotating to a new session invalidates all QR codes from the old session automatically.

**Security note:** `session_secret` must NEVER be included in any API response.

---

## DD-003: `qr_challenges.used_at` as single-use enforcement

**Decision:** `used_at` is a nullable DATETIME column. NULL = challenge not yet used. It is set atomically within the attendance transaction.

**Specification basis:** `ATTENDANCE_ALGORITHM.md` §4 step 7: "Challenge single-use (not already used)". `used_at` tracking is explicitly required.

**Effect:** The check `WHERE uuid = ? AND used_at IS NULL` combined with an immediate UPDATE within the transaction provides atomic single-use enforcement.

---

## DD-004: `qr_challenges.qr_nonce` stored alongside challenge nonce

**Decision:** The nonce extracted from the scanned QR payload is stored in `qr_challenges.qr_nonce` when a challenge is issued.

**Specification basis:** Replay protection requires the system to track which QR nonces have been presented. Storing the QR nonce at challenge time enables QR nonce replay detection.

**Effect:** The system can detect if a student attempts to reuse an old QR nonce in a new challenge request.

---

## DD-005: `student_courses` table as a direct lookup table

**Decision:** `student_courses` is maintained as a denormalized lookup table, even though student-course membership can be derived from `student_class_assignments + class_courses`.

**Specification basis:** `ATTENDANCE_ALGORITHM.md` §4 step 8 requires "Student → course membership" validation during challenge-response. This happens in a latency-sensitive code path.

**Effect:** A single index lookup on `(student_id, course_id)` instead of a multi-join query. The application must keep `student_courses` in sync with `student_class_assignments` when enrollment changes.

---

## DD-006: `attendance_records.source` column

**Decision:** Store how each attendance record's status was set: `SYSTEM` (initialization), `QR` (via challenge-response), `MANUAL` (teacher override).

**Specification basis:** `PROJECT_SPECIFICATION.md` §6.8 states the teacher live view shows "QR / MANUAL source" per student. `ATTENDANCE_ALGORITHM.md` §6 distinguishes teacher override from QR-submitted status.

**Effect:** Auditability: the UI and reports can show whether a student attended via QR scan or teacher manual entry.

---

## DD-007: `risk_assessments` as a separate table

**Decision:** Risk evaluation results are stored in a dedicated `risk_assessments` table, not embedded in `attendance_records`.

**Specification basis:** `PROJECT_SPECIFICATION.md` §6.14 — risk scoring produces outcomes (`PRESENT`, `PENDING_REVIEW`, `BLOCKED`) and records signals. These are distinct from the final attendance status and need their own lifecycle.

**Effect:** Risk data is preserved even if the attendance outcome is BLOCKED (no `attendance_records` row is created). Enables risk history analysis per student.

---

## DD-008: `system_settings` table for configurable parameters

**Decision:** Risk scoring thresholds and session close behavior (WAITING → ABSENT or PENDING_REVIEW) are stored in `system_settings`.

**Specification basis:** `ORIGINAL_SPECIFICATION.md` explicitly states: *"Risk values must not be hard-coded — manage via system_settings"*. Close behavior: *"per system_settings"*.

**Effect:** Administrators can adjust thresholds without code changes. No magic constants in source code.

---

## DD-009: Soft delete on structural entities

**Decision:** Structural entities (users, teachers, students, schools, faculties, departments, programs, classes, rooms, courses) use soft delete via `deleted_at` nullable column.

**Specification basis:** Attendance records reference historical entities. Hard-deleting a course or student would break historical attendance data integrity. The spec requires historical data preservation for reporting.

**Effect:** Deleted entities are filtered from active queries (`WHERE deleted_at IS NULL`) but remain in the database for historical integrity.

---

## DD-010: ON DELETE RESTRICT for most attendance-related FKs

**Decision:** Foreign keys on attendance-critical tables use `ON DELETE RESTRICT`, not CASCADE.

**Specification basis:** Attendance records must never be silently deleted. The specification requires audit trails and reporting on historical data.

**Effect:** Attempting to delete a course, class, or teacher that has attendance history will fail with a FK error, forcing the administrator to explicitly handle the data before deletion.

---

## DD-011: ON DELETE SET NULL for security event actor references

**Decision:** `security_events.user_id` and `audit_logs.actor_user_id` use `ON DELETE SET NULL`.

**Specification basis:** Security events and audit logs must be preserved even if the actor's account is deleted. A deleted user account must not erase the evidence of their actions.

**Effect:** Logs are preserved; actor identity becomes anonymous (NULL) if the user is hard-deleted.

---

## DD-012: `device_sessions` stores hashed tokens only

**Decision:** `access_token_hash` and `refresh_token_hash` store hashed values only, never the plaintext token.

**Specification basis:** `SECURITY_RULES.md` — tokens must never be stored in plaintext. `AGENTS.md` rule 4.

**Effect:** Database compromise does not expose active tokens.

---

## DD-013: Migration order must respect FK dependency chain

**Decision:** Migrations must be created in this strict dependency order:
1. `system_settings` (no deps)
2. `schools` → `faculties` → `departments` → `programs`
3. `roles` → `permissions` → `role_permissions`
4. `users` → `user_roles` → `login_attempts` → `device_sessions`
5. `teachers` → `students`
6. `academic_years` → `academic_terms`
7. `courses` → `rooms` → `classes`
8. `class_courses` → `teacher_courses` → `teacher_class_assignments`
9. `student_class_assignments` → `student_courses`
10. `course_schedules`
11. `attendance_sessions` → `attendance_records`
12. `qr_challenges` → `risk_assessments`
13. `security_events` → `audit_logs`

**Effect:** Migrations can be run in order without FK errors.

---

## Open Design Questions Affecting This Schema

See `docs/OPEN_QUESTIONS.md`:

| ID | Question | Tables Affected |
|----|----------|----------------|
| OQ-001 | Is `PENDING_REVIEW` a valid `attendance_records.status`? | `attendance_records` ENUM |
| OQ-003 | Is location data collected? | Potential new column or table |
| OQ-009 | Is there a per-teacher limit on active sessions? | Index strategy on `attendance_sessions` |
