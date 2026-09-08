# QRIVO — Jury Presentation Day

One page. Do these in order. **No internet is required for any of it.**

---

## 1. Start (or confirm) the system

```powershell
cd C:\Projects\QRIVO
.\start-qrivo.ps1
```

MySQL and Apache are Windows services and come up **at boot** on their own, so
normally this only confirms them, prints `QRIVO IS READY`, and starts nothing.

It no longer starts the public tunnel — that is opt-in (`-Tunnel`), because the
demo needs no internet. If it reports a service is stopped and it cannot start
it, that is correct behaviour, not a failure: starting a service needs an
**Administrator** PowerShell.

```powershell
Start-Service QRIVOMySQL, QRIVOApache
```

---

## 2. Re-seed so the lesson is happening NOW

**Do this every demo day. It is the single easiest way to lose the demo.**

The seeder writes the lesson against **today's weekday** and a window around the
moment it runs. Seed on Tuesday and demo on Wednesday and there is no Wednesday
lesson at all, so the panel refuses to start attendance with
`OUTSIDE_SCHEDULED_TIME`. That is the eligibility control working correctly, not
a bug — and it is the same refusal you would get from a stale time window, so
do not spend the demo debugging the clock.

```powershell
cd C:\Projects\QRIVO\backend
php scripts/seed.php
```

It is idempotent: it prints `rows inserted: 0` and re-centres the lesson. The last
lines show the window — check it covers the current time.

---

## 3. Turn on the hotspot

**Win+A → Mobile hotspot → on.** Windows always puts the laptop at
`192.168.137.1`. Join the phone to it. The phone will say "no internet" — expected
and correct; nothing here uses the internet.

---

## 4. Verify with one command

```powershell
cd C:\Projects\QRIVO
.\check-qrivo.ps1
```

The lines that matter: **MySQL**, **API**, **Teacher panel**, **Hotspot**,
**Firewall (8000 in)**. If those five are green you are ready.

*"Tunnel", "Published address" and "Reachable from outside" all concern the
optional internet path. Red or UNKNOWN on those does not affect the demo — the
script still exits non-zero, which is expected and is not a reason to start
debugging. "Published address" reads UNKNOWN with a STALE note whenever an old
address is still on record with no tunnel behind it; that is the script refusing
to show a dead address in green.*

---

## 5. Open the teacher panel

```
http://127.0.0.1:8000/panel/
```

**This URL, not `:8080`.** Here the panel is served by the API itself, so they
share an origin: no CORS, nothing cross-port to go wrong.

Sign in as the teacher, open **CENG201 / CENG-2A**, press **YOKLAMA BAŞLAT**. The
QR appears and refreshes every 30 seconds — that refresh is the anti-replay
design, not a glitch.

---

## 6. The phone

Open QRIVO and sign in as the student. It finds the laptop at `192.168.137.1:8000`
by itself — **no address is ever typed**. Scan the QR on your screen. The
student's row flips to **VAR / QR** with a timestamp within about 3 seconds.

---

## What to show the jury — all verified working on 2026-09-08

| Step | What it proves |
| --- | --- |
| Student scans the QR | Recorded as `PRESENT`, source `QR` |
| Scan the **same** QR again | Refused — the challenge is single-use |
| Teacher sets a student to **GEÇ** with a reason | Manual override, source `MANUAL`, written to `audit_logs` |
| **YOKLAMAYI KAPAT** | Session `CLOSED`; every remaining `WAITING` becomes `YOK` |
| A student trying to set their own status | **HTTP 403** — a student can never alter attendance |

---

## One security note before you present

The inbound firewall rules on this laptop are currently
`QRIVO API 8000 (private)` and `QRIVO API 8080 (private)`, and they allow **any**
local address. That means ports 8000 and 8080 accept inbound connections on
every interface, including whatever campus Wi-Fi you join on the day — not only
the hotspot.

It works, and the API still requires a login, but it is broader than
`install-autostart.ps1` intends: that script now creates rules named
`QRIVO API <port> (hotspot)` bound to `192.168.137.1` only. Those tighter rules
were never installed because the installer has not been re-run since it changed.

