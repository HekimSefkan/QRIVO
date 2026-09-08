# QRIVO — Jury Presentation Day

One page. Do these in order. **No internet is required for any of it.**

---

## 1. Start (or confirm) the system

```powershell
cd C:\Projects\QRIVO
.\start-qrivo.ps1
```

MySQL and Apache are Windows services and come up **at boot** on their own, so
usually this just confirms them.

---

## 2. Re-seed so the lesson is happening NOW

**Do this every demo day.** The demo lesson is centred on the moment the seeder
runs. If you seeded yesterday the window has passed and the panel will refuse to
start attendance with `OUTSIDE_SCHEDULED_TIME` — that is the eligibility control
working correctly, not a bug.

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

*"Tunnel" and "Reachable from outside" concern the optional internet path and are
irrelevant here — red on those does not affect the demo.*

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
| **MySQL will not start** — "the service did not respond" | A stray `mysqld.exe` is still holding the data directory. `Stop-Process -Name mysqld -Force`, then `Start-Service QRIVOMySQL`. |
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

## The internet path (optional, disabled)

Not needed for the demo, and **disabled** because its scheduled task popped a
console window every five minutes.

```powershell
schtasks /change /tn "QRIVO-Tunnel" /enable
```

```powershell
schtasks /change /tn "QRIVO-Tunnel" /disable
```

Both require an **Administrator** PowerShell.

Cloudflare quick tunnels proved unreliable: measured 2026-09-08, roughly one in
three never becomes reachable, and one that had served traffic for an hour was
withdrawn mid-session. That is why the hotspot is the primary path.

---

## Shutting down

```powershell
cd C:\Projects\QRIVO
.\stop-qrivo.ps1
```
