# QRIVO — Project Summary

*Prepared 2026-09-08. Every number below was measured on the delivered system,
not estimated. Where something was not measured, it says so.*

---

## What the system does

QRIVO records university lecture attendance without letting students mark
themselves present.

A lecturer opens an attendance session for a scheduled lesson from a web panel.
The panel displays a QR code that **changes every 30 seconds**. A student scans
it with the QRIVO Android app; the server decides whether that scan counts and
records the result. The lecturer sees the roster update live, can override any
student's status manually with a written reason, and closes the session, at
which point everyone still unmarked becomes absent.

The design assumption is that a student will try to cheat: by photographing the
code and sending it to a friend, by replaying an old scan, by calling the API
directly, or by editing their own record. Each of those is addressed below.

---

## Architecture, in plain terms

Four parts:

| Part | Technology | Role |
| --- | --- | --- |
| **Backend API** | PHP 8.3, MySQL 8.4, Apache + mod_php | The only component that decides anything |
| **Teacher panel** | Static HTML/CSS/JavaScript, Bootstrap 5 | Displays the QR and the live roster |
| **Student app** | Flutter (Dart), Android | Camera, and a transport for scans |
| **Database** | MySQL 8.4, 33 tables | Attendance, identity, audit and security records |

The backend follows Clean Architecture: `Domain` (entities and rules, no
framework), `Application` (services), `Infrastructure` (database, logging),
`Presentation` (HTTP). Dependencies point inward, so the attendance rules do not
know they are behind a web server.

**The clients hold no authority.** Neither the panel nor the app decides whether
attendance is valid, whether a teacher may open a session, or what a student may
see. They send requests and render the answer. The phone app's only local logic
is checking that a scanned barcode starts with `qrivo.` — purely so scanning a
supermarket barcode shows "not a QRIVO code" instead of making three pointless
API calls. Anything past that check is still fully re-validated server-side.

For the demonstration the laptop runs a Wi-Fi hotspot and the phone joins it. No
internet is involved: the phone finds the laptop at a fixed private address by
itself, and nothing is typed into the app.

---

## The attendance algorithm

A QR code carries `qrivo.v1.<session-uuid>.<timestamp>.<nonce>.<signature>`. The
signature is HMAC-SHA256 over the rest, keyed by a **per-session secret that is
generated server-side and never appears in any API response**.

Scanning it does not record attendance. It starts a challenge-response exchange:

1. The app sends the scanned string. The server checks the signature, the age of
   the code, and that the session is still open.
2. The server issues a single-use challenge with its own nonce and short expiry.
3. The app returns the challenge. The server then checks enrolment, duplicates,
   rate limits, device consistency and a risk score before writing a record.

The record is written inside one database transaction, protected by a unique
constraint on `(attendance_session_id, student_id)`, so two simultaneous
submissions cannot produce two rows.

---

## The security model

### What it does guarantee

- **A student cannot mark themselves present.** There is no endpoint that lets a
  student write an attendance status. Attempting the teacher endpoint returns
  **HTTP 403** — verified on the running system.
- **A photographed QR code expires.** Codes are valid for 30 seconds plus a
  small clock-skew allowance.
- **A code cannot be used twice.** The challenge is single-use, marked consumed
  inside the transaction that uses it, and there is a per-student nonce guard.
- **Codes cannot be forged** without the per-session secret, which never leaves
  the server.
- **Every mutation is attributable.** Manual overrides record who changed what,
  when, and the stated reason, in `audit_logs`.
- **Failures do not explain themselves.** A rejected scan returns a generic
  message; the specific reason goes only to `security_events`. An attacker
  cannot use the error text to learn which check failed.
- **Passwords are Argon2id.** Raw tokens are never stored — only SHA-256 hashes.

### What it does NOT guarantee — stated plainly

- **It cannot tell who is holding the phone.** A student who hands their unlocked
  phone to a friend in the room will be marked present. No QR system solves this;
  it would need biometrics or physical presence checking.
- **It does not verify location.** A student on a video call with someone in the
  room, scanning a code held to a camera within the 30-second window, would
  succeed. A location signal exists in the risk model but is deliberately inert:
  indoor GPS is accurate to 10–50 m, which cannot distinguish adjacent rooms,
  and collecting student location is personal data requiring a legal basis this
  project does not have. This was a deliberate decision, recorded as OQ-003.
