# QRIVO — Production Deployment

Reference deployment: **nginx + PHP-FPM 8.3 + MySQL 8** on a single Linux host.

> **Status.** `OQ-007` (deployment architecture) is still formally open — no
> target environment was ever specified. This document is the *reference*
> deployment, not a decision that QRIVO must be hosted this way. It exists so the
> security-relevant parts of the question (TLS, CORS, secrets, backups) have
> concrete answers instead of prose. If you adopt it, answer OQ-007 accordingly.
>
> For local development read `docs/RUNBOOK.md` instead. Nothing here uses
> `scripts/seed.php`, which refuses to run outside `APP_ENV=local` by design.

---

## 1. Host prerequisites

| Component | Version | Notes |
| --- | --- | --- |
| PHP | **8.3** | `composer.json` requires `^8.3` |
| PHP extensions | `pdo_mysql`, `mbstring` | `pdo_sqlite` is test-only, not needed in production |
| MySQL | **8.0+** | verified against 8.4 |
| nginx | any current | TLS terminator and static file server |
| Composer | 2.x | build-time only; not needed at runtime |

```bash
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml
```

---

## 2. Filesystem layout and ownership

```
/var/www/qrivo/            # git checkout
├── backend/
│   ├── public/            # ← nginx document root. NOTHING else is web-reachable.
│   ├── src/  config/  routes/  database/
│   ├── storage/logs/      # writable by the PHP-FPM user
│   └── .env               # 0640, owned root:www-data — NEVER world-readable
└── web/                   # teacher web client (static)
```

```bash
sudo chown -R root:www-data /var/www/qrivo
sudo find /var/www/qrivo -type d -exec chmod 750 {} \;
sudo find /var/www/qrivo -type f -exec chmod 640 {} \;
sudo chown -R www-data:www-data /var/www/qrivo/backend/storage
sudo chmod 750 /var/www/qrivo/backend/storage/logs
sudo chmod 640 /var/www/qrivo/backend/.env
```

The document root is `backend/public` and **only** `backend/public`. If the
document root is ever set to `backend/`, then `.env`, `src/` and `database/`
become downloadable over HTTP. That is the single most damaging misconfiguration
available here.

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` matters: it leaves PHPUnit and its dependencies off the production
host entirely.

---

## 3. nginx

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name qrivo.example.edu;
    # Everything except the ACME challenge goes to HTTPS.
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name qrivo.example.edu;

    ssl_certificate     /etc/letsencrypt/live/qrivo.example.edu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/qrivo.example.edu/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options    "nosniff" always;
    add_header X-Frame-Options           "DENY"    always;
    add_header Referrer-Policy           "no-referrer" always;

    # ── API ────────────────────────────────────────────────────────────────
    root /var/www/qrivo/backend/public;
    index index.php;

    # Front controller. Every /api/v1/* path is a route, not a file, so the
    # rewrite must reach index.php with the URI intact.
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ ^/index\.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php/php8.3-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 30s;
    }

    # Only index.php may be executed. Without this, any other .php file that
    # ever lands in public/ becomes an entry point.
    location ~ \.php$ { return 404; }

    # Defence in depth — these must not be under the document root anyway.
    location ~ /\.(env|git) { deny all; return 404; }

    # ── Teacher web client ─────────────────────────────────────────────────
    location /app/ {
        alias /var/www/qrivo/web/;
        try_files $uri $uri/ /app/index.html;
    }

    client_max_body_size 2m;
}
```

Serving the client under `/app/` on the **same origin** as the API is the
simplest correct answer to CORS: same-origin requests are not cross-origin, so
§5 becomes a formality. If you host the client elsewhere, its origin must be
listed explicitly in `CORS_ALLOWED_ORIGINS`.

### TLS certificate

```bash
sudo certbot certonly --webroot -w /var/www/certbot -d qrivo.example.edu
```

Renewal is a systemd timer installed by certbot; confirm with
`systemctl list-timers | grep certbot`. Add `--deploy-hook "systemctl reload nginx"`.

---

## 4. MySQL 8

Create a **dedicated, least-privilege** account. Do not run QRIVO as `root`.

```sql
CREATE DATABASE qrivo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'qrivo'@'127.0.0.1' IDENTIFIED BY '<generated>';
GRANT SELECT, INSERT, UPDATE, DELETE ON qrivo.* TO 'qrivo'@'127.0.0.1';
FLUSH PRIVILEGES;
```