To close the gap, in an **Administrator** PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\windows\install-autostart.ps1
Get-NetFirewallRule -DisplayName 'QRIVO API * (private)' | Remove-NetFirewallRule
```

Do this **before** demo day, not on it, and re-run `check-qrivo.ps1` afterwards
with the hotspot on to confirm the phone can still reach the API.

---

## Logins

| Role | E-mail | Password |
| --- | --- | --- |
| Teacher | `teacher1@qrivo.local` | `Test1234!` |
| Student | `student01@qrivo.local` | `Test1234!` |
| Admin | `superadmin@qrivo.local` | `Test1234!` |

`student01` … `student12` all work, same password.

- **Panel:** `http://127.0.0.1:8000/panel/`
- **APK for the phone:** `http://192.168.137.1:8080/QRIVO.apk`
- **APK on the laptop:** `C:\Users\hekim\Desktop\QRIVO.apk`

---

## If something is down

| Symptom | Do this |
| --- | --- |
| **MySQL DOWN** | `Start-Service QRIVOMySQL` in an **admin** PowerShell. Do **not** also open Laragon — two MySQL instances cannot share one data directory. |
| **MySQL will not start** — "the service did not respond" | A **loose** `mysqld.exe` (from Laragon, or from an old manual start) is holding the data directory; MySQL will not open it twice. In an **admin** shell: `Stop-Process -Name mysqld -Force`, then `Start-Service QRIVOMySQL`. Check first with `Get-Service QRIVOMySQL` — if it already says `Running`, the loose process is not the problem. |
| **API or Teacher panel DOWN** | `Restart-Service QRIVOApache` (admin). Log: `deploy\windows\logs\apache-error.log`. |
| **Hotspot UNKNOWN** | The hotspot is off. Win+A → Mobile hotspot → on. |
| **Firewall red** | Run `deploy\windows\install-autostart.ps1` as Administrator. |
| Panel says **OUTSIDE_SCHEDULED_TIME** | You skipped step 2. Run `php scripts/seed.php`. |
| Panel says **"Sunucuya ulaşılamadı"** | You are on `:8080`. Use `http://127.0.0.1:8000/panel/`. |
| Phone cannot reach the server | Confirm it is on the hotspot, not mobile data. Test `http://192.168.137.1:8000/api/v1/health` in the phone's browser. |
| Phone says **"Your session expired"** | Normal after a long idle. Sign in again. |
| Scanner says **camera unavailable** | The Details line underneath names the cause. Reinstall from the APK URL above. |
| Everything is broken | The panel alone still shows the whole flow: start a session, mark a student manually, close it. |

---

## The internet path (optional, and now removed)

Not needed for the demo, and **gone**: the `QRIVO-Tunnel` scheduled task has been
deleted. It re-ran every five minutes and each run flashed a console window over
whatever was on screen, which is intolerable during a presentation.

`install-autostart.ps1` no longer creates it either — it now *deletes* it if an
older run left one behind — so re-running the installer cannot bring the popping
window back.

To get remote access again, it is opt-in in two places and neither is automatic:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\windows\install-tunnel-task.ps1
```

That re-registers the task (no administrator rights needed). For a single tunnel
without any scheduled task at all:

```powershell
.\start-qrivo.ps1 -Tunnel
```

Remove the task again with:

```powershell
schtasks /delete /tn "QRIVO-Tunnel" /f
```

Cloudflare quick tunnels proved unreliable: measured 2026-09-08, roughly one in
three never becomes reachable, and one that had served traffic for an hour was
withdrawn mid-session. That is why the hotspot is the primary path and this one
is off by default.

---
## Shutting down

```powershell
cd C:\Projects\QRIVO
.\stop-qrivo.ps1
```

Stopping a **service** needs an Administrator PowerShell, so from a normal shell
this reports what it cannot stop rather than killing the process. That is
deliberate: killing a service's process looks like a crash, and both services are
configured to restart themselves five seconds later, so the kill achieves
nothing except a MySQL crash-recovery on the next start.

You do not need to stop anything for a normal shutdown — just shut the laptop
down. The services come back at the next boot.
