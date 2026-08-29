# QRIVO — Project Specification

> **Source:** Extracted faithfully from `ORIGINAL_SPECIFICATION.md`.
> This document preserves the original requirements without modification.

---

## 1. Project Overview

**QRIVO** — Secure QR-Based University Attendance System

A university-level attendance management system that uses dynamic, short-lived, cryptographically signed QR codes to prevent proxy attendance and manual record-keeping fraud. The system includes a PHP backend, MySQL database, web dashboard, and cross-platform mobile application.

---

## 2. System Purpose

- Eliminate proxy attendance
- Replace manual attendance processes
- Provide cryptographically secured, time-limited QR-based attendance
- Enforce server-side security for all attendance decisions
- Support both QR-based and manual teacher-driven attendance

---

## 3. Actors

| Actor | Description |
|-------|-------------|
| **Super Admin** | Full system control |
| **Admin** | Institutional administration |
| **Teacher** | Course management, attendance sessions, manual attendance |
| **Student** | QR scanning, attendance submission, personal history |

---

## 4. User Roles

| Role | Identifier |
|------|------------|
| Super Admin | `SUPER_ADMIN` |
| Admin | `ADMIN` |
| Teacher | `TEACHER` |
| Student | `STUDENT` |

---

## 5. Technology Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.3+ |
| Database | MySQL 8+ |
| ORM/DB Access | PDO |
| Package Manager | Composer |
| API Style | REST API (JSON) |
| Architecture | Clean Architecture / MVC with Service Layer, Repository Layer, Middleware |
| Web Client | Web-based dashboard |
| Mobile Client | Flutter (cross-platform: Android + iOS) |
| QR Signing | HMAC-SHA256 |
| Password Hashing | Argon2id (or equivalent secure hash) |

---

## 6. Functional Requirements

