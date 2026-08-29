# QRIVO — Attendance Algorithm

> **This algorithm is authoritative and must NOT be simplified, redesigned, or bypassed.**
> Source: `ORIGINAL_SPECIFICATION.md`

---

## 1. Overview

The QRIVO attendance algorithm treats attendance as a **security-critical transaction**, not a simple form submission. A student's attendance is recorded only after passing through a multi-step server-side verification pipeline.

**Core principle:** QR does NOT directly record attendance. QR starts the verification chain.

---

## 2. Attendance Session Creation

**Actor:** Teacher
**Endpoint:** `POST /api/v1/teacher/attendance/start`

### Validation Sequence (mandatory, in order):

1. Teacher authentication
2. Teacher authorization
3. Course assignment verification
4. Class assignment verification
5. Schedule validation
6. Date validation
7. Time validation
8. Room validation
9. Academic term validation
10. Active attendance session check (prevent duplicates)

### On Success:

Create `attendance_session` with:

| Field | Description |
|-------|-------------|
| `course_id` | The course being taught |
| `class_id` | The class attending |
| `teacher_id` | The teacher conducting |
| `room_id` | The room assigned |
| `academic_term_id` | Current academic term |
| `start_time` | Session start |
| `end_time` | Session end |
| `expires_at` | Session expiration |
| `status` | `ACTIVE` |
| `session_secret` | Server-generated secret for QR signing |

### Student Initialization:

All students in the class are added to the attendance list with initial status: **`WAITING`**

**Transaction required.** Race condition and concurrent request protection required.

---

## 3. Dynamic QR Generation

**No static QR codes.**

### QR Payload (minimal):

```
session_id
timestamp
nonce
signature
```

### Rules:

- Signature generated **server-side** using **HMAC-SHA256**
- QR refreshes at regular intervals
- Old QR codes become **invalid** upon refresh
- QR payload must NOT contain unnecessary sensitive data
- QR does NOT directly create attendance records

### Required Services:

- QR generation service
- Nonce generation service
- Signature generation service
- Expiry validation service
- QR refresh mechanism
- QR validation service

---

## 4. Challenge-Response Verification

This is the core of the attendance verification pipeline.

### Flow:

```
Student (authenticated)
    │
    ▼
Scans QR code
    │
    ▼
QR data parsed (session_id, timestamp, nonce, signature)
    │
    ▼
Mobile app requests challenge from backend
    │
    ▼
Backend generates challenge:
    - challenge_id
    - nonce
    - expires_at
    │
    ▼
Mobile app submits challenge response + verification request
    │
    ▼
Backend validates (see validation checklist below)
    │
    ▼
Challenge marked: used_at = NOW()
    │
    ▼
Same challenge CANNOT be reused (replay protection)
    │
    ▼
On success: attendance record created within transaction
```

### Server Validation Checklist:

1. Student authentication
2. Active attendance session
3. QR validity (not expired, not tampered)
4. QR signature verification (HMAC-SHA256)
5. Challenge validity (not expired)
6. Challenge ownership (belongs to this student/session)
7. Challenge single-use (not already used)
8. Student → course membership
9. Student → class membership
10. Duplicate attendance check
11. Rate limiting
12. Device/session rules
13. Risk scoring evaluation

### Transaction Boundary:

The entire attendance creation must occur inside a **database transaction**.

### Error Handling:

Failed attempts must NOT expose technical security details to the mobile client.

---

## 5. Attendance States

| State | Description |
|-------|-------------|
| `WAITING` | Initial state when session is created |
| `PRESENT` | Student successfully attended (via QR or manual) |
| `ABSENT` | Student did not attend |
| `LATE` | Student attended late |
| `EXCUSED` | Student has an approved excuse |

---

## 6. Teacher Manual Attendance

**Endpoint:** `PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`

### Validation Sequence:

1. Teacher authentication
2. Teacher authorization
3. Attendance ownership verification
4. Student membership verification
5. Status validation
6. State transition validation
7. Update
8. Audit log

### Supported Transitions:

Teacher can set any student to: `WAITING`, `PRESENT`, `ABSENT`, `LATE`, `EXCUSED`

Teacher can **override** QR-submitted status.

### Audit Data (mandatory):

| Field | Description |
|-------|-------------|
| `teacher_id` | Who made the change |
| `student_id` | Whose record was changed |
| `attendance_id` | Which attendance session |
| `old_status` | Previous status |
| `new_status` | New status |
| `reason` | Reason for change |
| `timestamp` | When the change occurred |
| `ip` | Request IP address |

### Critical Rule:

**Student must NEVER be able to modify their own attendance status.**

---

## 7. Session Close / Cancel

**Endpoint:** `POST /api/v1/teacher/attendance/{id}/close`

### Close Flow:

1. Session status: `ACTIVE` → `CLOSED`
2. QR validations become invalid
3. New attendance submissions blocked
4. Remaining `WAITING` students → `ABSENT` or `PENDING_REVIEW` (per `system_settings`)
5. Transaction required
6. Audit log created

### Cancel:

Session status: `ACTIVE` → `CANCELLED`

Audit log created for cancellation.

### Concurrency:

Concurrent close requests must NOT corrupt session state.

---

## 8. Live Attendance Updates

Teacher screen must show real-time attendance changes.

### Update Mechanism:

1. **Preferred:** WebSocket (if stable and reliable)
2. **Fallback:** AJAX polling (2-5 second intervals)

### Live Counters:

- TOTAL
- WAITING
- PRESENT (VAR)
- ABSENT (YOK)
- LATE (GEÇ)
- EXCUSED (MAZERETLİ)

### Trigger:

When a student completes QR verification: `WAITING` → `PRESENT` — teacher screen updates automatically.

---

## 9. Risk Evaluation Integration

Attendance verification includes risk scoring. Risk signals:

- Expired QR
- Replay attempt
- Invalid challenge
- Excessive retries
- Duplicate attendance
- New device
- Multiple device activity
- Suspicious IP
- Location mismatch
- Unauthorized relationship

Risk levels: `LOW`, `MEDIUM`, `HIGH`, `BLOCKED`

Outcomes:
- `PRESENT` (low risk)
- `PRESENT` + `SECURITY_EVENT` (medium risk)
- `PENDING_REVIEW` (high risk)
- `BLOCKED` (critical risk)

---

## 10. Replay Protection Summary

| Mechanism | Protection |
|-----------|------------|
| QR nonce | Each QR payload is unique |
| QR expiration | Short-lived, old QR codes rejected |
| HMAC-SHA256 | Payload tampering detected |
| Challenge nonce | Each challenge is unique |
| Challenge `used_at` | Single-use enforcement |
| `UNIQUE(session_id, student_id)` | Database-level duplicate prevention |
| Rate limiting | Excessive request throttling |
