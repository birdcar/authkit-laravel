# PRD: Smart Install - Phase 1

**Contract**: ./contract.md
**Phase**: 1 of 3
**Focus**: Detection Foundation & CLI Infrastructure

## Phase Overview

Phase 1 establishes the foundation for intelligent installation by building environment detection capabilities and CLI infrastructure. Before we can offer smart installation options, we need to understand what the user already has installed.

This phase is sequenced first because all subsequent wizard logic depends on knowing the user's starting point. Without detection, we can't offer appropriate migration paths or avoid creating duplicate configurations.

After this phase completes, the install command will:
- Detect existing auth setups (laravel/workos, Breeze, Jetstream, Fortify, or none)
- Support `--force` and `--mini` flags with appropriate behavior
- Report what was detected before taking any action

## User Stories

1. As a developer installing authkit-laravel, I want the installer to detect my existing auth setup so that it can provide appropriate guidance
2. As a developer who wants full control, I want a `--force` flag so that I can overwrite any existing configuration without prompts
3. As a developer who prefers manual setup, I want a `--mini` flag so that I can get just the config file and setup instructions

## Functional Requirements

### Environment Detection

- **FR-1.1**: Detect if `laravel/workos` package is installed via composer.json
- **FR-1.2**: Detect if WorkOS config exists in `config/services.php` (laravel/workos convention)
- **FR-1.3**: Detect if `config/workos.php` already exists (previous authkit-laravel install)
- **FR-1.4**: Detect existing `WORKOS_*` environment variables in `.env` file
- **FR-1.5**: Detect if Laravel Breeze is installed (check for `laravel/breeze` in composer.json)
- **FR-1.6**: Detect if Laravel Jetstream is installed (check for `laravel/jetstream` in composer.json)
- **FR-1.7**: Detect if Laravel Fortify is installed (check for `laravel/fortify` in composer.json)
- **FR-1.8**: Return a structured detection result object with all findings

### CLI Flags

- **FR-1.9**: Add `--mini` flag that publishes config only and displays setup instructions
- **FR-1.10**: `--mini` mode must NOT auto-run migrations
- **FR-1.11**: `--mini` mode displays comprehensive next-steps including env vars, guard setup, and migration command
- **FR-1.12**: `--force` flag overwrites existing `config/workos.php` without prompting
- **FR-1.13**: `--force` flag overwrites existing auth guard configuration without prompting
- **FR-1.14**: Flags can be combined: `--force --mini` publishes config overwriting existing

### Detection Reporting

- **FR-1.15**: Before any installation action, display what was detected to the user
- **FR-1.16**: Use Laravel's console components for consistent, readable output
- **FR-1.17**: Detection output should indicate severity (info for neutral findings, warning for potential conflicts)

## Non-Functional Requirements

- **NFR-1.1**: Detection must complete in under 500ms for typical Laravel projects
- **NFR-1.2**: Detection must not modify any files (read-only operation)
- **NFR-1.3**: Detection must gracefully handle missing files (e.g., no .env file)
- **NFR-1.4**: All detection logic should be unit-testable via dependency injection

## Dependencies

### Prerequisites

- Existing InstallCommand.php as starting point
- Understanding of laravel/workos package structure and conventions

### Outputs for Next Phase

- `EnvironmentDetector` class with detection methods
- `DetectionResult` value object containing all findings
- CLI flags infrastructure (`--force`, `--mini`)
- Refactored InstallCommand that uses detection results

## Acceptance Criteria

- [ ] `php artisan workos:install` on fresh Laravel app shows "No existing auth detected"
- [ ] `php artisan workos:install` on app with laravel/workos shows "Detected: laravel/workos package"
- [ ] `php artisan workos:install` on app with Breeze shows "Detected: Laravel Breeze"
- [ ] `php artisan workos:install --mini` only publishes config file and shows instructions
- [ ] `php artisan workos:install --mini` does NOT run migrations
- [ ] `php artisan workos:install --force` overwrites existing config without prompting
- [ ] Detection result includes env vars found (WORKOS_CLIENT_ID, WORKOS_API_KEY, WORKOS_REDIRECT_URL/URI)
- [ ] Unit tests exist for EnvironmentDetector with all scenarios
- [ ] All existing tests continue to pass

## Open Questions

- Should detection also check for `config/auth.php` modifications (existing workos guard)?
- Should we detect database migrations (users table has workos_id column)?

---

*Review this PRD and provide feedback before spec generation.*
