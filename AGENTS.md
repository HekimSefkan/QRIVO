# QRIVO — AI Agent Development Rules

> **This file is the mandatory instruction set for every AI coding agent working on QRIVO.**
> All agents must read and follow these rules before performing any task.

---

## 1. Source of Truth

The following documents are authoritative:

- [`docs/PROJECT_SPECIFICATION.md`](file:///c:/Projects/QRIVO/docs/PROJECT_SPECIFICATION.md)
- [`docs/ARCHITECTURE_RULES.md`](file:///c:/Projects/QRIVO/docs/ARCHITECTURE_RULES.md)
- [`docs/ATTENDANCE_ALGORITHM.md`](file:///c:/Projects/QRIVO/docs/ATTENDANCE_ALGORITHM.md)
- [`docs/SECURITY_RULES.md`](file:///c:/Projects/QRIVO/docs/SECURITY_RULES.md)

The original user-provided algorithm and architecture have priority.

**Never silently replace them.**

---

## 2. Architecture Protection

**Do not:**

- Replace the approved architecture
- Introduce a different architectural pattern without approval
- Remove required modules
- Merge unrelated modules
- Move responsibilities between layers without justification

**If an architectural change becomes necessary:**

1. Document the reason
2. Update `OPEN_QUESTIONS.md`
3. **Stop**
4. Request explicit approval

---

## 3. Algorithm Protection

The attendance algorithm must be implemented exactly according to:

[`docs/ATTENDANCE_ALGORITHM.md`](file:///c:/Projects/QRIVO/docs/ATTENDANCE_ALGORITHM.md)

**Do not:**

- Simplify the algorithm
- Remove validation steps
- Bypass challenge-response
- Bypass replay protection
- Bypass authorization
- Bypass risk evaluation
- Move security decisions only to the client

---

## 4. Security

**Security must be enforced server-side.**

**Never trust:**

- Client-side authorization
- Client-side attendance status
- Client-provided role
- Client-provided permissions
- Client-provided session ownership

**Never store inside source control:**

- Plaintext passwords
- API secrets
- Private keys
- Database passwords
- Authentication secrets

---

## 5. Development Process

**Before every task:**

1. Read relevant documentation
2. Inspect existing code
3. Inspect Git status
4. Identify affected modules
5. Identify required tests

**After every task:**

1. Implement
2. Test
3. Fix failures
4. Review git diff
5. Inspect changed files
6. Inspect for secrets
7. Update documentation
8. Update `CHANGELOG.md`
9. Commit
10. Push to GitHub

---

## 6. Testing

- Do not consider a feature complete until its relevant tests pass.
- Do not disable tests to make a build pass.
- Do not weaken security to make tests pass.

---

## 7. Git

**Use Conventional Commits.**

Allowed prefixes:

| Prefix       | Usage                        |
|--------------|------------------------------|
| `feat:`      | New feature                  |
| `fix:`       | Bug fix                      |
| `refactor:`  | Code refactoring             |
| `test:`      | Adding or updating tests     |
| `docs:`      | Documentation changes        |
| `chore:`     | Maintenance tasks            |
| `security:`  | Security-related changes     |

**Rules:**

- Never use force push
- Never rewrite Git history
- Never commit secrets

---

## 8. GitHub

Every completed development phase must be pushed to GitHub.

**A phase is not complete until:**

- Tests pass
- Commit exists
- Push succeeds

---

## 9. Changelog

Every significant development phase must update:

[`CHANGELOG.md`](file:///c:/Projects/QRIVO/CHANGELOG.md)

---

## 10. AI Agent Behavior

- Do not make large unrelated changes
- Do not refactor working modules without a reason
- Do not silently modify architecture
- Do not silently modify algorithms
- Do not assume missing requirements

**When uncertain:**

> **STOP AND DOCUMENT THE QUESTION.**