### 6.1 Authentication

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/refresh`
- User existence validation
- Active account validation
- Approval status validation
- Secure password verification (Argon2id)
- Login rate limiting
- Login attempt tracking
- Successful login audit logging
- Failed login security event logging
- Refresh token management
- Token reuse detection
- Logout session/token invalidation

### 6.2 Authorization (RBAC + Resource-level)

- Role-based access control: `SUPER_ADMIN`, `ADMIN`, `TEACHER`, `STUDENT`
- Permission-based authorization
- Resource ownership validation
- Relationship-based authorization
- Tables: `roles`, `permissions`, `role_permissions`, `user_roles`
- Teacher: only assigned courses/classes
- Student: only own data
- Admin: permission-controlled
- Frontend visibility is NOT a security mechanism
- All authorization enforced server-side
- IDOR/BOLA protection

### 6.3 Academic Structure Management (Admin)

Entity management in this order:

1. Schools
2. Faculties
3. Departments
4. Programs
5. Academic Years
6. Academic Terms
7. Classes
8. Rooms
9. Courses
10. Teachers
11. Students

Each entity requires: Model, Repository, Service, Validation, Authorization, Controller, REST API, Tests.

### 6.4 Course & Schedule Assignments

- `class_courses`
- `teacher_courses`
- `teacher_class_assignments`
- `student_class_assignments`
- `student_courses`
- `course_schedule`

Relationships:
- Teacher → Course
- Teacher → Class
- Student → Class
- Student → Course
- Class → Course
- Course → Schedule
- Course → Room

The system must determine: which teacher teaches which course, to which class, in which room, at what time, during which academic term.

### 6.5 Attendance Session

**Endpoint:** `POST /api/v1/teacher/attendance/start`

Backend validation sequence:
1. Teacher authentication
2. Teacher authorization
3. Course assignment
4. Class assignment
5. Schedule validation
6. Date validation
7. Time validation
8. Room validation
9. Academic term validation
10. Active attendance session check

**Session fields:**
- `course_id`, `class_id`, `teacher_id`, `room_id`, `academic_term_id`
- `start_time`, `end_time`, `expires_at`
- `status`, `session_secret`

**Session states:** `ACTIVE`, `CLOSED`, `CANCELLED`

On creation: initialize all class students with status `WAITING`.

Use transactions. Prevent race conditions and concurrent duplicate sessions.

### 6.6 Dynamic QR

- No static QR
- Short-lived QR codes
- Minimal payload: `session_id`, `timestamp`, `nonce`, `signature`
- Signature: server-side HMAC-SHA256
- QR refreshes at regular intervals
- Old QR codes become invalid
- QR does NOT directly create attendance — it starts the verification chain

Services: QR generation, nonce generation, signature generation, expiry validation, QR refresh mechanism, QR validation.

### 6.7 Challenge-Response

Flow:
1. Student is authenticated
2. Student scans QR
3. QR session data is parsed
4. Mobile app requests challenge from backend
5. Backend generates: `challenge_id`, `nonce`, `expires_at`
6. Mobile app submits challenge with verification request
7. Backend validates all checks
8. Used challenge marked with `used_at`
9. Same challenge cannot be reused
10. On success: attendance record created

Validations: replay protection, duplicate protection, expired QR, expired challenge, invalid session, unauthorized student, student course membership, rate limit.

Entire attendance operation in a transaction.

### 6.8 Teacher Live Attendance

- Teacher dashboard showing: course, class, room, session status, QR, remaining time
- Student attendance counters: TOTAL, WAITING, PRESENT, ABSENT, LATE, EXCUSED
- Auto-update on student QR verification (WAITING → PRESENT)
- Prefer WebSocket; fallback to AJAX polling (2-5 second intervals)
- Search and filter support

**Desktop layout:**
- Left panel: QR, session info, summary
- Right panel: student list with name, number, status, source (QR/MANUAL), time

**Mobile layout:** QR → Summary → Student List (vertical)

### 6.9 Teacher Manual Attendance

**Endpoint:** `PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`

Supported states: `WAITING`, `PRESENT`, `ABSENT`, `LATE`, `EXCUSED`

Backend validation:
1. Teacher authentication
2. Teacher authorization
3. Attendance ownership
4. Student membership
5. Status validation
6. State transition validation
7. Update
8. Audit log

Audit data: `teacher_id`, `student_id`, `attendance_id`, `old_status`, `new_status`, `reason`, `timestamp`, `ip`

Teacher can override QR-submitted status. Student cannot modify own status.

### 6.10 Attendance Close/Cancel

**Endpoint:** `POST /api/v1/teacher/attendance/{id}/close`

On close:
1. ACTIVE → CLOSED
2. QR validations become invalid
3. New attendance blocked
4. WAITING students → ABSENT or PENDING_REVIEW (per system_settings)
5. Use transaction
6. Audit log

Support `CANCELLED` state. Prevent concurrent close corruption.

### 6.11 Mobile Application

Technology: Flutter (cross-platform)

Initial scope:
1. Project structure
2. API client
3. Authentication
4. Secure token storage
5. Student Dashboard
6. Schedule
7. Attendance history
8. Profile

QR scanner added in subsequent phase.

### 6.12 Mobile QR Flow

1. Student login → QR scanner → Read QR → Send to backend → Receive challenge → Submit verification → Backend validates → Attendance result

Handle: expired QR, invalid QR, invalid session, expired challenge, replay, duplicate, unauthorized student, rate limiting, network errors.

### 6.13 Device Session Management

- Device registration
- Session identification
- Session expiration
- Logout
- Suspicious device detection
- Multiple device rules
- Integration with security and risk scoring

### 6.14 Risk Scoring

Risk signals:
- Expired QR
- Replay attempt
- Invalid challenge
- New device
- Multiple device attendance
- Suspicious IP
- Location mismatch
- Excessive retry
- Duplicate attendance
- Unauthorized course/student relationship

Risk levels: `LOW`, `MEDIUM`, `HIGH`, `BLOCKED`

Risk outcomes:
- `PRESENT`
- `PRESENT` + `SECURITY_EVENT`
- `PENDING_REVIEW`
- `BLOCKED`

Risk values managed via `system_settings` or configuration — not hard-coded.

### 6.15 Security Events & Audit Logging

Security events for: login failures, suspicious auth, QR replay, invalid QR, invalid challenge, duplicate attendance, unauthorized access, suspicious device, risk escalation, blocked attendance.

Audit logging for: administrative changes, attendance state changes.

Logs must NOT expose: passwords, raw tokens, secrets, unnecessary personal data.

### 6.16 Reporting

**Teacher:** course attendance, class attendance, date range, student history
**Admin:** institution-level, department-level, course statistics, attendance statistics
**Student:** only their own attendance history

Authorization enforced on all reports. Pagination and filtering required.

---

## 7. Non-Functional Requirements

- MySQL 8+ with InnoDB, utf8mb4
- Transaction-safe attendance operations
- `UNIQUE(attendance_session_id, student_id)` constraint
- Proper foreign keys, indexes, unique constraints
- Soft delete evaluation
- `created_at` / `updated_at` timestamps where appropriate
- Performance indexes on `security_events` and `audit_logs`
- Pagination and filtering on list endpoints
- Responsive web design (desktop and tablet priority)
- Cross-platform mobile (Android + iOS)

---

## 8. Development Phases (Original)

1. Project Specification
2. Architecture Analysis
3. ER Model
4. Database Design
5. Database Migrations
6. PHP Backend Skeleton
7. Authentication
8. RBAC + Authorization
9. Admin & Academic Structure
10. Course / Teacher / Student Assignments
11. Course Schedule
12. Attendance Session
13. Dynamic QR
14. Teacher Attendance Screen
15. Live Student List
16. Manual Attendance
17. Close / Cancel Attendance
18. Mobile App Base
19. Mobile Authentication
20. QR Scanner
21. Challenge Response
22. Device Session
23. Risk Scoring
24. Security Events
25. Reports
26. Notifications
27. Full Testing
28. Deployment
