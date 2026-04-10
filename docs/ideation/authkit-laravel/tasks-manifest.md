# Tasks Manifest

**Project:** authkit-laravel
**Created:** 2026-01-23

## Quick Start

To implement this project, start a fresh Claude session and run:

```bash
/execute-spec docs/ideation/authkit-laravel/spec-phase-1.md
```

## Phases

| Phase | Focus | Effort | Spec File | Dependencies |
|-------|-------|--------|-----------|--------------|
| 1 | Core Foundation | L | spec-phase-1.md | None |
| 2 | Authorization & Middleware | L | spec-phase-2.md | Phase 1 |
| 3 | Team Management | L | spec-phase-3.md | Phase 1, 2 |
| 4 | Audit Logging | M | spec-phase-4.md | Phase 1, 2 |
| 5 | Webhooks & Events | L | spec-phase-5.md | Phase 1, 2, 3 |
| 6 | Testing & Example App | L | spec-phase-6.md | All previous |

## Execution Order

```
Phase 1 (Core Foundation)
    ↓
Phase 2 (Authorization)
    ↓
    ├── Phase 3 (Teams) ←─┐
    │                     │
    └── Phase 4 (Audit) ──┤ (can run in parallel)
                          │
Phase 5 (Webhooks) ←──────┘
    ↓
Phase 6 (Testing & Example)
```

## Per-Phase Execution

### Phase 1: Core Foundation
```bash
# Start fresh session
/execute-spec docs/ideation/authkit-laravel/spec-phase-1.md

# When complete, verify:
composer install
./vendor/bin/pest
./vendor/bin/pint --test
```

### Phase 2: Authorization & Middleware
```bash
/execute-spec docs/ideation/authkit-laravel/spec-phase-2.md

# Verify auth flow works end-to-end
```

### Phase 3: Team Management
```bash
/execute-spec docs/ideation/authkit-laravel/spec-phase-3.md

# Test org switching and invitations
```

### Phase 4: Audit Logging
```bash
/execute-spec docs/ideation/authkit-laravel/spec-phase-4.md

# Verify audit events reach WorkOS API (or test with fake)
```

### Phase 5: Webhooks & Events
```bash
/execute-spec docs/ideation/authkit-laravel/spec-phase-5.md

# Test with ngrok or similar for local webhook testing
```

### Phase 6: Testing & Example App
```bash
/execute-spec docs/ideation/authkit-laravel/spec-phase-6.md

# Final coverage check
./vendor/bin/pest --coverage --min=90
```

## Artifacts Summary

```
docs/ideation/authkit-laravel/
├── contract.md              # Approved project contract
├── prd-phase-1.md           # Core Foundation requirements
├── prd-phase-2.md           # Authorization requirements
├── prd-phase-3.md           # Team Management requirements
├── prd-phase-4.md           # Audit Logging requirements
├── prd-phase-5.md           # Webhooks requirements
├── prd-phase-6.md           # Testing requirements
├── spec-phase-1.md          # Core Foundation implementation
├── spec-phase-2.md          # Authorization implementation
├── spec-phase-3.md          # Team Management implementation
├── spec-phase-4.md          # Audit Logging implementation
├── spec-phase-5.md          # Webhooks implementation
├── spec-phase-6.md          # Testing implementation
└── tasks-manifest.md        # This file
```

## Key Technical Decisions

1. **Session expiration from WorkOS** - No local session duration config; uses `expires_at` from WorkOS tokens
2. **Lazy SDK configuration** - WorkOS SDK only configured when first accessed
3. **Laravel auth primitives** - `Auth::user()`, `@auth` directives all work natively
4. **PR #69 ergonomics** - Facade and helper patterns preserved from existing PR
5. **Headless frontend** - No UI components; just data sharing for Inertia/Blade
6. **Pest testing** - Laravel's preferred test framework
7. **Feature flags** - Optional features (audit, webhooks) via config

## Repository Setup

Before starting Phase 1, create the repository:

```bash
# Create new repository
mkdir authkit-laravel && cd authkit-laravel
git init

# Or clone a Laravel package skeleton
composer create-project --prefer-dist spatie/package-skeleton-laravel authkit-laravel
cd authkit-laravel

# Rename/configure for WorkOS
# Then start Phase 1 execution
```
