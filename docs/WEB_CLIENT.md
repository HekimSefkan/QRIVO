# QRIVO — Web Client (Teacher Panel)

The teacher-facing web dashboard: login, dashboard, **live attendance**, and
reports. Resolves `FINAL_AUDIT` **F-5** (web client never built) and **OQ-006**
(which frontend technology).

---

## 1. Technology (OQ-006 resolved)

| Decision | Value |
|---|---|
| Markup / styling | HTML5 + CSS + **Bootstrap 5.3.3** (vendored locally) |
| Scripting | **Vanilla JavaScript** (ES2017), no framework |
| Build step | **None.** The files are served as-is. |
| API access | `fetch` + `Authorization: Bearer <token>` |
| QR rendering | `qrcode-generator` 1.4.4 (MIT), **vendored locally** |
| Served from | `web/` — any static file server |

> `ORIGINAL_SPECIFICATION.md` does not name a frontend technology (its only
> technology list, §5, is for the backend) — which is precisely why OQ-006 was
> raised. Static HTML + Bootstrap was chosen for this phase. Nothing in
> `ARCHITECTURE_FREEZE.md` §2.15 forbids it: the web client must only consume the
> REST API and must never be a security boundary. See `ACCEPTED_DEVIATIONS.md`
> **AD-018**.

**No third-party runtime dependencies.** Bootstrap and the QR library are files
in `web/vendor/`. The QR payload is rendered to SVG **in the browser** and is
never sent to any external service.

---

## 2. Security posture

The client is a presentation layer. It holds no secrets and makes **no**
authorization decisions.

- **Every** decision is the server's. The client renders answers:
  - the *"YOKLAMA BAŞLAT"* button is enabled only when
    `GET /teacher/attendance/eligibility` returns `authorized: true`; the client
    never evaluates schedules, assignments or terms itself.
  - a status change is a `PATCH`; close/cancel are `POST`s. The API enforces
    ownership, state and permissions and the client shows the outcome.
  - `requireSession()` in `auth.js` only checks that a token exists, so the
    browser can redirect to the login page instead of rendering an empty screen.
    It is a **UX guard, not a security control** — every page immediately calls
    the API, which decides what the caller may see.
- **Errors are the API's own words.** `ApiError.message` is always the server's
  message (or a neutral network fallback). The client never infers or displays a
  security reason — a failed login shows exactly `Invalid credentials.`
- **Tokens** live in memory and `sessionStorage` (tab-scoped). On a `401` the
  client attempts **one** single-flight refresh via `/auth/refresh` and retries;
  if that fails the session is cleared and the user returns to login.
- **XSS**: the API deliberately stores user text verbatim (it is data, not
  markup — `FINAL_AUDIT` §2 row 19). The client therefore **never** interpolates
  API data into `innerHTML`; everything goes through `QrivoUi.el()` /
  `textContent`. The single `innerHTML` use is the QR library's own generated
  SVG, built from the server-issued payload.

---

## 3. Structure

```
web/
├── index.html          Login
├── dashboard.html      Teacher dashboard
├── live.html           Live attendance (the core screen)
├── reports.html        Course / class / student reports
├── assets/
│   ├── css/app.css     Layer on top of Bootstrap
│   └── js/
│       ├── config.js   API base URL resolution
│       ├── api.js      fetch wrapper, token pair, 401 → refresh → retry
│       ├── auth.js     session guard + navbar chrome
│       ├── ui.js       badges, toasts, confirm dialog, escaping helpers
│       ├── dashboard.js
│       ├── live.js     QR + polling + roster + manual override + close/cancel
│       └── reports.js
└── vendor/             bootstrap.min.css, bootstrap.bundle.min.js, qrcode.min.js
```

---

## 4. Screens

### 4.1 Login (`index.html`)
`POST /auth/login`. Stores the token pair, redirects to the dashboard. The
collapsible *"Sunucu adresi"* section overrides the API base URL (stored in
`localStorage`) — useful when the API is not on `localhost:8000`.

### 4.2 Dashboard (`dashboard.html`)
`GET /teacher/dashboard` → the four blocks the specification (§12) names:

| Block | Source |
|---|---|
| **Bugünkü dersler** | `today_schedule`, each gated by `/teacher/attendance/eligibility` |
| **Aktif yoklama** | `active_sessions` — links straight to the live screen |
| **Son yoklamalar** | `recent_sessions` (CLOSED / CANCELLED) with VAR/TOPLAM |
| **Toplam katılım** | `totals` + a computed attendance rate |

### 4.3 Live attendance (`live.html?session=<id>`) — the core screen

Layout per specification §12 — **left** panel QR + details, **right** panel the
student list. On tablet/mobile the DOM order gives the required stacking:
**QR → Ders Bilgisi → Özet → Öğrenci Listesi**.

