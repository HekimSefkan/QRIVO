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

- Flutter SDK `>= 3.19` (Dart `>= 3.3`)
- A running QRIVO backend (see `backend/README.md`)

## First-time setup

The generated platform folders (`android/`, `ios/`, …) are **not** committed —
they are regenerated locally:

```bash
cd mobile
flutter create .
flutter pub get
```

### Camera permission (for the QR scanner)

`flutter create .` writes default manifests; add the camera permission the
`mobile_scanner` plugin needs:

- **Android** — `android/app/src/main/AndroidManifest.xml`:
  `<uses-permission android:name="android.permission.CAMERA" />`
- **iOS** — `ios/Runner/Info.plist`:
  `NSCameraUsageDescription` = "QRIVO uses the camera to scan the attendance code."

The scanner screen already handles a denied/unavailable camera gracefully.

## Run

The API base URL is passed with `--dart-define`. Defaults to `http://10.0.2.2:8000`
(the Android emulator's route to the host).

```bash
flutter run --dart-define=QRIVO_API_BASE_URL=http://10.0.2.2:8000
```

For a physical device, use your machine's LAN address, e.g.
`--dart-define=QRIVO_API_BASE_URL=http://192.168.1.20:8000`.

## Test

```bash
flutter test
```

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

> Note: this repository is developed on a machine without the Flutter SDK, so the
> Dart tests are authored but executed in CI / on a developer workstation. The
> backend attendance endpoints they exercise are covered by the PHPUnit suite
> (`backend/tests/.../Attendance/`, `.../Student/`). The camera integration in
> `qr_scanner_screen.dart` should be smoke-tested on a device.
