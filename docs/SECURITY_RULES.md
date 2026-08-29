# QRIVO — Security Rules

> **These security requirements are mandatory and must NOT be weakened or bypassed.**
> Source: `ORIGINAL_SPECIFICATION.md`

---

## 1. Fundamental Principle

**All security decisions must be enforced server-side.**

The frontend and mobile client are presentation layers only. They are **never** a security boundary.

---

## 2. Server-Side Enforcement

### Never Trust:

- Client-side authorization decisions
- Client-provided attendance status
- Client-provided user role
- Client-provided permissions
- Client-provided session ownership
- Client-provided risk scores

### Always Validate Server-Side:

- Authentication state
- Authorization (role + resource + relationship)
- Input validity
- Attendance eligibility
- QR integrity
- Challenge ownership and expiration
- Session state
- Duplicate attendance

---

## 3. Authentication Security

| Requirement | Implementation |
|-------------|----------------|
| Password storage | **Argon2id** (or equivalent secure hash) |
| Plaintext passwords | **NEVER stored** |
| Login rate limiting | **Required** |
| Login attempt tracking | **Required** |
| Failed login logging | **Security event** |
| Successful login logging | **Audit log** |
| Token/session management | **Server-controlled** |
| Refresh token security | **Required** |
| Token reuse detection | **Required** |
| Logout | **Invalidates session/token** |

---

## 4. Authorization Security

### RBAC

| Role | Scope |
|------|-------|
| `SUPER_ADMIN` | Full system access |
| `ADMIN` | Institution-level, permission-controlled |
| `TEACHER` | Only assigned courses and classes |
| `STUDENT` | Only own data |

### Resource-Level Authorization

- Role alone is **NOT sufficient**
- Resource ownership must be verified
- Relationship-based access required
- Teacher cannot access unassigned courses/classes
- Student cannot access other students' data
- Admin permissions checked via permission system

### Protection Against:

- **IDOR** (Insecure Direct Object Reference)
- **BOLA** (Broken Object Level Authorization)
- **Privilege escalation**
- **Unauthorized resource access**

---

## 5. QR Security

| Requirement | Implementation |
|-------------|----------------|
| QR type | Dynamic, short-lived |
| QR signing | HMAC-SHA256 |
| QR nonce | Unique per generation |
| QR expiration | Enforced server-side |
| QR replay protection | Nonce + expiration |
| QR tampering detection | HMAC signature verification |
| QR → Attendance | QR does NOT directly create attendance |
| Sensitive data in QR | Minimal payload only |

---

## 6. Challenge-Response Security

| Requirement | Implementation |
|-------------|----------------|
| Challenge uniqueness | Unique per request |
| Challenge nonce | Server-generated |
| Challenge expiration | Short-lived, enforced |
| Challenge single-use | `used_at` tracking |
| Challenge replay | Rejected |
| Challenge ownership | Bound to student + session |

---

## 7. Attendance Transaction Security

| Requirement | Implementation |
|-------------|----------------|
| Transaction boundary | Entire attendance creation |
| Duplicate prevention | `UNIQUE(attendance_session_id, student_id)` |
| Concurrent protection | Race condition handling |
| Student self-modification | **BLOCKED** |
| Status override | Teacher only (audited) |

---

## 8. Risk Scoring Security

| Requirement | Implementation |
|-------------|----------------|
| Risk configuration | `system_settings` or config, NOT hard-coded |
| Risk signals | Spec-defined (expired QR, replay, new device, etc.) |
| Risk integration | Attendance validation pipeline |
| Risk outcomes | `PRESENT`, `PRESENT+EVENT`, `PENDING_REVIEW`, `BLOCKED` |

---

## 9. Data Security

### Never Store in Source Control:

- `.env` files
- Plaintext passwords
- API keys / secrets
- Private keys
- Database credentials
- Authentication tokens
- Session secrets

### Never Log:

- Passwords
- Raw authentication tokens
- Private keys
- Unnecessary sensitive personal data

### Never Expose to Client:

- Technical security details on failure
- Internal error messages
- Stack traces
- Database error details

---

## 10. Security Event Logging

All security-sensitive events must be logged:

| Event Category | Examples |
|---------------|----------|
| Authentication | Login failures, suspicious auth attempts |
| QR | Replay attempts, invalid QR, expired QR |
| Challenge | Invalid challenge, expired challenge |
| Attendance | Duplicate attendance, unauthorized attempts |
| Authorization | Unauthorized access, IDOR attempts |
| Device | Suspicious device, new device |
| Risk | Risk escalation, blocked attendance |

---

## 11. Audit Logging

All administrative and state-changing actions must be audited:

| Audit Category | Fields |
|---------------|--------|
| Attendance changes | Actor, target, old state, new state, reason, timestamp |
| Administrative actions | Actor, action, target entity, timestamp |
| Security events | Event type, actor, details, IP, timestamp |

---

## 12. Testing Security Requirements

The following must be tested:

| Category | Tests |
|----------|-------|
| Authentication | Valid login, invalid login, rate limit, token reuse |
| Authorization | IDOR, BOLA, unauthorized teacher/student, privilege escalation |
| QR | Valid QR, expired QR, fake QR, QR replay, duplicate scan |
| Attendance | Valid, duplicate, wrong course/class, manual operations, close/cancel |
| Security | Brute force, rate limit abuse, SQL injection, XSS, CSRF, concurrent requests |

### Rules:

- Do NOT disable tests to make a build pass
- Do NOT weaken security to make tests pass
- Every failure must be fixed, not bypassed