**Left panel**
- The dynamic QR, drawn locally from `GET /teacher/attendance/{id}/qr`, refreshed
  on the interval the API reports (`refresh_seconds`, default 30 s).
- Course, class, room, teacher, start time, remaining time (ticking locally,
  re-synced from the server every poll).
- The counters: **TOPLAM / VAR / YOK / GEÇ / MAZERETLİ / BEKLİYOR**.
- **YOKLAMAYI KAPAT** and **YOKLAMAYI İPTAL ET**, both behind a confirmation
  dialog (cancel additionally takes a reason).

**Right panel**
- The roster: name, number, status badge, **QR / MANUEL / SİSTEM** source,
  timestamp, and a status selector.
- **Search** (server-side, name or number), **student-number filter**
  (client-side narrowing) and a **status filter** (server-side).
- Changing a status opens a confirmation dialog with a **Gerekçe** field, then
  `PATCH /teacher/attendance/{id}/student/{studentId}`. The reason is written to
  `audit_logs`. On failure the control rolls back and the API's message is shown.
- **Bulk actions** ("Bekleyenleri YOK/VAR yap") require confirmation and issue one
  independent, individually-audited `PATCH` per student.

**Polling (AD-010)** — the specification's own fallback, since the frozen stack
has no WebSocket server. Every ~3 s (`poll_interval_ms` from the API):
`/live/counters` is fetched, and the roster is re-fetched **only** when
`students_version` changes. Polling pauses while the tab is hidden and stops when
the session leaves `ACTIVE`.

**Accessibility** — status is carried by **badge text** (`VAR`, `YOK`, `GEÇ`,
`MAZERETLİ`), not colour alone; badges carry `aria-label`; the poll indicator has
a text label; `prefers-reduced-motion` disables the row-change animation.

### 4.4 Reports (`reports.html`)
`GET /teacher/reports/{course|class|student}/{id}` with date range and
pagination. The pickers are populated from `/teacher/schedule`, so only assigned
courses/classes are offered — **and the server re-checks**: requesting another
teacher's course returns `403 You are not authorized to view this report.`

---

## 5. Running it

The client is static; serve `web/` with anything. With the backend already
running on `:8000` (see `docs/RUNBOOK.md`):

```bash
# from the repository root, in a second terminal
php -S localhost:8080 -t web
```

Then open <http://localhost:8080> and sign in with a seeded teacher account
(`teacher1@qrivo.local` — password from `SEED_DEFAULT_PASSWORD`, see the runbook).

**Cross-origin:** the client on `:8080` calls the API on `:8000`, which works
because `CORS_ALLOWED_ORIGINS=*` in development. For production set explicit
origins (OQ-007) and serve the client from the same origin or a configured one.

Alternatives: any static server (`python -m http.server 8080`, nginx, Apache
vhost). No build, no npm, no bundler.

---

## 6. Backend addition made for these screens (AD-018)

The dashboard was **impossible** to build with the existing API: every teacher
endpoint needs an id (`class_id`, `course_id`, session id) that a teacher had no
way to discover, `/admin/course-schedules` is ADMIN-only, and `/student/schedule`
rejects non-students. The TEACHER role already held `profile.self.view` and
`schedule.self.view` with **no route consuming them** — the student equivalents
were added in Phase 16 and the teacher pair was missed.

Two read-only endpoints were added, mirroring `Student\SelfController`:

| Endpoint | Permission (pre-existing) |
|---|---|
| `GET /api/v1/teacher/dashboard` | `profile.self.view` |
| `GET /api/v1/teacher/schedule` | `schedule.self.view` |

No new permission, no RBAC change, no migration, no schema change, and no change
to any existing endpoint or security control. `teachers.id` is resolved from the
bearer token and can never be supplied by the client; a non-teacher gets `403`.

---

## 7. Defects found while building this (fixed)

Exercising the client against a live MySQL surfaced two **pre-existing** backend
bugs that the SQLite test harness could not catch, because SQLite tolerates a
reused named placeholder and MySQL (with `PDO::ATTR_EMULATE_PREPARES = false`)
rejects it with `SQLSTATE[HY093]`:

| Location | Bug | Symptom on MySQL |
|---|---|---|
| `AttendanceRecordRepository::liveRoster()` | `:q` bound once, used 3× | **HTTP 500** on every roster search |
| `RelationshipRepository::teacherSharesClassWithStudent()` | `:tid` bound once, used 2× | swallowed by `safeExists()` → a legitimate teacher was **wrongly denied (403)** a term-filtered student report |

Both were fixed by giving each occurrence its own placeholder (no logic or
security change — parameters remain bound). A regression guard,
`tests/Unit/Infrastructure/Repository/SqlPlaceholderReuseTest.php`, statically
scans the repository layer and fails on any reused placeholder; it was verified
to fail on both defects before the fix.
