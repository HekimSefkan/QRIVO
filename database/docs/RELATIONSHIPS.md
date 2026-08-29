# QRIVO — Database Relationships

> All relationships derived from `ORIGINAL_SPECIFICATION.md`.

---

## Notation

| Symbol | Meaning |
|--------|---------|
| `1` | Exactly one |
| `N` | Many |
| `0..1` | Zero or one (optional) |
| `0..N` | Zero or many (optional) |

---

## 1. Identity & Access Relationships

### users → roles (via user_roles)
- **Cardinality:** M:N
- **Description:** A user can have multiple roles. A role can be assigned to many users.
- **Join table:** `user_roles(user_id, role_id)`
- **Constraint:** UNIQUE(user_id, role_id) — no duplicate role assignment

### roles → permissions (via role_permissions)
- **Cardinality:** M:N
- **Description:** A role can have many permissions. A permission can belong to many roles.
- **Join table:** `role_permissions(role_id, permission_id)`
- **Constraint:** UNIQUE(role_id, permission_id)

### users → login_attempts
- **Cardinality:** 1:N (optional — user_id may be NULL if login target not found)
- **Description:** Tracks every authentication attempt per user for rate limiting and security logging.

### users → device_sessions
- **Cardinality:** 1:N
- **Description:** A user can have multiple active device sessions (e.g. phone + laptop).
- **Security note:** Multiple active sessions per user is subject to device session rules.

---

## 2. Academic Structure Relationships

### schools → faculties
- **Cardinality:** 1:N
- **Description:** A school contains many faculties.

### faculties → departments
- **Cardinality:** 1:N
- **Description:** A faculty contains many departments.

### departments → programs
- **Cardinality:** 1:N
- **Description:** A department offers many degree programs.

### departments → courses
- **Cardinality:** 1:N
- **Description:** Courses are owned by a department.

### departments → teachers
- **Cardinality:** 1:N
- **Description:** A teacher is affiliated with one department.

### schools → academic_years
- **Cardinality:** 1:N
- **Description:** Academic years are scoped to a school.

### academic_years → academic_terms
- **Cardinality:** 1:N
- **Description:** An academic year contains multiple terms (Fall, Spring, Summer).

### programs → classes
- **Cardinality:** 1:N
- **Description:** A program has many class groups, one per term.

### academic_terms → classes
- **Cardinality:** 1:N
- **Description:** Classes are instantiated per academic term.

### schools → rooms
- **Cardinality:** 1:N
- **Description:** Rooms belong to a school.

---

## 3. People Profile Relationships

### users → teachers
- **Cardinality:** 1:0..1 (a user may or may not be a teacher)
- **Description:** One-to-one. A teacher has exactly one user account. UNIQUE(user_id) enforced.

### users → students
- **Cardinality:** 1:0..1 (a user may or may not be a student)
- **Description:** One-to-one. A student has exactly one user account. UNIQUE(user_id) enforced.

### programs → students
- **Cardinality:** 1:N
- **Description:** A student is enrolled in one program.

---

## 4. Assignment & Schedule Relationships

### classes × courses × academic_terms → class_courses
- **Cardinality:** M:N (with term scope)
- **Description:** A class can have many courses. A course can be taught to many classes. Scoped per term.
- **Unique:** `(class_id, course_id, academic_term_id)`

### teachers × courses × academic_terms → teacher_courses
- **Cardinality:** M:N (with term scope)
- **Description:** A teacher can teach many courses. A course can be taught by many teachers. Scoped per term.
- **Unique:** `(teacher_id, course_id, academic_term_id)`

### teachers × classes × courses × academic_terms → teacher_class_assignments
- **Cardinality:** M:N (with full scope)
- **Description:** The specific assignment of a teacher to teach a particular course to a particular class in a term. **This is the authorization basis for attendance session creation.**
- **Unique:** `(teacher_id, class_id, course_id, academic_term_id)`

### students × classes × academic_terms → student_class_assignments
- **Cardinality:** M:N (with term scope)
- **Description:** A student is enrolled in a class for a term. Used to initialize attendance records (all enrolled students start as WAITING).
- **Unique:** `(student_id, class_id, academic_term_id)`

### students × courses × academic_terms → student_courses
- **Cardinality:** M:N (with term scope)
- **Description:** Derived from class enrollment. Stored for fast lookup during challenge-response validation (step 8: student → course membership).
- **Unique:** `(student_id, course_id, academic_term_id)`

### teacher_class_assignments → course_schedules
- **Cardinality:** 1:N
- **Description:** A teacher-class assignment can have one or more scheduled time slots (e.g. Monday 09:00 + Thursday 10:00).

### rooms → course_schedules
- **Cardinality:** 1:N
- **Description:** A room hosts many scheduled sessions.

---

## 5. Attendance Core Relationships

### teachers → attendance_sessions
- **Cardinality:** 1:N
- **Description:** A teacher can start many sessions over time. The 10-step validation in `ATTENDANCE_ALGORITHM.md` ensures authorization.

### courses → attendance_sessions
- **Cardinality:** 1:N

### classes → attendance_sessions
- **Cardinality:** 1:N

### rooms → attendance_sessions
- **Cardinality:** 1:N

### academic_terms → attendance_sessions
- **Cardinality:** 1:N

### attendance_sessions → attendance_records
- **Cardinality:** 1:N
- **Description:** One session has exactly one attendance record per enrolled student.
- **Critical constraint:** UNIQUE(attendance_session_id, student_id) — duplicate prevention.
- **Initialization:** All records created as WAITING when the session is opened.

### students → attendance_records
- **Cardinality:** 1:N
- **Description:** A student has one record per session they are enrolled in.

---

## 6. QR & Challenge Relationships

### attendance_sessions → qr_challenges
- **Cardinality:** 1:N
- **Description:** A session can generate many challenges (one per student scan attempt).

### students → qr_challenges
- **Cardinality:** 1:N
- **Description:** A student can attempt multiple challenge requests (failed attempts, retries).

---

## 7. Security & Risk Relationships

### qr_challenges → risk_assessments
- **Cardinality:** 1:1
- **Description:** Each challenge attempt produces exactly one risk assessment.

### students → risk_assessments
- **Cardinality:** 1:N

### attendance_sessions → risk_assessments
- **Cardinality:** 1:N

### users → security_events
- **Cardinality:** 1:N (nullable — events may be unauthenticated)

### attendance_sessions → security_events
- **Cardinality:** 1:N (nullable)

### users → audit_logs
- **Cardinality:** 1:N (nullable — system actions may have no actor)

---

## 8. Cross-Domain Lookup Path (Attendance Algorithm)

The attendance algorithm requires the following lookup chain to be supported by the database:

```
Teacher (users.id)
    → teachers.user_id
    → teacher_class_assignments.teacher_id
    → [course_id, class_id, academic_term_id]

        → attendance_sessions [match: course_id, class_id, teacher_id, academic_term_id]
            → session_secret [for HMAC QR validation]

        → course_schedules [match: teacher_class_assignment_id, day_of_week, room_id]
            [validates: schedule, date, time, room]

Student (users.id)
    → students.user_id
    → student_courses [match: student_id, course_id]      → validates course membership (step 8)
    → student_class_assignments [match: student_id, class_id] → validates class membership (step 9)
    → attendance_records [match: attendance_session_id, student_id] → validates no duplicate (step 10)

Challenge
    → qr_challenges.uuid [challenge_id]
    → qr_challenges.expires_at [step 5: not expired]
    → qr_challenges.student_id [step 6: belongs to this student]
    → qr_challenges.used_at [step 7: IS NULL = single-use OK]
```
