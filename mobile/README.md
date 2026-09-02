# QRIVO Mobile (Student)

Flutter client for the QRIVO student experience. Covers authentication, the
student dashboard, profile, weekly schedule, attendance history, and the
**QR attendance flow** (scan → challenge → verification → result).

## Security posture

The backend is the sole authority. This app makes **no** security decisions:

- It never validates credentials, tokens, roles, or attendance rules locally — it
  only renders what the API returns and forwards what the user types.
- The QR attendance flow (`ATTENDANCE_ALGORITHM.md §4`) runs entirely
  server-side: the camera decodes a barcode and the app POSTs the raw string to
  `/student/attendance/{qr/verify,challenge,verify}`. Expiry, HMAC signature,
  enrolment, replay, rate-limit, risk and duplicate checks are all the server's.
  The only local check is a `qrivo.` prefix sniff that avoids firing the API at
  an unrelated barcode — anything past it is still fully re-validated server-side.
- The only secret it persists is the token pair (`Session`), stored exclusively in
  the platform secure store (iOS Keychain / Android EncryptedSharedPreferences +
  Keystore) as a single JSON blob. Tokens are never logged.
- `AppConfig` holds no secrets — only the API base URL, injected at build time.

See `docs/SECURITY_RULES.md` and `docs/ATTENDANCE_ALGORITHM.md` in the repo root.

## Project layout

```
lib/
  core/
    config/      compile-time config (API base URL)
    api/         REST client, envelope unwrapping, typed student endpoints
    auth/        Session, secure store, auth repository, AuthController
    models/      User, StudentProfile, ScheduleEntry, AttendanceEntry
  features/
    auth/        login screen
    home/        bottom-nav shell + "Scan" action
    dashboard/   greeting + today's classes + attendance summary
    schedule/    weekly schedule grouped by day
    history/     paginated attendance history
    profile/     account details + sign out
    attendance/  QR scanner + pipeline controller + result sheet
  widgets/       AsyncView (load / error / retry helper)
  app.dart       provider graph + auth-driven routing
  main.dart      entrypoint
```

### QR attendance flow (`features/attendance/`)

`QrAttendanceController` (a plain `ChangeNotifier`, camera-free) runs the exact
algorithm order: `validating` (preflight `POST /qr/verify`) → `challenging`
(`POST /challenge`) → `verifying` (`POST /verify`, submitting the server's nonce
back) → `success` / `failure`. Every defined failure state
(`ATTENDANCE_ALGORITHM.md §4/§9`) maps to a `QrFailureKind` for presentation
only — the server's generic message is shown as-is. `qr_scanner_screen.dart`
owns the camera (`mobile_scanner`); `attendance_result_sheet.dart` renders the
progress / result / failure panel.

## Prerequisites

- **Flutter SDK `>= 3.19`** (Dart `>= 3.3`). Verified on **Flutter 3.47.2 /
  Dart 3.13.2**.
- For Android: the Android SDK (Android Studio installs it) and a JDK 17.
- For iOS: macOS with Xcode. **The iOS target has never been built** — see
  "Platform support" below.
- A running QRIVO backend and a seeded database — see `docs/RUNBOOK.md`.

## First-time setup

`android/` and `ios/` **are committed** (since Phase 26), so there is no
`flutter create` step. Do not run `flutter create .` — it would overwrite the
manifest and Gradle configuration described below.

```bash
cd mobile
flutter pub get
```

### Platform support

| Target | Status |
| --- | --- |
| Android | Configured and **verified**: `flutter build apk` succeeds in both debug and release, and the merged manifests were inspected (release contains no cleartext config) |
| iOS | Configured, **never built** — no macOS/Xcode available to the authors |
| web / desktop | Not supported; those platform folders are not generated |

### Known issue — `mobile_scanner` and the Kotlin Gradle Plugin

The Android build prints:

> Your app uses the following plugins that apply Kotlin Gradle Plugin (KGP):
> `mobile_scanner`. Future versions of Flutter will fail to build if your app
> uses plugins that apply KGP.

This does **not** affect the current build (verified on Flutter 3.47.2). It is a
plugin-side migration to Built-in Kotlin and will need a `mobile_scanner`
upgrade before a future Flutter bump. Not done here: upgrading the scanner
touches the attendance capture path, which is out of scope for this phase.

### Camera permission (already configured)

Nothing to do — this is committed. For reference:

- **Android** — `android/app/src/main/AndroidManifest.xml` declares
  `android.permission.CAMERA`, plus `camera` / `camera.autofocus` as
  `required="false"` so the app still installs on a camera-less device.
- **iOS** — `ios/Runner/Info.plist` sets `NSCameraUsageDescription`.

The camera is used only by the scanner screen, only while it is open, and no
frame leaves the device. The scanner handles a denied/unavailable camera
gracefully.

### Cleartext HTTP to the local API

The dev backend is plain HTTP, which both platforms block by default.

- **Android** — permitted in **debug builds only**. The config lives in the
  `debug` source set (`android/app/src/debug/res/xml/network_security_config.xml`)
  so it is physically absent from profile and release APKs. Do not move it into
  `src/main/`.
- **iOS** — `NSAllowsLocalNetworking` in `Info.plist`. This relaxes ATS for
  local-network destinations only; it is *not* `NSAllowsArbitraryLoads`, so
  cleartext to public hosts stays blocked. Unlike Android it is not scoped to
  debug, because `Info.plist` is shared by all configurations (AD-011).

