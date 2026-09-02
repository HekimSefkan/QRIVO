# QRIVO — Specification Gap Report

**Phase:** 28
**Date:** 2026-09-02
**Question asked:** are `devices`, `notifications`, `ip_logs`, `location_logs`
and an attendance dispute / absence-request flow missing from the schema, and
should they be built?

**Short answer:** one of the five is a genuine gap. Three are not in the
specification at all, and two of those are already covered by existing columns.
The fifth — the dispute flow — is not a gap but a **new feature**, because the
section it was attributed to does not exist.

---

## 1. Method (reproducible)

`ORIGINAL_SPECIFICATION.md` is a saved ChatGPT HTML page, not Markdown, so it
must be stripped before it can be searched:

```bash
sed 's/<[^>]*>/\n/g' ORIGINAL_SPECIFICATION.md | grep -v '^[[:space:]]*$' > spec.txt
```

That yields 3,529 lines of prose. Every claim below cites a line number in that
extraction. Schema claims are read from `database/migrations/*.sql` directly
rather than from `TABLES.md`, so this report reflects what actually exists.

> **Caveat on line 3518.** One line of the extraction is the saved page's own
> session-state JSON. It is page furniture, not specification text, and is
> excluded from every count below. See **Appendix A** — it also contains a live
> credential and needs separate action.

---

## 2. Correction: the cited section numbers do not exist

The brief cited "sections 4, 5, 22, 23, 24, 31, 43". The specification's phase
list (spec.txt lines 995–1047) runs **1 to 28 and stops**:

| Cited | What is actually there |
| --- | --- |
| 4 | Database Design |
| 5 | Database Migrations |
| 22 | Device Session |
| 23 | Risk Scoring |
| 24 | Security Events |
| **31** | **does not exist — the list ends at 28 (Deployment)** |
| **43** | **does not exist** |

```bash
grep -nE "^(31|43)[.):]? " spec.txt
```

returns no matches. So "the attendance dispute / absence request flow from
section 31" cannot be recovered from the specification, because there is no
section 31. Section 43 is likewise absent. This matters for §4.5.

---

## 3. The specification's own entity list

The authoritative enumeration is at spec.txt lines 1435–1459. It names 24
entities and closes with two binding instructions:

> **"Do not invent unnecessary entities."** (line 1459)
> **"Implement only entities defined by the approved specification."** (line 2900)

That list contains **`notifications`** and **`device sessions`**. It does **not**
contain `devices`, `ip_logs`, or `location_logs`.

The schema currently has **33 tables** (31 domain, plus `schema_migrations` and
`qr_used_nonces`), covering every entity on that list **except `notifications`**.

---

## 4. Findings

| # | Item | Verdict | Recommendation |
| --- | --- | --- | --- |
| 1 | `notifications` | **Genuine gap — required** | **Build** |
| 2 | `devices` | Not in specification | Do not build |
| 3 | `ip_logs` | Not in specification; already covered | Do not build |
| 4 | `location_logs` | Not in specification; blocked by OQ-003 | Do not build |
| 5 | Dispute / absence request | Not in specification — a new feature | Build only as a documented deviation |

### 4.1 `notifications` — genuine gap, recommend building

**Evidence it is required.** Named twice, independently:

- spec.txt line 1456 — `- notifications`, inside the entity list.
- spec.txt line 1043 — `26. Notifications`, a numbered development phase.

**Evidence it is missing.** No `notifications` table in any migration, and no
notification entity, repository, service, controller, route or permission
anywhere in `backend/src`.

**Verdict:** the only one of the five that the specification asks for and the
schema does not provide.

> **This overlaps OQ-002, which is still awaiting your answer.** The Phase 27
> brief recommended **in-app only** — no push, no e-mail — because that is the
> only variant with no external accounts, no secrets, and nothing that cannot be
> tested on this machine. Approving `notifications` here resolves OQ-002 on
> those terms; confirm explicitly and both close together.

### 4.2 `devices` — not in the specification

**Evidence.** Zero occurrences of `devices` in spec.txt outside the excluded
page-state line. The entity list says *"device sessions"*, which exists as
`device_sessions`.

**Coverage today.** `device_sessions` (migration `001`, lines 104–127) already
carries per-device identity: `device_fingerprint`, `device_name`, `ip_address`,
`user_agent`, plus `revoked_at` for explicit revocation. `AD-013` records that
the fingerprint is **server-derived** (`sha256(X-Device-Id | UA)`) and never
client-trusted.

**What a `devices` table would add.** Normalising device identity out of the
session row would let a user see "my three registered devices" and revoke one
across all of its sessions. That is a real product capability — but it is a
**feature request, not a specification gap**, and it runs directly into *"Do not
invent unnecessary entities."*

**Recommendation: do not build.** Revocation already works per session. If you
want per-device management, raise it as a new open question so it is recorded as
a deliberate addition rather than a gap fix.

### 4.3 `ip_logs` — not in the specification, and already covered

**Evidence it is not asked for.** Zero occurrences of `ip_logs` in spec.txt.

**Evidence it is already covered.** `ip_address VARCHAR(45)` is recorded at the
point of the event on four tables, all in migration `001`:

| Table | Line | Purpose |
| --- | --- | --- |
| `login_attempts` | 90 | every authentication attempt (`NOT NULL`) |
| `device_sessions` | 110 | the session's origin address |
| `security_events` | 137 | every security-relevant event |
| `audit_logs` | 161 | every mutating action |

