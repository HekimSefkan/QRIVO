# QRIVO

**Secure QR-Based University Attendance System**

---

## Project Purpose

QRIVO is a university attendance system designed to eliminate proxy attendance and manual record-keeping through cryptographically secured, time-limited QR codes. Teachers generate dynamic QR codes during live sessions; students scan them via a mobile application; the server validates each attendance submission through a multi-step verification pipeline that includes challenge-response, replay protection, and risk scoring.

## Major Components

| Component | Description |
|-----------|-------------|
| **Backend API** | RESTful server handling authentication, QR generation, attendance validation, and administration |
| **Web Dashboard** | Administrative and teacher-facing interface for session management, reports, and system configuration |
| **Mobile Application** | Student-facing app for QR scanning, challenge-response completion, and attendance history |
| **Database** | Persistent store for users, sessions, attendance records, security events, and audit logs |

## Security-Focused Architecture

QRIVO treats attendance as a **security-critical transaction**, not a simple form submission. The architecture enforces:

- **Dynamic, short-lived QR codes** — each QR payload is unique and expires within seconds
- **HMAC-SHA256 integrity** — QR data is cryptographically signed to prevent forgery
- **Nonce-based replay protection** — each QR can only be used once
- **Server-side challenge-response** — the server issues a challenge that the client must answer before attendance is recorded
- **Risk scoring** — behavioral and contextual signals are evaluated to flag suspicious submissions
- **Role-Based Access Control (RBAC)** — all API endpoints enforce role and resource-level authorization server-side
- **Comprehensive audit logging** — every security-relevant event is logged for forensic review

## Development Status

> **Phase: Project Initialization**
>
> The project is in its initial documentation and scaffolding phase. No application code has been implemented yet. The project specification, architecture rules, attendance algorithm, and security rules are being documented as the authoritative foundation for all future development.

---

## Documentation

- [`docs/PROJECT_SPECIFICATION.md`](docs/PROJECT_SPECIFICATION.md) — Full project requirements
- [`docs/ARCHITECTURE_RULES.md`](docs/ARCHITECTURE_RULES.md) — Protected architecture decisions
- [`docs/ATTENDANCE_ALGORITHM.md`](docs/ATTENDANCE_ALGORITHM.md) — Authoritative attendance algorithm
- [`docs/SECURITY_RULES.md`](docs/SECURITY_RULES.md) — Security requirements and constraints
- [`docs/DEVELOPMENT_PLAN.md`](docs/DEVELOPMENT_PLAN.md) — Phased implementation plan
- [`docs/OPEN_QUESTIONS.md`](docs/OPEN_QUESTIONS.md) — Unresolved requirements and design questions

## License

To be determined.
