# QRIVO — Database Indexes

> Every index is justified by a concrete query pattern derived from the approved algorithm or specification.

---

## Index Priority Levels

| Priority | Meaning |
|----------|---------|
| **CRITICAL** | Required by the attendance algorithm; missing index would break correctness or security |
| **HIGH** | Required for core system queries (login, dashboard, live attendance) |
| **MEDIUM** | Required for reporting, search, and audit queries |
| **LOW** | Convenience indexes for admin UI |

---

## `users`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | CRITICAL | Internal FK references |
| UNIQUE | `uuid` | HIGH | External API references; safe ID exposure |
| UNIQUE | `email` | CRITICAL | Login lookup |
| IDX | `deleted_at` | MEDIUM | Soft-delete filtering |

---

## `login_attempts`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| IDX | `(ip_address, created_at)` | CRITICAL | Rate limiting: count attempts from IP in window |
| IDX | `(user_id, created_at)` | HIGH | Per-user attempt count for lock/alert |
| IDX | `(email_attempted, created_at)` | HIGH | Rate limiting by email |

---

## `device_sessions`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| UNIQUE | `uuid` | HIGH | External session references |
| IDX | `access_token_hash` | CRITICAL | Token validation on every authenticated request |
| IDX | `refresh_token_hash` | HIGH | Refresh flow |
| IDX | `(user_id, expires_at)` | HIGH | List active sessions per user; session expiry check |
| IDX | `expires_at` | MEDIUM | Cleanup of expired sessions |

---

## `faculties`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(school_id, code)` | HIGH | Code uniqueness within school |

---

## `departments`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(faculty_id, code)` | HIGH | Code uniqueness within faculty |

---

## `programs`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(department_id, code)` | HIGH | Code uniqueness within department |

---

## `academic_years`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(school_id, name)` | HIGH | Year uniqueness per school |
| IDX | `is_active` | HIGH | Finding the current academic year |

---

## `academic_terms`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| IDX | `is_active` | CRITICAL | Attendance session creation requires active term validation (step 9) |
| IDX | `academic_year_id` | MEDIUM | Term listing per year |

---

## `class_courses`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(class_id, course_id, academic_term_id)` | HIGH | Prevent duplicate course assignments |

---

## `teacher_courses`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(teacher_id, course_id, academic_term_id)` | HIGH | Prevent duplicate teacher-course assignments |

---

## `teacher_class_assignments`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(teacher_id, class_id, course_id, academic_term_id)` | CRITICAL | Authorization basis for attendance session creation (algorithm step 3–4) |
| IDX | `(teacher_id, academic_term_id)` | HIGH | Teacher dashboard: find teacher's classes this term |
| IDX | `(class_id, course_id)` | HIGH | Attendance session authorization lookup |

---

## `student_class_assignments`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(student_id, class_id, academic_term_id)` | HIGH | Prevent duplicate enrollment |
| IDX | `(class_id, academic_term_id)` | CRITICAL | Session creation: fetch all students in class to initialize attendance_records |

---

## `student_courses`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `(student_id, course_id, academic_term_id)` | HIGH | Prevent duplicate enrollment |
| IDX | `(student_id, course_id)` | CRITICAL | Algorithm step 8: validate student → course membership during challenge-response |

---

## `course_schedules`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| IDX | `(teacher_class_assignment_id, day_of_week)` | CRITICAL | Algorithm steps 5–8: validate schedule, date, time during session creation |
| IDX | `room_id` | MEDIUM | Room conflict checking |

---

## `attendance_sessions`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| UNIQUE | `uuid` | HIGH | Safe external reference |
| IDX | `(status, teacher_id)` | CRITICAL | Find active session for a teacher; algorithm step 10 |
| IDX | `(status, class_id)` | CRITICAL | Prevent duplicate active session for a class (concurrency protection) |
| IDX | `(teacher_id, status)` | HIGH | Teacher dashboard: list active/recent sessions |
| IDX | `(class_id, course_id, status)` | HIGH | Attendance session authorization check |
| IDX | `status` | HIGH | General status filtering |

---

## `attendance_records`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| UNIQUE | `(attendance_session_id, student_id)` | **CRITICAL** | **Core duplicate attendance prevention** — required by spec |
| IDX | `(attendance_session_id, status)` | CRITICAL | Live attendance counters (TOTAL, WAITING, PRESENT, etc.) |
| IDX | `(student_id, attendance_session_id)` | HIGH | Student attendance history queries |

---

## `qr_challenges`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| UNIQUE | `uuid` | CRITICAL | `challenge_id` lookup on challenge response submission |
| UNIQUE | `nonce` | CRITICAL | Challenge nonce uniqueness — replay protection |
| IDX | `(attendance_session_id, student_id)` | CRITICAL | Look up existing challenge for session+student |
| IDX | `expires_at` | HIGH | Reject expired challenges (algorithm step 5); cleanup |
| IDX | `qr_nonce` | HIGH | QR nonce replay check |
| IDX | `used_at` | HIGH | Find consumed challenges (replay detection) |

---

## `risk_assessments`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| IDX | `attendance_session_id` | HIGH | Session-level risk review |
| IDX | `(student_id, created_at)` | HIGH | Student risk history; pattern detection |
| IDX | `risk_level` | MEDIUM | Filter HIGH/BLOCKED outcomes |

---

## `security_events`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| IDX | `(event_type, created_at)` | HIGH | Query events by type over time (e.g., all QR_REPLAY events today) |
| IDX | `(user_id, created_at)` | HIGH | User-level security timeline |
| IDX | `(severity, created_at)` | MEDIUM | Filter HIGH/CRITICAL events for alerting |
| IDX | `created_at` | MEDIUM | Chronological queries; cleanup |

---

## `audit_logs`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| PK | `id` | — | — |
| IDX | `(actor_user_id, created_at)` | HIGH | Actor audit trail |
| IDX | `(target_entity, target_id)` | HIGH | Show change history for a specific record |
| IDX | `(event_type, created_at)` | MEDIUM | Event type filtering |
| IDX | `created_at` | MEDIUM | Chronological reporting |

---

## `system_settings`

| Index | Columns | Priority | Reason |
|-------|---------|----------|--------|
| UNIQUE | `key` | CRITICAL | O(1) setting lookup by key |