`login_attempts` also carries the composite index `idx_la_ip_time (ip_address,
created_at)` — precisely the access pattern a separate IP-log table would exist
to serve.

**Recommendation: do not build.** A standalone `ip_logs` table would duplicate
data already captured beside the event that gives it meaning, and would create a
second, weaker source of truth for the same fact. An IP address is only
interesting attached to *what happened*.

### 4.4 `location_logs` — not in the specification, and blocked upstream

**Evidence.** Zero occurrences of `location_logs` in spec.txt.

**Blocked by an unanswered decision.** `LOCATION_MISMATCH` exists in the risk
catalogue but is data-gated and inert — nothing supplies it (AD-014). Whether
QRIVO collects location at all is **OQ-003, still awaiting your answer**. The
Phase 27 brief recommended **not collecting it**: indoor GPS is 10–50 m
accurate, so it cannot distinguish adjacent rooms, while student location is
personal data under GDPR/KVKK requiring a lawful basis, retention policy and
DPIA.

**Recommendation: do not build.** Creating the table first would stand the
decision on its head — a personal-data store with no collector, no consent model
and no retention rule. If OQ-003 is ever answered "collect", the table gets
designed *then*, alongside the lawful basis.

### 4.5 Attendance dispute / absence request — not a gap, a new feature

**Evidence there is nothing to recover.** Beyond the missing section 31, the
concept is absent from the specification's vocabulary entirely:

| Term | Occurrences |
| --- | --- |
| `itiraz` (TR: objection) | 0 |
| `talep` (TR: request) | 0 |
| `başvuru` (TR: application) | 0 |
| `dispute` / `appeal` / `objection` | 0 / 0 / 0 |
| `absence request` / `leave request` | 0 / 0 |

The one near-miss is `mazeret` / `EXCUSED` (lines 806, 822, 1860, 1878), but
those are the attendance **status** `EXCUSED`, already implemented as an
`attendance_records.status` value. They describe an outcome a teacher can set,
not a workflow a student can start. Every occurrence of "approve" in the
specification is the phrase *"approved architecture"*; there is no approval
workflow anywhere in it.

**This does not make the feature wrong.** The requirement as stated — *a student
may never change their own attendance status; they may only file a request that
a teacher or admin resolves; every resolution is audit logged* — is sound, and
it is exactly the right shape. It preserves the invariant that a student is
never the authority over their own record, which is the property the entire QR
design exists to protect.

**Recommendation: build it only on your explicit say-so, and record it as a
deviation.** It is a genuine extension beyond the approved specification, so it
needs a new **AD-019** stating that it was added by your decision rather than
recovered from the source — the same treatment `AD-018` received for the web
client. It must not be committed as "closing a spec gap", because it closes none.

If approved, the shape would be:

- `attendance_requests` — `student_id`, `attendance_record_id`,
  `requested_status`, `reason`, `status` (PENDING / APPROVED / REJECTED),
  `resolved_by_user_id`, `resolved_at`, `resolution_note`.
- **A student may only INSERT and read their own rows.** There is no path from a
  student to `attendance_records`. Resolution is the only thing that mutates
  attendance, and only a teacher or admin may call it.
- Teacher scope reuses the existing relationship guards
  (`teacher_class_assignments` / `teacher_courses`) rather than inventing a rule.
- Every resolution writes an `audit_logs` row. A student attempting to resolve
  gets `ForbiddenException` plus a security event, like every other denial.

---

## 5. Recommendation summary

**Build one:** `notifications`, in-app only — which also resolves OQ-002.

**Do not build three:** `devices`, `ip_logs`, `location_logs`. None is in the
specification; two are already covered by existing columns; one is blocked on an
unanswered privacy decision.

**Decide one:** the dispute flow. Not a gap but a new feature, worth building on
your say-so and recordable as AD-019.

Nothing in this report has been implemented. No migration, entity, repository,
service, controller or route was created.

---

## Appendix A — credential exposure in `ORIGINAL_SPECIFICATION.md`

Found while searching the file. Unrelated to the data model, recorded here so it
is not lost.

`ORIGINAL_SPECIFICATION.md` is a saved HTML page, and line 3518 of the
extraction is the page's own session-state JSON. It contains an OpenAI
**`accessToken`** for the repository owner's account, along with profile e-mail
and account identifiers.

- **Committed and pushed.** Introduced in `8b56c39`
  (`docs: synchronize QRIVO specification and architecture`) and present in every
  commit since, on `origin/main`.
- **Probably still live.** The embedded session declares
  `"expires":"2026-11-27"` — roughly three months after this report's date.

**Action required, by a human, in this order:**

1. **Revoke it.** Log out of all ChatGPT sessions at chatgpt.com. This
   invalidates the token and is the step that actually ends the exposure.
2. **Then** clean history. The file is in every commit from `8b56c39` onward, so
   removal requires `git filter-repo` (or BFG) plus a force-push, and anyone
   holding a clone must re-clone. Revoking first turns this from an emergency
   into housekeeping.
3. Consider replacing the saved HTML with a plain-text or Markdown extraction of
   the specification, so the page furniture — and whatever else is embedded in
   it — cannot come back.

This report does not reproduce the token.
