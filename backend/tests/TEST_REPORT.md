# QRIVO — Integration Validation Report (Phase 22)

**Date:** 2026-08-30
**Suite:** `backend/` — PHPUnit 11.5, PHP 8.3, SQLite in-memory schema mirroring
migrations `001`–`008` (production runs MySQL 8).
**Result:** ✅ **586 tests, 1505 assertions, 100% passing.**

```
$ vendor/bin/phpunit --no-coverage
OK (586 tests, 1505 assertions)
```

---

## 1. Scope

Two things were validated:

1. **The complete approved flow**, end to end, dispatched through the real HTTP
   `Router` at every step
   (`tests/Unit/Integration/FullFlowIntegrationTest.php`).
2. **Every security failure path** defined by `SECURITY_RULES.md` §12, also
   through the real `Router`
   (`tests/Unit/Integration/SecurityFailurePathsTest.php`).

Per-phase suites (Phases 5–21) remain green and are the fine-grained backing for
the integration tests below.

---

## 2. Gap found and fixed

### FIX-1 — Session close / cancel was not implemented (Phase 15 was deferred)

| | |
|---|---|
| **Root cause** | Phase 15 (`ATTENDANCE_ALGORITHM.md` §7) was skipped in the build sequence. `POST /api/v1/teacher/attendance/{id}/close` and `/cancel` did not exist, so the approved flow could not be walked past "Live attendance". |
| **Fix** | Implemented §7 exactly: `AttendanceSessionService::close()` / `cancel()`; `AttendanceSessionRepository::transitionStatus()` (an atomic `UPDATE … WHERE status = :from` guard — a second concurrent close/cancel changes 0 rows and is rejected without corrupting state, per §7 "Concurrent close requests must NOT corrupt session state"); `AttendanceRecordRepository::markRemainingWaiting()` (WAITING → `ABSENT` \| `PENDING_REVIEW` from `system_settings.attendance.close.waiting_default_status`, default `ABSENT` per OQ-001). Ownership is verified (IDOR → `IDOR_ATTEMPT` event); every close/cancel writes an audit log (`ATTENDANCE_SESSION_CLOSED` / `ATTENDANCE_SESSION_CANCELLED`); the whole transition runs in one DB transaction. Routes + `Teacher\AttendanceController::close/cancel` added, gated by `attendance.session.close` / `attendance.session.cancel`. Migration `008` seeds the new setting. **No schema change beyond the seed row.** |
| **Regression tests** | `tests/Unit/Application/Service/Attendance/SessionCloseServiceTest.php` (10 tests: ACTIVE→CLOSED, WAITING resolution + the `PENDING_REVIEW` setting, PRESENT records untouched, audit content, guarded double-close → 409, close-a-cancelled → 409, non-owner → 403 + `IDOR_ATTEMPT`, ACTIVE→CANCELLED leaves records untouched + reason audited, cancel-a-closed → 409, non-owner cancel → 403) plus the close step in `FullFlowIntegrationTest`. |
| **Security** | Not weakened. QR generation and challenge/verify already reject any non-`ACTIVE` session, so "QR validations become invalid" and "new attendance submissions blocked" (§7 steps 2–3) needed no change — confirmed by `FullFlowIntegrationTest` ("challenge after close must be rejected"). |

No other gap was found: **every security failure path is already correctly
rejected** by the per-phase implementations. There were no validation bypasses to
close and nothing was weakened.

---

## 3. Complete approved flow — step coverage

`FullFlowIntegrationTest::test_complete_approved_flow_end_to_end` (70 assertions,
real `Router`):

