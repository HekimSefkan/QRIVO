# QRIVO — Jury Presentation Day

One page. Do these in order.

---

## Before you leave the house

```powershell
cd C:\Projects\QRIVO
.\start-qrivo.ps1
```

Starts MySQL, Apache (API + panel) and the ngrok tunnel, and waits until each
one actually answers before printing **QRIVO IS READY**.

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

Five lines, green or red. **The "Public URL" line only goes green when an
independent third-party service fetches a nonce written seconds earlier** — a
request from this laptop is never accepted as proof, because that is exactly the
mistake that once showed green while the phone could not connect.

---

## 3. Open the teacher panel — locally

```
http://127.0.0.1:8080
```

**On this laptop only.** The panel does not go through the tunnel, so there is no
ngrok interstitial to click past. Sign in as the teacher, open the lesson, and
press **YOKLAMA BAŞLAT** to start attendance and display the QR.

---

## 4. The phone

The app is already built against the public URL. Turn **Wi-Fi off** so it is
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
**Public API:** `https://fanatic-blitz-eastbound.ngrok-free.dev`
**Panel:** `http://127.0.0.1:8080`

---

## If something is down

| Symptom | Do this |
| --- | --- |
| `check-qrivo.ps1` says **MySQL DOWN** | `.\start-qrivo.ps1`. If it still fails, open Laragon and press Start. |
| **API DOWN** | `.\start-qrivo.ps1`. If it still fails: `deploy\windows\logs\apache-error.log`. Usually port 8000 is taken — `netstat -ano \| findstr :8000`. |
| **Teacher panel DOWN** | Same Apache instance as the API; restart with `.\start-qrivo.ps1`. |
| **ngrok agent DOWN**, dies instantly | Windows Defender is blocking it. See the box below. |
| **Public URL** not green but agent is running | Wait 20 s and re-run. If still red, test on the phone anyway — the external checker itself can be down. |
| Panel says **OUTSIDE_SCHEDULED_TIME** | You forgot step 1. Run `php scripts/seed.php`. |
| Phone says **"Could not reach the server"** | Check `check-qrivo.ps1` first. If Public URL is green, turn the phone's Wi-Fi fully off and on. |
| Phone says **"Your session expired"** | Normal after a long idle. Sign in again. |
| Everything is broken and the jury is waiting | Put the phone on the laptop's Wi-Fi hotspot and demo against `http://<laptop-LAN-IP>:8000`. The API listens on all interfaces. |

---

## ⚠ ngrok and Windows Defender

Defender on this machine flags ngrok as `Trojan:Win32/Kepavll!rfn` and refuses to
run it — including the binary downloaded straight from ngrok's official CDN. The
`!rfn` suffix marks a machine-learning heuristic rather than a signature match,
which is the usual shape of a false positive on tunnelling tools. That is
evidence, not proof.

**Nothing was excluded from Defender on your behalf.** If you accept the risk,
run once as Administrator:

```powershell
powershell -ExecutionPolicy Bypass -File C:\Projects\QRIVO\deploy\windows\install-autostart.ps1 -AllowNgrok
```

To undo it later:

```powershell
Remove-MpPreference -ExclusionPath 'C:\Tools\ngrok\ngrok.exe'
```

**Without this, the phone cannot reach the API over the internet** and you must
fall back to the laptop-hotspot row in the table above.

---

## Shutting down afterwards

```powershell
cd C:\Projects\QRIVO
.\stop-qrivo.ps1
```
