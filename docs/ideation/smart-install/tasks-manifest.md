# Tasks Manifest

**Project**: smart-install
**Created**: 2026-01-23
**Total Phases**: 3

## Quick Start

To begin implementation, start a fresh Claude session and run:

```bash
/execute-spec docs/ideation/smart-install/spec-phase-1.md
```

## Phases Overview

| Phase | Focus | Estimated Effort | Spec File |
|-------|-------|------------------|-----------|
| 1 | Detection Foundation & CLI Infrastructure | M (Medium) | spec-phase-1.md |
| 2 | Interactive Wizard & Component Selection | L (Large) | spec-phase-2.md |
| 3 | Migration Assistant & Comprehensive Testing | L (Large) | spec-phase-3.md |

## Phase Dependencies

```
Phase 1 ─────► Phase 2 ─────► Phase 3
(Detection)   (Wizard)       (Migration + Tests)
```

Each phase must be completed and tested before starting the next.

## Phase 1: Detection Foundation

**Files to create:**
- `src/Support/EnvironmentDetector.php`
- `src/Support/DetectionResult.php`
- `tests/Unit/EnvironmentDetectorTest.php`

**Files to modify:**
- `src/Commands/InstallCommand.php`
- `src/AuthKitServiceProvider.php`
- `tests/Feature/InstallCommandTest.php`

**Key deliverables:**
- `--mini` flag working
- `--force` flag enhanced
- Detection results displayed before install

**Validation:**
```bash
./vendor/bin/pest tests/Unit/EnvironmentDetectorTest.php
./vendor/bin/pest tests/Feature/InstallCommandTest.php
```

## Phase 2: Interactive Wizard

**Files to create:**
- `src/Install/WizardFlow.php`
- `src/Install/ComponentInstaller.php`
- `src/Install/RouteInstaller.php`
- `src/Install/AuthSystemInstaller.php`
- `src/Install/WebhookInstaller.php`
- `src/Install/LaravelWorkosMigrator.php`
- `src/Install/EnvManager.php`
- `tests/Unit/WizardFlowTest.php`
- `tests/Unit/EnvManagerTest.php`

**Files to modify:**
- `src/Commands/InstallCommand.php`
- `src/AuthKitServiceProvider.php`
- `tests/Feature/InstallCommandTest.php`

**Key deliverables:**
- Interactive wizard flow complete
- Component selection working
- laravel/workos migration options
- Env var management

**Validation:**
```bash
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature
```

## Phase 3: Migration Assistant & Testing

**Files to create:**
- `src/Install/MigrationPlanGenerator.php`
- `src/Install/Plans/MigrationPlan.php`
- `src/Install/Plans/BreezeMigrationPlan.php`
- `src/Install/Plans/JetstreamMigrationPlan.php`
- `src/Install/Plans/FortifyMigrationPlan.php`
- `tests/Unit/MigrationPlanGeneratorTest.php`
- `tests/Feature/WizardFlowTest.php`
- Test fixtures

**Files to modify:**
- `src/Install/WizardFlow.php`
- `src/Commands/InstallCommand.php`
- `tests/Feature/InstallCommandTest.php`

**Key deliverables:**
- Migration plans generated for Breeze/Jetstream/Fortify
- ≥80% test coverage
- All edge cases handled

**Validation:**
```bash
./vendor/bin/pest --coverage --min=80
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```

## Artifacts Summary

All ideation artifacts are in `docs/ideation/smart-install/`:

```
docs/ideation/smart-install/
├── contract.md           # Problem, goals, success criteria, scope
├── prd-phase-1.md        # Phase 1 requirements
├── prd-phase-2.md        # Phase 2 requirements
├── prd-phase-3.md        # Phase 3 requirements
├── spec-phase-1.md       # Phase 1 implementation spec
├── spec-phase-2.md       # Phase 2 implementation spec
├── spec-phase-3.md       # Phase 3 implementation spec
└── tasks-manifest.md     # This file
```

## Research Sources

The following sources informed this design:

- [Laravel Starter Kits Documentation](https://laravel.com/docs/12.x/starter-kits)
- [Laravel WorkOS Integration](https://laravel-news.com/getting-to-know-laravel-12-starter-kits)
- [laravel/workos GitHub Repository](https://github.com/laravel/workos)
- Current authkit-laravel codebase (`src/Commands/InstallCommand.php`)

## Notes for Implementation

1. **Env var naming**: Use `WORKOS_REDIRECT_URL` (Laravel convention) for new installs, but preserve existing `WORKOS_REDIRECT_URI` for backward compatibility.

2. **laravel/workos coexistence**: When detected, wizard offers three options: replace, augment, or keep both.

3. **No UI scaffolding**: WorkOS hosts the auth UI externally, so no views are needed.

4. **Migration plans**: Generated as markdown files in `storage/`, not executed automatically.

5. **Testing strategy**: Use test fixtures to simulate different project states (fresh, Breeze, Jetstream, etc.).

---

*Ideation complete. Ready for implementation.*
