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

## 3. Open the teacher panel — use THIS URL

```
http://127.0.0.1:8000/panel/
```

**Use this one, not `:8080`.** Here the panel is served by the API itself, so
they share an origin: no CORS, no cross-port anything, and no dependence on a
tunnel that may be down. `:8080` still works, but it is a separate origin and
therefore has more that can go wrong.

Sign in as the teacher, open the CENG201 / CENG-2A lesson, and press
**YOKLAMA BAŞLAT** to start attendance and display the QR.

> The panel now *probes* for a working API rather than trusting a remembered
> address: same origin, then the hotspot (`192.168.137.1:8000`), then the
> published tunnel, and only then the manual "Sunucu adresi" override. That
> order exists because a tunnel address saved during testing used to be sticky
> in `localStorage` and won over everything — so the panel kept calling a dead
> hostname and said "Sunucuya ulaşılamadı" while a healthy API sat on the same
> machine.

## 4. The phone — the hotspot path (PRIMARY, needs no internet)

This is the route to use in front of the jury. It does not touch the internet,
so nothing external can fail during your demo.

1. On the laptop: **Win+A → Mobile hotspot → on.**
   Windows always puts the laptop at `192.168.137.1`.
2. On the phone: **turn Wi-Fi on and join that hotspot.**
3. Open QRIVO. It probes `192.168.137.1:8000` first, finds the laptop, and uses
   it. **You never type an address.**

If the hotspot is off, or the phone is not on it, the app falls back to the
published tunnel address automatically — so the same build works both ways.

### Why this is the primary path

Cloudflare quick tunnels proved unreliable: measured on 2026-09-08, roughly one
in three never becomes reachable (cloudflared prints a hostname, registers one
connection instead of four, and the name stays NXDOMAIN), and a tunnel that had
been serving for an hour was withdrawn mid-session. Cloudflare's own banner says
these account-less tunnels have **no uptime guarantee**. The hotspot depends on
none of that.

## Logins

| Role | E-mail | Password |
| --- | --- | --- |
| Teacher | `teacher1@qrivo.local` | `Test1234!` |
| Student | `student01@qrivo.local` | `Test1234!` |
| Admin | `superadmin@qrivo.local` | `Test1234!` |

Students `student01` … `student12` all work, same password.

**APK:** `C:\Users\hekim\Desktop\QRIVO.apk`
**Public API:** changes each restart — run `.\check-qrivo.ps1` to see it
**Panel:** `http://127.0.0.1:8000/panel/`

---

## If something is down

| Symptom | Do this |
| --- | --- |
| `check-qrivo.ps1` says **MySQL DOWN** | `.\start-qrivo.ps1`. If it still fails, open Laragon and press Start. |
| **API DOWN** | `.\start-qrivo.ps1`. If it still fails: `deploy\windows\logs\apache-error.log`. Usually port 8000 is taken — `netstat -ano \| findstr :8000`. |
| **Teacher panel DOWN** | Same Apache instance as the API; restart with `.\start-qrivo.ps1`. |
| **Tunnel DOWN** | `.\start-qrivo.ps1`. Check `deploy\windows\logs\cloudflared.log`. |
| **Reachable from outside** not green | Wait 20 s and re-run. If still red, test on the phone anyway — the external checker itself can be down. |
| Phone cannot see the laptop on the hotspot | Run `.\check-qrivo.ps1` — it must show **Hotspot UP** and **Firewall (8000 in) UP**. If the firewall line is red, run install-autostart.ps1 as Administrator. |
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
