# QRIVO Mobile (Student)

Flutter client for the QRIVO student experience. **Foundation phase** — this
build covers authentication, the student dashboard, profile, weekly schedule, and
attendance history. The QR attendance scanner is a later phase
(`PROJECT_SPECIFICATION §6.11`) and is intentionally absent here.

## Security posture

The backend is the sole authority. This app makes **no** security decisions:

- It never validates credentials, tokens, roles, or attendance rules locally — it
  only renders what the API returns and forwards what the user types.
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
    home/        bottom-nav shell
    dashboard/   greeting + today's classes + attendance summary
    schedule/    weekly schedule grouped by day
    history/     paginated attendance history
    profile/     account details + sign out
  widgets/       AsyncView (load / error / retry helper)
  app.dart       provider graph + auth-driven routing
  main.dart      entrypoint
```

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
| `core/student_api_test.dart` | typed parsing of the four student endpoints |
| `core/models_test.dart` | `fromJson` mapping and null-tolerance |
| `widget/login_screen_test.dart` | client-side validation is UX-only; errors surface |

> Note: this repository is developed on a machine without the Flutter SDK, so the
> Dart tests are authored but executed in CI / on a developer workstation. The
> backend student self-service endpoints they exercise are covered by the PHPUnit
> suite (`backend/tests/.../Student/`).