- **The risk score flags, it does not block.** A high score marks attendance
  `PENDING_REVIEW` for a human to resolve.
- **On the demo hotspot, traffic is unencrypted HTTP.** This is a two-device
  private network with no router and no internet path. The app refuses cleartext
  to anything except a private address, and Android independently enforces the
  same rule. A real deployment must use HTTPS, and the system now **refuses to
  start** in production with permissive CORS.
- **Only the teacher web panel exists.** There is no administrator web interface;
  administrative operations are API-only.
- **The mobile app is Android-only in practice.** The iOS project exists but has
  never been built — no macOS was available.

---

## The numbers

| Measure | Value |
| --- | --- |
| Backend tests | **595 tests, 1560 assertions**, all passing |
| Mobile tests | **106 tests**, all passing; static analysis clean |
| End-to-end smoke test | **16 steps**, full attendance lifecycle, real HTTP against real MySQL |
| Database | 33 tables |
| API concurrency | 20 concurrent requests completed in **523 ms**; sequential median **105 ms** |
| Continuous integration | GitHub Actions, backend + mobile, green |

**On concurrency:** 20 requests in 523 ms against a 105 ms sequential median
proves genuine parallelism — serialised they would have taken roughly 2.1 s.

**Not measured:** a sustained multi-minute soak test under continuous load was
not run. The concurrency figure above is a burst measurement. Saying so is more
useful than implying an endurance result that does not exist.

### External verification

Reachability was never confirmed from the machine hosting the service, because a
request from the host can succeed for reasons a phone cannot share. Instead a
random token was written to a file and **independent third-party services** were
asked to fetch it. A token generated seconds earlier cannot be produced by a
cache or a guess. This method caught a tunnel that appeared healthy locally and
was unreachable from the internet.

---

## Defects found and fixed during development

These are worth recording because most were invisible to the test suite.

| Defect | How it was found | Consequence if shipped |
| --- | --- | --- |
| **`mobile_scanner` keep-rule wildcard** — the library's own ProGuard rules use `com.google.mlkit.*`, which matches only that package and not subpackages, so R8 renamed ML Kit's barcode classes | The camera failed with an obfuscated null-reference; the error code was surfaced in the UI to read it | The scanner never works in any release build |
| **UTC / MySQL clock divergence** — `APP_TIMEZONE` was read but `date_default_timezone_set()` was never called, so PHP ran on UTC while MySQL ran on local time, 3 hours apart | A lesson scheduled 12:41–16:41 was refused as outside its window | Attendance cannot be opened at the correct time |
| **`php -S` serialises requests** — PHP's built-in server handles one request at a time, and on Windows cannot do otherwise | Phone requests queued behind the panel's 3-second polling and timed out | Intermittent "could not reach server" during a live demo |
| **A health check that could not fail** — the tunnel check ran from the host, where the OS resolved the name locally, so it reported green while the phone could not connect | An external checker disagreed with it | False confidence; the exact opposite of what a check is for |
| **Reused SQL placeholders** — `:q` three times, `:tid` twice | Running against real MySQL | HTTP 500 on roster search; a teacher wrongly denied a report. Invisible to the tests, which run on SQLite, which tolerates it |
| **401 retry used the dead token** — the refreshed token was discarded and the old one re-sent | First execution of the mobile test suite | A student signed out mid-scan |
| **Sticky client config** — the panel trusted a remembered API address over one that demonstrably answered | The panel failed while a healthy API ran on the same machine | Panel unusable after any address change |

Two themes: **a test suite that runs on a different database than production
will miss whole classes of defect**, and **a check that cannot fail is worse
than no check**.

---

## Honest limitations

- Built and demonstrated on **one laptop**. Not deployed to a server, and not
  load-tested beyond the burst above.
- The **dispute / absence-request** module described in the gap report was
  designed but **not implemented**. `docs/OPEN_QUESTIONS.md` records what remains
  open rather than implying completeness.
- Cloudflare quick tunnels were evaluated for remote access and **rejected as
  unreliable**: roughly one in three never became reachable, and one that had
  served traffic for an hour was withdrawn mid-session. The demonstration
  therefore uses the laptop hotspot, which depends on nothing external.
- The specification file was found to contain a live API token and was purged
  from git history; the token was revoked.