**Release builds must use HTTPS** (`--dart-define=API_BASE_URL=https://…`).

## Run

The API base URL is injected at build time with `--dart-define`. The app accepts
either `API_BASE_URL` or the older `QRIVO_API_BASE_URL` (the namespaced name wins
if both are given). Default: `http://10.0.2.2:8000`.

| Target | Value | Why |
| --- | --- | --- |
| Android emulator | `http://10.0.2.2:8000` | `10.0.2.2` is the emulator's alias for the host's loopback |
| iOS simulator | `http://127.0.0.1:8000` | the simulator shares the host's network stack |
| Physical device | `http://<LAN-IP>:8000` | e.g. `192.168.1.20` — device and host must be on the same network |

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

```bash
flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000
```

Find your LAN IP with `ipconfig` (Windows) or `ipconfig getifaddr en0` (macOS),
then:

```bash
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000
```

For a physical device, the backend must listen on all interfaces, not just
localhost:

```bash
php -S 0.0.0.0:8000 -t backend/public
```

## Test

```bash
flutter test
```

**67 tests, all passing** on Flutter 3.47.2; `flutter analyze` reports no issues.

Tests under `test/` use `package:http/testing.dart`'s `MockClient` and an
in-memory session store — no network, no platform channels:

| File | Covers |
| --- | --- |
| `core/session_test.dart` | token expiry / refresh-window logic, JSON round-trip |
| `core/api_client_test.dart` | bearer header, envelope unwrap, error mapping, 401→refresh→retry |
| `core/auth_repository_test.dart` | login / refresh / me / logout wire format |
| `core/auth_controller_test.dart` | bootstrap, sign in/out, single-flight refresh, session clearing |
| `core/student_api_test.dart` | typed parsing of the student endpoints |
| `core/models_test.dart` | `fromJson` mapping and null-tolerance |
| `core/attendance_models_test.dart` | QR preflight / challenge / result parsing |
| `features/qr_attendance_controller_test.dart` | full pipeline + every defined failure state |
| `features/attendance_result_sheet_test.dart` | progress / success / retryable-vs-final failure UI |
| `widget/login_screen_test.dart` | client-side validation is UX-only; errors surface |

These are unit/widget tests with no network and no platform channels. The camera
integration in `qr_scanner_screen.dart` cannot be covered this way — use the
manual script below.

---

## Manual test script (end-to-end)

Verifies the real attendance path against a real backend: scan succeeds once,
and the **same QR scanned again is refused**. Everything is decided server-side;
the app only transports and renders.

### Setup

1. **Backend + seed data** — follow `docs/RUNBOOK.md`:

   ```bash
   php backend/scripts/migrate.php
   ```

   ```bash
   php backend/scripts/seed.php
   ```

   For a physical device, start the API on all interfaces:

   ```bash
   php -S 0.0.0.0:8000 -t backend/public
   ```

2. **Web client** (this is what displays the QR) — from the repo root:

   ```bash
   php -S localhost:8080 -t web
   ```

3. **Teacher opens a session** — sign in at <http://localhost:8080> as
   `teacher1@qrivo.local`, and on the dashboard press **YOKLAMA BAŞLAT** for the
   lesson whose slot covers the current time. The live screen now shows a QR
   that refreshes every ~30 s. Leave it on screen.

4. **Run the app** against the same backend:

   ```bash
   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
   ```

   > An emulator cannot photograph your monitor. Either run on a **physical
   > device** pointed at the screen (use the LAN IP), or use the emulator's
   > **virtual scene camera** and inject the QR image.

### Script

| # | Step | Expected |
| --- | --- | --- |
| 1 | Sign in as a seeded student — `student01@qrivo.local`, password from `SEED_DEFAULT_PASSWORD` | Dashboard loads with the student's name and today's classes |
| 2 | Wrong password first | The API's own generic `Invalid credentials.` — no hint about which field, no lockout detail |
| 3 | Tap **Scan** | Camera preview opens; the OS permission prompt appears on first run showing the usage rationale |
| 4 | Point at any non-QRIVO barcode (e.g. a product code) | "Not a QRIVO code" — **no** API call is made (AD-012: local prefix sniff is a UX filter only) |
| 5 | Scan the teacher's QR | Sheet shows progress → **PRESENT**, with a Done button. Teacher's web screen flips this student to **VAR / QR** with a timestamp within ~3 s, and the counters increment |
| 6 | **Scan the same QR image again** (before it refreshes) | **Fails.** The server refuses the replay — the app shows the server's generic message, not a technical reason. The student's status on the teacher screen does **not** change |
| 7 | Let the QR refresh, then scan a QR you captured >30 s ago | Fails — expired |
| 8 | Teacher presses **YOKLAMAYI KAPAT**, then scan again | Fails — the session is no longer active |
| 9 | Check **History** in the app | The attendance appears once, as PRESENT |

Step 6 is the important one: it proves the replay protection
(`ATTENDANCE_ALGORITHM.md §10` — challenge single-use + per-student QR nonce)
is enforced by the backend and that the client neither caches nor re-decides a
verdict. A second **PRESENT** there would be a security defect.

> The failure messages in steps 6–8 are deliberately generic. The specific
> reason is recorded server-side in `security_events`; check there (or
> `backend/storage/logs/`) to confirm *which* guard fired.
