# Tasks Manifest

**Project:** authkit-laravel-enhancement
**Created:** 2026-01-23
**Status:** Ready for Implementation

## Quick Start

To begin implementation, start a fresh Claude Code session and run:

```bash
/execute-spec docs/ideation/authkit-laravel-enhancement/spec-phase-1.md
```

## Phases Overview

| Phase | Title | Effort | Spec File | Status |
|-------|-------|--------|-----------|--------|
| 1 | CI/CD Foundation | S | spec-phase-1.md | pending |
| 2 | Example App Foundation | L | spec-phase-2.md | blocked (by 1) |
| 3 | Authentication & Organizations | M | spec-phase-3.md | blocked (by 2) |
| 4 | Todo Features & Admin Portal | L | spec-phase-4.md | blocked (by 3) |
| 5 | Documentation & Polish | M | spec-phase-5.md | blocked (by 4) |

## Phase Dependencies

```
Phase 1 (CI/CD)
    ↓
Phase 2 (App Foundation)
    ↓
Phase 3 (Auth & Orgs)
    ↓
Phase 4 (Todos & Admin Portal)
    ↓
Phase 5 (Docs & Polish)
```

## Implementation Order

### Phase 1: CI/CD Foundation
**Command:** `/execute-spec docs/ideation/authkit-laravel-enhancement/spec-phase-1.md`

Creates:
- `.github/workflows/ci.yml`
- `.github/workflows/release.yml`
- `CHANGELOG.md`
- Updated `.gitattributes`

**Validation:**
```bash
# Verify files created
ls -la .github/workflows/
cat CHANGELOG.md
```

### Phase 2: Example App Foundation
**Command:** `/execute-spec docs/ideation/authkit-laravel-enhancement/spec-phase-2.md`

Creates:
- Complete Laravel 12 app in `workbench/`
- Livewire + Flux Pro setup
- SQLite database configuration
- Base layouts and models
- Composer scripts

**Validation:**
```bash
composer serve
# Visit http://localhost:8000
composer fresh
```

### Phase 3: Authentication & Organizations
**Command:** `/execute-spec docs/ideation/authkit-laravel-enhancement/spec-phase-3.md`

Creates:
- Login page with WorkOS button
- Dashboard page
- Organization switcher component
- Organization settings page
- Current organization middleware

**Validation:**
```bash
composer serve
# Sign in via WorkOS
# Switch organizations
# View org settings
```

### Phase 4: Todo Features & Admin Portal
**Command:** `/execute-spec docs/ideation/authkit-laravel-enhancement/spec-phase-4.md`

Creates:
- Todo CRUD Livewire components
- Admin Portal link generation
- All 6 Admin Portal intents
- Audit logging integration

**Validation:**
```bash
composer serve
# Create, complete, delete todos
# Click Admin Portal links
# Verify audit logs
```

### Phase 5: Documentation & Polish
**Command:** `/execute-spec docs/ideation/authkit-laravel-enhancement/spec-phase-5.md`

Creates:
- `.github/README.md` (comprehensive docs)
- Example app tests
- Model factories
- UI polish (loading states)

**Validation:**
```bash
composer test
composer test:example
composer format && composer analyse
# Check README on GitHub
```

## Validation Commands (All Phases)

After completing all phases, run:

```bash
# Package tests
composer test

# Static analysis
composer analyse

# Code formatting
composer format

# Example app tests
composer test:example

# Start example app
composer serve

# Reset example database
composer fresh
```

## Artifacts Summary

```
docs/ideation/authkit-laravel-enhancement/
├── contract.md           # Project contract
├── prd-phase-1.md        # CI/CD requirements
├── prd-phase-2.md        # App foundation requirements
├── prd-phase-3.md        # Auth & organizations requirements
├── prd-phase-4.md        # Todo & Admin Portal requirements
├── prd-phase-5.md        # Documentation requirements
├── spec-phase-1.md       # CI/CD implementation spec
├── spec-phase-2.md       # App foundation implementation spec
├── spec-phase-3.md       # Auth implementation spec
├── spec-phase-4.md       # Todo implementation spec
├── spec-phase-5.md       # Documentation implementation spec
└── tasks-manifest.md     # This file
```

## Notes

- Each phase should be implemented in a fresh Claude Code session
- Commit after each phase completes validation
- Use semantic PR labels (feature, fix, etc.) to trigger releases
- The example app requires WorkOS credentials for full testing
