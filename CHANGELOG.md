# Changelog

All notable changes to the QRIVO project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Conventional Commits](https://www.conventionalcommits.org/).

---

## [Unreleased]

### Added

- `docs/ARCHITECTURE_FREEZE.md` — frozen component catalogue with responsibilities, inputs, outputs, dependencies, and security boundaries for all 15 major components; lists 14 decisions that must not change during implementation
- `database/docs/ER_DIAGRAM.md` — complete Mermaid ER diagram for all 31 tables across 8 domain groups
- `database/docs/TABLES.md` — full column definitions, types, nullability, keys and indexes for all 31 tables
- `database/docs/RELATIONSHIPS.md` — complete cardinality documentation and attendance algorithm lookup path
- `database/docs/INDEXES.md` — all indexes with priority ratings and algorithm-backed justifications
- `database/docs/CONSTRAINTS.md` — 26 uniqueness constraints, 53 foreign keys with ON DELETE policies, ENUM values, transaction boundaries
- `database/docs/DATABASE_DECISIONS.md` — 13 explicit design decisions each tied to a specification requirement

### Changed

- `docs/ATTENDANCE_ALGORITHM.md` — added `PENDING_REVIEW` to attendance states table (OQ-001 resolution)
- `docs/OPEN_QUESTIONS.md` — OQ-001 resolved: `PENDING_REVIEW` is a full `attendance_records.status` value


