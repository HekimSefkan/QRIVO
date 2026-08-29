# QRIVO — Architecture Rules

> **These decisions are mandatory and must NOT be changed without explicit user approval.**
> Source: `ORIGINAL_SPECIFICATION.md`

---

## 1. Immutable Architecture Decisions

### 1.1 Technology Stack

| Decision | Value | Status |
|----------|-------|--------|
| Backend Language | PHP 8.3+ | **LOCKED** |
| Database | MySQL 8+ (InnoDB, utf8mb4) | **LOCKED** |
| Database Access | PDO | **LOCKED** |
| Package Manager | Composer | **LOCKED** |
| API Style | REST API (JSON) | **LOCKED** |
| QR Signing | HMAC-SHA256 | **LOCKED** |
| Password Hashing | Argon2id | **LOCKED** |
| Mobile Framework | Flutter (cross-platform) | **LOCKED** |
| Web Client | Web-based dashboard | **LOCKED** |

### 1.2 Backend Architecture

| Decision | Status |
|----------|--------|
| Clean Architecture / MVC pattern | **LOCKED** |
| Service Layer | **LOCKED** |
| Repository Layer | **LOCKED** |
| Middleware Layer | **LOCKED** |
| Controllers | **LOCKED** |
| Validators | **LOCKED** |
| DTOs | **LOCKED** |
| Policies / Authorization | **LOCKED** |
| Exceptions | **LOCKED** |
| Config | **LOCKED** |

**Backend directory structure:**

```
backend/
    public/
    src/
        Domain/
        Application/
        Infrastructure/
        Presentation/
    config/
    routes/
    storage/
    tests/
```

Or an equivalent Clean Architecture layout consistent with the specification.

### 1.3 Project Directory Structure

```
QRIVO/
├── docs/
├── backend/
├── mobile/
├── database/
├── tests/
├── scripts/
├── .gitignore
├── .env.example
├── AGENTS.md
├── README.md
└── CHANGELOG.md
```

---

## 2. Attendance Architecture (LOCKED)

- QR does NOT directly create attendance
- QR starts the verification chain
- Attendance requires: QR → Challenge → Server Validation → Transaction
- All attendance operations are transactional
- `UNIQUE(attendance_session_id, student_id)` enforced at database level
- Student cannot modify own attendance status
- Teacher can override QR-submitted status

---

## 3. Security Architecture (LOCKED)

- All security decisions are server-side
- Frontend/mobile is never a security boundary
- RBAC + resource-level + relationship-based authorization
- No client-side authorization trust
- HMAC-SHA256 for QR payload signing
- Nonce-based replay protection
- Challenge-response verification before attendance
- Risk scoring integration with attendance
- All security events logged

---

## 4. API Architecture (LOCKED)

- REST API with JSON payloads
- Versioned endpoints: `/api/v1/...`
- Defined endpoints:
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/logout`
  - `POST /api/v1/auth/refresh`
  - `POST /api/v1/teacher/attendance/start`
  - `POST /api/v1/teacher/attendance/{id}/close`
  - `PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId}`

---

## 5. Database Architecture (LOCKED)

- MySQL 8+ with InnoDB engine
- utf8mb4 character set
- Foreign keys enforced
- Proper indexes
- Unique constraints where specified
- Transaction support for attendance operations
- `created_at` / `updated_at` timestamps
- Performance indexes on `security_events` and `audit_logs`

---

## 6. Authorization Architecture (LOCKED)

- Four roles: `SUPER_ADMIN`, `ADMIN`, `TEACHER`, `STUDENT`
- Role-based authorization alone is NOT sufficient
- Resource ownership must be validated
- Relationship-based authorization required
- Teacher → only assigned courses/classes
- Student → only own data
- Admin → permission-controlled
- IDOR/BOLA protection mandatory

---

## 7. Mobile Architecture (LOCKED)

- Flutter for cross-platform (Android + iOS)
- Same backend REST API for all clients
- No security decisions on mobile only
- Backend remains authoritative

---

## 8. Change Protocol

If any locked decision must change:

1. **Document the reason** clearly
2. **Update** `docs/OPEN_QUESTIONS.md`
3. **Stop** implementation
4. **Request explicit user approval**
5. Only proceed after approval is granted

**Unapproved changes to locked decisions are prohibited.**
