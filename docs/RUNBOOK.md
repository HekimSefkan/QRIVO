# QRIVO — Local Runbook

How to get QRIVO running on a developer machine, seed a realistic demo dataset,
log in, and verify the whole attendance flow end to end.

Two supported paths:

| Path | When to use it | Section |
|---|---|---|
| **A — Docker** | You have Docker Desktop. Fewest moving parts. | [§2](#2-path-a--docker) |
| **B — Native** (Laragon / XAMPP / bare PHP + MySQL) | You already run PHP and MySQL locally, e.g. Laragon on Windows. | [§3](#3-path-b--native-laragon--xampp--bare) |

Both paths end at the same place: a seeded database, a server on
`http://localhost:8000`, and a passing smoke test.

---

## 1. Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| PHP | **8.3+** | `backend/composer.json` → `"php": "^8.3"`. Needs `pdo_mysql`, `mbstring`, `ctype`, `openssl`, `json`, `filter` (all standard). |
| Composer | **2.x** | |
| MySQL | **8.0+** | InnoDB + `utf8mb4`. Locked by `docs/ARCHITECTURE_RULES.md` §1.1 — MariaDB is not a substitute. |
| Node.js | **not required** | QRIVO has no JavaScript build step. |
| Flutter / Dart | 3.19+ / 3.3+ | Only for the mobile app (`mobile/README.md`). Not needed for the backend. |

Docker path additionally needs Docker Desktop (Compose v2). Nothing else.

---

## 2. Path A — Docker

```bash
cp .env.docker.example .env
```
Creates the compose environment file. **Edit it and set the two `CHANGE ME`
values** — `DB_PASSWORD` (any local value) and `SEED_DEFAULT_PASSWORD`
(≥ 8 characters). The root `.env` is gitignored.

```bash
docker compose up -d --build
```
Builds the API image (PHP 8.3 + `pdo_mysql`) and starts MySQL 8.4. The API waits
for the database healthcheck to pass, then installs Composer dependencies on
first boot and serves on `0.0.0.0:8000`.

```bash
docker compose logs -f api      # watch until "QRIVO API listening on 0.0.0.0:8000"
```

```bash
docker compose exec api php scripts/migrate.php
docker compose exec api php scripts/seed.php
```
Applies migrations, then creates the demo dataset (§5).

```bash
docker compose exec api php scripts/smoke_test.php
```
Runs the end-to-end verification (§6).

**Stop / reset:**

```bash
docker compose down             # stop, keep the data volume
docker compose down -v          # stop and DELETE the database volume (full reset)
```

---

## 3. Path B — Native (Laragon / XAMPP / bare)

### 3.1 Start MySQL

- **Laragon** — open Laragon and press **Start All** (or start only MySQL).
  Apache/Nginx are **not** needed: QRIVO runs on PHP's built-in server.
- **XAMPP** — start MySQL from the control panel. Apache not needed.
- **Bare MySQL** — ensure `mysqld` is listening on 3306.

Verify:

```powershell
# Windows / PowerShell
Get-NetTCPConnection -LocalPort 3306 -State Listen
```

### 3.2 Configure the backend

```powershell
cd backend
Copy-Item .env.example .env
```
Creates the backend environment file (gitignored).

Then edit `backend/.env` and set:

| Variable | Value |
|---|---|
| `APP_ENV` | `local` — **required**; the seeder refuses to run otherwise |
| `APP_DEBUG` | `true` |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` |
| `DB_DATABASE` | `qrivo` |
| `DB_USERNAME` / `DB_PASSWORD` | your MySQL credentials (Laragon default: `root` / empty) |
| `SEED_DEFAULT_PASSWORD` | a local demo password, ≥ 8 characters — **your choice, never committed** |

There is **no application key or crypto secret to configure.** Auth tokens are
generated per-login with `random_bytes()` and stored only as SHA-256 hashes
(DD-012); each attendance session's HMAC key (`session_secret`) is generated
server-side per session (DD-002). Nothing cryptographic belongs in `.env`.

### 3.3 Install, migrate, seed, serve

```powershell
composer install
```
Installs `phpdotenv`, `monolog`, `fast-route` and PHPUnit into `backend/vendor/`.

```powershell
php scripts/migrate.php
```
Creates the `qrivo` database if absent and applies `database/migrations/*.sql`.

```powershell
php scripts/seed.php
```
Creates the demo dataset and prints the login table (§5).

```powershell
php -S localhost:8000 -t public
```
Starts the API. Leave this window open; `Ctrl+C` stops it.

In a **second** terminal:

```powershell
cd backend
php scripts/smoke_test.php
```

> **Windows note:** if PHP prints
> `Warning: PHP Startup: Unable to load dynamic library 'intl'`, it is harmless.
> That is a Laragon `php.ini` setting; QRIVO does not use `intl`.

---

## 4. The scripts

All three live in `backend/scripts/` and are run from the `backend/` directory.
They read `backend/.env` through the same `Config` class the application uses, so
they always agree with the running server.

### `migrate.php`

```bash
php scripts/migrate.php            # apply everything pending
php scripts/migrate.php --status   # list applied / pending, change nothing
php scripts/migrate.php --fresh    # DROP the database, recreate, re-apply
```

- Creates the database (`utf8mb4` / `utf8mb4_unicode_ci`) if it does not exist.
- Applies `database/migrations/*.sql` in filename order.
- Records each file in `schema_migrations` (filename, SHA-256, statement count,
  duration) so **re-running is a no-op**.
- Splits each file into statements with a quote-aware scanner
  (`SqlScriptSplitter`) — a naive `explode(';')` would break on the semicolons
  inside `COMMENT='…'` clauses in migration 001.
- `--fresh` is destructive and refuses to run unless `APP_ENV=local`.

> **Transaction caveat, stated honestly:** each file is wrapped in a transaction,
> but MySQL performs an *implicit commit* on every DDL statement. The wrapper is
> therefore genuinely atomic only for the data-only migrations (002, 008) and the
> ledger write. If a DDL file fails midway, the earlier statements remain and the
> file is **not** recorded as applied — safe, because every migration uses
> `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`, so simply re-run it.

### `seed.php`

```bash
php scripts/seed.php
```

- **Refuses to run unless `APP_ENV=local`.**
- Requires `SEED_DEFAULT_PASSWORD` (≥ 8 chars) in `backend/.env`.
- Every `password_hash` is computed **at runtime** with `PASSWORD_ARGON2ID`.
  No hash and no plaintext password exists anywhere in the repository.
- Fully **idempotent** — each insert is keyed on the table's UNIQUE column, so
  re-running reports `rows inserted: 0`.
- Re-centres the "live" course schedule on the current time each run, so
  *start attendance* always works right after seeding.

### `smoke_test.php`

```bash
php scripts/smoke_test.php [--base-url=http://localhost:8000]
```

Needs a **running server** and a seeded database. See §6.

---

## 5. Seeded demo data

`seed.php` creates one coherent institution:

| Entity | Value |
|---|---|
| School → Faculty → Department → Program | QRIVO Demo University → Engineering → Computer Engineering → CENG BSc |
| Academic year / term | 2025-2026 / **Fall 2025 (ACTIVE)** |
| Rooms | Lecture Hall A (A-101), Lab B (B-205) |
| Courses | CENG201 Data Structures, CENG301 Operating Systems, CENG305 Database Systems |
| Class | CENG-2A — **12 students enrolled**, each in all 3 courses |
| Schedules | CENG201 **on today's weekday, covering the current time**; the other two on fixed days |

### Accounts

All accounts use the password you set in `SEED_DEFAULT_PASSWORD`.

| E-mail | Role |
|---|---|
| `superadmin@qrivo.local` | SUPER_ADMIN |
| `admin@qrivo.local` | ADMIN |
| `teacher1@qrivo.local` | TEACHER — owns the live CENG201 schedule |
| `teacher2@qrivo.local` | TEACHER |
| `student01@qrivo.local` … `student12@qrivo.local` | STUDENT (12) |

This resolves **FINAL_AUDIT F-2 / OQ-004 for local development**. Production
account provisioning remains an open question — do **not** run this seeder
against a shared or production database (it refuses unless `APP_ENV=local`).

`teacher1` can start attendance immediately:

```
POST /api/v1/teacher/attendance/start   {"class_id": 1, "course_id": 1}
```

(the seeder prints the actual ids for your database at the end of its run).

---

## 6. Smoke test

With the server running:

```bash
php scripts/smoke_test.php
```

It walks the full lifecycle over real HTTP and asserts each step:

1. Health check (`database: ok`)
2. SUPER_ADMIN login → discover the schedule covering *now*
3. TEACHER login → assert the `TEACHER` role
4. Start attendance → `ACTIVE`, 12 × `WAITING`, **`session_secret` withheld**
5. Fetch the dynamic QR → `qrivo.v1.…`, 6 parts, no secret leaked
6. STUDENT login (clean account **and** a probe account)
7. QR preflight → `valid: true`
8. Request challenge → `challenge_id` + `nonce`
9. Submit challenge response → **`PRESENT` / source `QR`**
10. Replay a used challenge → **HTTP 409**
11. Teacher live list → student01 shows `PRESENT` / `QR`
12. Manual override of a `WAITING` student → `LATE` / `MANUAL` + audit id
13. Student attempts to modify attendance → **HTTP 403**
14. Close session → every `WAITING` becomes `ABSENT`, settled statuses untouched
15. Challenge after close → rejected; re-close → **HTTP 409**
16. Audit + security trail present, and **no password or raw token in either**

It fails loudly with the HTTP status and the full response body.

> **Why two student accounts?** `student01` runs the clean path and is never used
> for a deliberate rejection. The risk engine correctly remembers recent security
> events per user for `RISK_HISTORY_WINDOW_SECONDS` (default 900 s), so probing a
> replay with `student01` would raise it to HIGH risk and the *next* run would
> legitimately return `PENDING_REVIEW` instead of `PRESENT`. `student12` absorbs
> every intentional rejection. This is the risk engine working as designed — the
> test is shaped around it rather than weakening it.

---

## 7. Resetting

| Goal | Command |
|---|---|
| Re-seed only (safe, idempotent) | `php scripts/seed.php` |
| Rebuild the schema and demo data | `php scripts/migrate.php --fresh && php scripts/seed.php` |
| Docker: delete everything including the volume | `docker compose down -v` |
| Check what has been applied | `php scripts/migrate.php --status` |

`--fresh` **drops the database**. It refuses to run unless `APP_ENV=local`.

---

## 8. Running the automated test suite

The PHPUnit suite is independent of all of the above — it uses an in-memory
SQLite schema and needs **no MySQL and no `.env`**:

```bash
cd backend
composer test
# or: vendor/bin/phpunit --no-coverage
```

---

## 9. Mobile app

See `mobile/README.md`. In short, from `mobile/`:

```bash
flutter create .        # regenerate the gitignored platform folders
flutter pub get
flutter run --dart-define=QRIVO_API_BASE_URL=http://10.0.2.2:8000    # Android emulator
```

| Client | `QRIVO_API_BASE_URL` | Server must bind to |
|---|---|---|
| Android emulator | `http://10.0.2.2:8000` | `localhost` is enough |
| Physical Android device | `http://<your-LAN-IP>:8000` | `0.0.0.0` |

For a physical device, serve with `php -S 0.0.0.0:8000 -t public` (Docker already
publishes on `0.0.0.0`), find your IP with `Get-NetIPAddress -AddressFamily IPv4`,
and allow inbound TCP 8000 through the firewall on a **private** network.

---

## 10. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `/api/v1/health` returns **503**, `"database":"unavailable"` | MySQL is not running, or `DB_*` in `backend/.env` is wrong. |
| `Cannot reach the database server at …` | Same — start MySQL, check host/port/credentials. |
| `seed.php refuses to run: APP_ENV is 'production'` | Set `APP_ENV=local` in `backend/.env`. Working as intended. |
| `SEED_DEFAULT_PASSWORD is not set` | Add it to `backend/.env` (≥ 8 characters). |
| Smoke test: *An ACTIVE session already exists* | A previous manual test left a session open. `php scripts/migrate.php --fresh && php scripts/seed.php`. |
| Smoke test: *no course schedule covers the current time* | Re-run `php scripts/seed.php` — it re-centres the demo slot on the current clock. |
| Attendance returns `PENDING_REVIEW` instead of `PRESENT` | The risk engine saw recent security events for that student (default 15-minute window). Expected behaviour — use a different student or wait out the window. |
| `Unable to load dynamic library 'intl'` | Harmless local `php.ini` warning; QRIVO does not use `intl`. |
| Port 8000 or 3306 already in use | Change `APP_PORT` / `DB_PORT` in the root `.env` (Docker), or pass a different port to `php -S`. |