`GRANT` deliberately excludes DDL. Migrations are run manually as a privileged
user during a deploy (§8); the application account cannot alter the schema, so a
SQL-injection foothold cannot drop a table.

`/etc/mysql/mysql.conf.d/qrivo.cnf`:

```ini
[mysqld]
bind-address                = 127.0.0.1     # never expose 3306 to the network
character-set-server        = utf8mb4
collation-server            = utf8mb4_unicode_ci
default-storage-engine      = InnoDB
transaction-isolation       = READ-COMMITTED
innodb_flush_log_at_trx_commit = 1
max_connections             = 200
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION
```

`READ-COMMITTED` matches how the attendance flow is written: session creation and
challenge redemption take explicit `FOR UPDATE` row locks (C-006) rather than
relying on repeatable-read snapshots. `STRICT_TRANS_TABLES` ensures a bad write
errors instead of being silently truncated.

---

## 5. Environment (`backend/.env`)

Copy `backend/.env.example` and change **every** value below. The file is never
committed — it is gitignored, and `chmod 640`.

| Variable | Production value | Why |
| --- | --- | --- |
| `APP_ENV` | `production` | also what makes `seed.php` refuse to run |
| `APP_DEBUG` | `false` | debug responses leak internals |
| `APP_URL` | `https://qrivo.example.edu` | |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_USERNAME` / `DB_PASSWORD` | the least-privilege account from §4 | never `root` |
| **`CORS_ALLOWED_ORIGINS`** | **explicit origins, comma-separated** | see below |
| `LOG_LEVEL` | `warning` or `error` | `debug` is noisy and records more than production needs |
| `LOG_MAX_FILES` | `30`+ | retention, in days |
| `AUTH_ACCESS_TOKEN_TTL` | `3600` | |
| `AUTH_REFRESH_TOKEN_TTL` | `2592000` | shorten if your policy is stricter |
| `SEED_DEFAULT_PASSWORD` | **leave empty** | only the local seeder reads it |

### `CORS_ALLOWED_ORIGINS` — FINAL_AUDIT F-4

`config/app.php` falls back to `*` when the variable is unset:

```php
'allowed_origins' => array_filter(explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*')),
```

`*` is acceptable for local development and **not** acceptable in production: it
lets any origin issue credentialed cross-site requests to the API. Set it
explicitly:

```dotenv
CORS_ALLOWED_ORIGINS=https://qrivo.example.edu
```

If the client is served from the same origin as the API (§3), that single value
is all that is needed. Add further origins only for a genuinely separate
front-end host. **Never leave `*` in a deployed `.env`.** Verify after deploy:

```bash
curl -sS -I -H "Origin: https://evil.example" https://qrivo.example.edu/api/v1/health | grep -i access-control-allow-origin
```

That must **not** echo `https://evil.example` and must not return `*`.

---

## 6. Logs

**The application already rotates its own logs.** `Infrastructure/Logging` uses
Monolog's `RotatingFileHandler` with `LOG_MAX_FILES` (default 30), writing
date-stamped files under `backend/storage/logs/`. Do **not** add a logrotate rule
for that directory — two rotators on the same files fight, and you get truncated
or vanished logs.

Retention is therefore `LOG_MAX_FILES` days. Raise it if your institution
requires longer, and ship logs off-host if the retention requirement is real.

What **does** need `logrotate` is nginx and PHP-FPM, which is already configured
by their distribution packages. Verify:

```bash
sudo logrotate -d /etc/logrotate.d/nginx
```

Ensure the log directory stays writable and is not web-reachable — it sits
outside `public/`, so it is not, provided §2's document root is correct.

Audit and security trails live in the **database** (`audit_logs`,
`security_events`), not in these files. Their retention is a backup question
(§7), not a rotation question.

---

## 7. Database backup

Attendance records are the system of record for a student's academic standing.
Treat the database as the only thing on this host that cannot be rebuilt.

```bash
#!/usr/bin/env bash
# /usr/local/bin/qrivo-backup — run daily via systemd timer or cron
set -euo pipefail
DEST=/var/backups/qrivo
STAMP=$(date +%F-%H%M)
mkdir -p "$DEST"
mysqldump --defaults-file=/root/.qrivo-backup.cnf \
          --single-transaction --quick --routines --triggers \
          qrivo | gzip -9 > "$DEST/qrivo-$STAMP.sql.gz"
find "$DEST" -name 'qrivo-*.sql.gz' -mtime +30 -delete
```

- `--single-transaction` takes a consistent InnoDB snapshot without locking
  writers, so a backup during a live attendance session cannot block a student's
  check-in.
- Credentials go in `/root/.qrivo-backup.cnf` (`chmod 600`), never on the
  command line where they would appear in `ps`.
- **Copy backups off the host.** A backup on the same disk as the database
  protects against nothing that actually happens.
- **Test the restore.** An untested backup is a guess:
  ```bash
  gunzip -c qrivo-2026-09-02-0300.sql.gz | mysql qrivo_restore_test
  ```

---

## 8. Deploying and migrating

```bash
cd /var/www/qrivo
git pull --ff-only
cd backend
composer install --no-dev --optimize-autoloader --no-interaction
php scripts/migrate.php          # as a DDL-privileged user, not the app account
sudo systemctl reload php8.3-fpm
```

`migrate.php` is safe to re-run: `schema_migrations` records what has been
applied and a second run reports `0 applied`. Check first with
`php scripts/migrate.php --status`.

**Never run `migrate.php --fresh` in production** — it drops the database. It is
gated on `APP_ENV=local`, and `APP_ENV=production` is your seatbelt (§5).

---

## 9. Creating the first SUPER_ADMIN

`scripts/seed.php` **cannot** be used: it refuses to run unless `APP_ENV=local`,
and it creates twelve demo students besides. That guard is deliberate — do not
work around it.

There is also no admin user-provisioning endpoint yet (`OQ-004`, still open,
`FINAL_AUDIT` F-2). Until one exists, the first account is created by direct
insert. This is a **one-time bootstrap**, done once per deployment.

**Step 1 — generate the hash on the server, interactively.** The password is
never passed as an argument, so it never reaches shell history or `ps`:

```bash
php -r '$p = readline("Password: "); echo password_hash($p, PASSWORD_ARGON2ID), "\n";'
```

Choose a long random password from a password manager. Copy the `$argon2id$...`
output.

**Step 2 — insert the account and grant the role**, in one transaction:

```sql
START TRANSACTION;

INSERT INTO `users`
  (`uuid`, `email`, `password_hash`, `first_name`, `last_name`, `is_active`, `is_approved`)
VALUES
  (UUID(), 'admin@qrivo.example.edu', '<paste the argon2id hash>',
   'Site', 'Administrator', 1, 1);

INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT LAST_INSERT_ID(), `id` FROM `roles` WHERE `name` = 'SUPER_ADMIN';

COMMIT;
```

`is_active` and `is_approved` must both be `1` or login is refused. `SUPER_ADMIN`
is role id 1, seeded by migration `002`; the `SELECT` avoids hard-coding it.

**Step 3 — verify, then clear your history.**

```bash
curl -sS -X POST https://qrivo.example.edu/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@qrivo.example.edu","password":"..."}' | head -c 200
```

```bash
mysql --defaults-file=/root/.my.cnf   # avoids -p<password> in history
history -c && rm -f ~/.mysql_history
```

**Notes**

- Run this against the DDL-privileged account, not the least-privilege app user
  from §4 — though `INSERT` alone is enough here.
- The password hash is Argon2id, computed by PHP with the same
  `PASSWORD_ARGON2ID` the application uses to verify. Do not compute it any other
  way.
- Create exactly **one** SUPER_ADMIN this way, then create everyone else through
  the application. Note that nothing currently prevents the last SUPER_ADMIN
  from being demoted (`OQ-005`) — until that is resolved, consider creating a
  second SUPER_ADMIN as a recovery account and storing its credentials offline.

---

## 10. Post-deploy checklist

- [ ] `https://` serves; `http://` redirects; HSTS present
- [ ] `curl https://host/.env` → **404**, not the file
- [ ] `curl https://host/api/v1/health` → 200
- [ ] CORS does not echo an arbitrary `Origin` and is not `*` (§5)
- [ ] `APP_DEBUG=false` — force a 500 and confirm no stack trace reaches the client
- [ ] `.env` is `640`, owned `root:www-data`
- [ ] MySQL is not listening on a public interface: `ss -lntp | grep 3306`
- [ ] `php scripts/migrate.php --status` → `0 pending`
- [ ] a backup has been taken **and restored** into a scratch database
- [ ] the first SUPER_ADMIN can log in, and shell/MySQL history has been cleared
