# QRIVO — Jury Presentation Day

One page. Do these in order.

---

## Before you leave the house

```powershell
cd C:\Projects\QRIVO
.\start-qrivo.ps1
```

Starts MySQL, Apache (API + panel) and the Cloudflare tunnel, publishes the
new public address, and waits until each part actually answers before printing
**QRIVO IS READY**.

If autostart is installed (`deploy\windows\install-autostart.ps1`, run once as
Administrator), MySQL and Apache are already up at boot and this script just
confirms them and starts the tunnel.

---

## 1. Re-seed so the lesson is happening *now*

**Do this every demo day.** The demo lesson is scheduled around the moment the
seeder runs; if you seeded yesterday, the window has passed and the teacher
panel will refuse to start attendance with `OUTSIDE_SCHEDULED_TIME`.

```powershell
cd C:\Projects\QRIVO\backend
php scripts/seed.php
```

It is idempotent — it will say `rows inserted: 0` and simply re-centre the
lesson. The last lines print the window; check it covers the current time.

---

## 2. Verify everything with one command

```powershell
cd C:\Projects\QRIVO
.\check-qrivo.ps1
```

Six lines, green or red. **The "Reachable from outside" line only goes green when
an independent third-party service fetches a nonce written seconds earlier** — a
request from this laptop is never accepted as proof, because that is exactly the
mistake that once showed green while the phone could not connect.

---

## 3. Open the teacher panel — locally

```
http://127.0.0.1:8080
```

**On this laptop only** — the panel does not go through the tunnel at all. Sign in
as the teacher, open the lesson, and press **YOKLAMA BAŞLAT** to start attendance
and display the QR.

---

## 4. The phone

The app discovers the current address by itself. Turn **Wi-Fi off** so it is
genuinely on mobile data, sign in as a student, and scan the QR on your screen.

---

## Logins

| Role | E-mail | Password |
| --- | --- | --- |
| Teacher | `teacher1@qrivo.local` | `Test1234!` |
| Student | `student01@qrivo.local` | `Test1234!` |
| Admin | `superadmin@qrivo.local` | `Test1234!` |

Students `student01` … `student12` all work, same password.

**APK:** `C:\Users\hekim\Desktop\QRIVO.apk`
**Public API:** changes each restart — run `.\check-qrivo.ps1` to see it
**Panel:** `http://127.0.0.1:8080`

---

## If something is down

| Symptom | Do this |
| --- | --- |
| `check-qrivo.ps1` says **MySQL DOWN** | `.\start-qrivo.ps1`. If it still fails, open Laragon and press Start. |
| **API DOWN** | `.\start-qrivo.ps1`. If it still fails: `deploy\windows\logs\apache-error.log`. Usually port 8000 is taken — `netstat -ano \| findstr :8000`. |
| **Teacher panel DOWN** | Same Apache instance as the API; restart with `.\start-qrivo.ps1`. |
| **Tunnel DOWN** | `.\start-qrivo.ps1`. Check `deploy\windows\logs\cloudflared.log`. |
| **Reachable from outside** not green | Wait 20 s and re-run. If still red, test on the phone anyway — the external checker itself can be down. |
| Panel says **OUTSIDE_SCHEDULED_TIME** | You forgot step 1. Run `php scripts/seed.php`. |
| Phone says **"Could not reach the server"** | Run `.check-qrivo.ps1`. If everything is green, the app will re-read the address by itself within a few seconds — pull to refresh. |
| Phone says **"Your session expired"** | Normal after a long idle. Sign in again. |
| Everything is broken and the jury is waiting | Put the phone on the laptop's Wi-Fi hotspot and demo against `http://<laptop-LAN-IP>:8000`. The API listens on all interfaces. |

---

## How the phone finds the laptop

The tunnel address changes every restart, so it is **not** baked into the app.
`start-qrivo.ps1` publishes the current address to a fixed public document, and
the app reads it at launch and re-reads it whenever a request fails. **You never
rebuild the APK.**

    https://raw.githubusercontent.com/HekimSefkan/QRIVO/endpoint/endpoint.json

If the phone says it cannot reach the server, run `.\check-qrivo.ps1` — the
"Published address" line shows exactly what the phone will read.

## Shutting down afterwards

```powershell
cd C:\Projects\QRIVO
.\stop-qrivo.ps1
```