| # | Step | How it was exercised | ✓ |
|---|------|----------------------|---|
| 1 | **Authentication** | `POST /auth/login` for admin/teacher/2×student; `LOGIN_SUCCESS` audit written | ✅ |
| 2 | **Authorization** | student → `POST /admin/schools` ⇒ 403 + `UNAUTHORIZED_ACCESS`; every later step is permission-gated | ✅ |
| 3 | **Academic structure** | admin `POST` school→faculty→department→program→room→academic-year→academic-term→class, each ⇒ 201; `*_CREATED` audits | ✅ |
| 4 | **Course** | admin `POST /admin/courses` ⇒ 201; teacher & student profiles linked to their user accounts (`USER_ROLE_ATTACHED` audit) | ✅ |
| 5 | **Schedule** | `class-courses` → `teacher-courses` → `teacher-class-assignments` → `course-schedules` (slot covering *now*) → `student-class-assignments` ×2 (derives `student_courses`) | ✅ |
| 6 | **Attendance session** | `POST /teacher/attendance/start` ⇒ 201, `status=ACTIVE`, 2 × `WAITING` initialised, **`session_secret` absent from the response** (DD-002), `ATTENDANCE_SESSION_STARTED` audit | ✅ |
| 7 | **Dynamic QR** | `GET /teacher/attendance/{id}/qr` ⇒ 200, `qr_string` starts `qrivo.v1.`, secret never exposed | ✅ |
| 8 | **QR scan** | client action — the returned `qr_string` is fed to the next call | ✅ |
| 9 | **Challenge** | `POST /student/attendance/challenge {qr}` ⇒ 201 `{challenge_id, nonce, expires_at}` | ✅ |
| 10 | **Challenge response** | `POST /student/attendance/verify {challenge_id, nonce, qr}` ⇒ 200 | ✅ |
| 11 | **Security validation** | performed inside `verify`; the response is `status=PRESENT, source=QR` | ✅ |
| 12 | **Risk evaluation** | exactly one `risk_assessments` row written for the session | ✅ |
| 13 | **Attendance transaction** | `attendance_records` row → `PRESENT`; challenge `used_at` set; `ATTENDANCE_RECORDED` audit; **re-submitting the same challenge ⇒ 409** | ✅ |
| 14 | **Live attendance** | `GET /teacher/attendance/{id}/live` ⇒ counters `PRESENT=1, WAITING=1` | ✅ |
| 15 | **Manual attendance** | `PATCH …/student/{id2} {status:LATE, reason}` ⇒ 200, record `LATE`, `ATTENDANCE_STATUS_CHANGED` audit; **student `PATCH` ⇒ 403** (SECURITY_RULES §7) | ✅ |
| 16 | **Session closing** | `POST /teacher/attendance/{id}/close` ⇒ 200, `status=CLOSED`, no `WAITING` left, `ATTENDANCE_SESSION_CLOSED` audit; **post-close challenge ⇒ rejected**; **re-close / cancel ⇒ 409** | ✅ |
| 17 | **Reporting** | teacher course report (`present=1, late=1`, one session), admin institution report (`total_records=2`), student self-report (**only the caller's 1 record**), student-2 self-report (only *their* `LATE` record) | ✅ |

---

## 4. Security failure paths — `SECURITY_RULES.md` §12

`SecurityFailurePathsTest` (22 tests, 138 assertions, real `Router`). Every row
is a **rejection** test — the request must fail with the correct status and,
where the spec requires it, a `security_events` row.

### Authentication

| Path | Expectation | Test | ✓ |
|------|-------------|------|---|
| Valid login | 200 + token | (all `setUp` logins) | ✅ |
| Invalid login | 401, generic `Invalid credentials.`, **no `data`**, `LOGIN_FAILURE` event | `test_invalid_login_is_rejected_generically_and_logged` | ✅ |
| Login rate limit | 429 after the window threshold | `test_login_rate_limit_trips` | ✅ |
| Refresh-token reuse | 401 + `TOKEN_REUSE` event | `test_revoked_refresh_token_reuse_is_flagged` | ✅ |
| Bogus / expired bearer | 401 | `test_expired_or_bogus_bearer_is_rejected` | ✅ |
| Token after logout | 401 | `test_token_is_dead_after_logout` | ✅ |

### Authorization — IDOR / BOLA / privilege escalation

| Path | Expectation | Test | ✓ |
|------|-------------|------|---|
| Student → admin / teacher endpoints | 403 + `UNAUTHORIZED_ACCESS` | `test_student_cannot_reach_admin_or_teacher_endpoints` | ✅ |
| Teacher A → Teacher B's session (view / live / qr / close) | 403 + `IDOR_ATTEMPT`, session unchanged | `test_teacher_cannot_touch_another_teachers_session_idor` | ✅ |
| Teacher starts session for an unassigned class | 403 + `UNAUTHORIZED_ATTENDANCE` | `test_teacher_cannot_start_a_session_for_an_unassigned_class` | ✅ |
| Student modifies own attendance | 403 | `FullFlowIntegrationTest` step 15 | ✅ |

### QR

| Path | Expectation | Test | ✓ |
|------|-------------|------|---|
| Valid QR | challenge issued | `FullFlowIntegrationTest` step 9 | ✅ |
| Malformed / fake QR | rejected, session unchanged | `test_malformed_qr_is_rejected` | ✅ |
| Tampered signature | rejected + `QR_INVALID` event | `test_tampered_qr_signature_is_rejected_and_logged` | ✅ |
| Expired QR (old timestamp, real signature) | rejected + `QR_EXPIRED` event | `test_expired_qr_is_rejected` | ✅ |
| QR nonce replay | 409 + `QR_REPLAY` event | `test_qr_nonce_replay_is_rejected` | ✅ |
| Duplicate scan | 409 + `DUPLICATE_ATTENDANCE` | `test_duplicate_attendance_is_rejected` | ✅ |

### Challenge

| Path | Expectation | Test | ✓ |
|------|-------------|------|---|
| Wrong challenge-response nonce | 401, record stays `WAITING` | `test_challenge_response_with_a_wrong_nonce_is_rejected` | ✅ |
| Challenge redeemed by a different student | 403 | `test_a_challenge_cannot_be_used_by_another_student` | ✅ |
| Reusing a used challenge | 409 | `FullFlowIntegrationTest` step 13 | ✅ |

### Attendance

| Path | Expectation | Test | ✓ |
|------|-------------|------|---|
| Valid | `PRESENT` | `FullFlowIntegrationTest` | ✅ |
| Duplicate | 409 | `test_duplicate_attendance_is_rejected` | ✅ |
| Wrong course/class (non-enrolled student) | 403/404 + `UNAUTHORIZED_ATTENDANCE` | `test_a_non_enrolled_student_cannot_get_a_challenge` | ✅ |
| Manual op on a non-member student | 404 + `UNAUTHORIZED_ATTENDANCE` | `test_manual_attendance_on_a_non_member_student_is_rejected` | ✅ |
| Manual op on a `CANCELLED` session | 409 | `test_manual_attendance_on_a_cancelled_session_is_rejected` | ✅ |
| Close / cancel | see FIX-1 regression tests | `SessionCloseServiceTest` | ✅ |

### Security — brute force / injection / concurrency

| Path | Expectation | Test | ✓ |
|------|-------------|------|---|
| Brute force / rate-limit abuse (login) | 429 | `test_login_rate_limit_trips` | ✅ |
| Rate-limit abuse (challenge) | 429 after the per-(student,session) window | `test_challenge_rate_limit_abuse_is_throttled` | ✅ |
| SQL injection in a filter param | 422 (rejected as a bad integer — never executed); tables intact | `test_sql_injection_in_a_report_filter_is_neutralised` | ✅ |
| XSS payload in a name field | stored verbatim as data, not executed, no extra row | `test_xss_payload_in_a_name_is_stored_verbatim_not_executed` | ✅ |
| CSRF | N/A — stateless bearer-token API, no cookies/sessions; every mutating call requires the `Authorization` header (covered implicitly by the 401 tests) | — | ✅ |
| Concurrent duplicate session start | one `ACTIVE` session; the second ⇒ 409 (class-row lock + guarded active-session check) | `test_concurrent_duplicate_session_start_yields_one_active` | ✅ |
| Concurrent close | guarded `UPDATE … WHERE status='ACTIVE'`; the loser ⇒ 409, no corruption | `SessionCloseServiceTest::test_close_is_idempotent_guarded_second_call_conflicts` | ✅ |

---

## 5. Assurances

- **No security control was weakened.** The only code change to an existing
  control path was making the (already-rejecting) non-`ACTIVE`-session checks
  reachable from a real close/cancel endpoint.
- **No validation step was bypassed.** The challenge-response pipeline still runs
  all of `ATTENDANCE_ALGORITHM.md` §4 in order; session creation still runs §2;
  manual attendance still runs §6; close/cancel runs §7.
- **`ORIGINAL_SPECIFICATION.md` is unchanged.**
- Failure responses remain generic; `session_secret`, tokens and password hashes
  never appear in any response or log (Phase 20 `LogSanitizer` + DD-002).

---

## 6. How to reproduce

```bash
cd backend
"$PHP" vendor/bin/phpunit --no-coverage
# targeted:
"$PHP" vendor/bin/phpunit --no-coverage --filter "FullFlowIntegrationTest|SecurityFailurePathsTest|SessionCloseServiceTest"
```
