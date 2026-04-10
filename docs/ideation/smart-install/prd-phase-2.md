# PRD: Smart Install - Phase 2

**Contract**: ./contract.md
**Phase**: 2 of 3
**Focus**: Interactive Wizard & Component Selection

## Phase Overview

Phase 2 implements the interactive wizard that guides users through installation based on their detected environment. This is the default mode (no flags) and provides the most guidance for users unsure of what they need.

This phase builds on Phase 1's detection by using those results to present relevant options. For example, if laravel/workos is detected, the wizard asks whether to replace, augment, or keep it. If no existing auth is detected, it skips those questions entirely.

After this phase completes, users will have a fully guided installation experience that:
- Asks only relevant questions based on their environment
- Offers component selection (Routes, Full auth system, Webhooks)
- Handles laravel/workos coexistence decisions
- Auto-runs migrations (with visual confirmation of what will run)

## User Stories

1. As a developer new to authkit-laravel, I want an interactive wizard so that I can understand what I'm installing
2. As a developer who chose WorkOS during `laravel new`, I want the wizard to ask how to handle the existing setup so that I don't end up with conflicting configurations
3. As a developer who only needs certain features, I want to choose which components to install so that I don't have unnecessary code in my project

## Functional Requirements

### Wizard Flow

- **FR-2.1**: Default mode (no flags) enters interactive wizard
- **FR-2.2**: Wizard uses detection results from Phase 1 to determine which questions to ask
- **FR-2.3**: Wizard uses Laravel's console choice/confirm components for consistent UX
- **FR-2.4**: Wizard can be exited at any point with Ctrl+C without partial installation

### Component Selection

- **FR-2.5**: Offer "Routes only" component - just auth routes (login, callback, logout)
- **FR-2.6**: Offer "Full auth system" component - routes + guard + provider + User model guidance
- **FR-2.7**: Offer "Webhooks" component - webhook routes and handlers
- **FR-2.8**: Components can be multi-selected (e.g., Routes + Webhooks but not Full)
- **FR-2.9**: Display what each component includes before selection

### laravel/workos Handling

- **FR-2.10**: When laravel/workos detected, ask: "Replace entirely", "Augment/extend", or "Keep both"
- **FR-2.11**: "Replace entirely" removes laravel/workos from composer.json and migrates config
- **FR-2.12**: "Augment/extend" keeps laravel/workos and adds authkit-laravel features on top
- **FR-2.13**: "Keep both" installs authkit-laravel alongside without touching laravel/workos
- **FR-2.14**: Migrate config from `services.php` workos key to `config/workos.php` when replacing

### Environment Variable Handling

- **FR-2.15**: Detect existing `WORKOS_REDIRECT_URL` and preserve it (Laravel convention)
- **FR-2.16**: If `WORKOS_REDIRECT_URI` exists but not `WORKOS_REDIRECT_URL`, offer to rename
- **FR-2.17**: Never create duplicate env vars - use what exists or add what's missing
- **FR-2.18**: Show user what env vars will be added/modified before making changes

### Migration Handling

- **FR-2.19**: In wizard mode, auto-run migrations after confirmation
- **FR-2.20**: Show list of migrations that will run before executing
- **FR-2.21**: If user declines migrations, display `php artisan migrate` as next step

## Non-Functional Requirements

- **NFR-2.1**: Wizard should complete in under 30 seconds of active user time
- **NFR-2.2**: All wizard prompts should have sensible defaults (pressing Enter accepts default)
- **NFR-2.3**: Wizard should recover gracefully from invalid input (re-prompt, don't crash)
- **NFR-2.4**: Wizard state should not persist between runs (fresh start each time)

## Dependencies

### Prerequisites

- Phase 1 complete (EnvironmentDetector, DetectionResult, CLI flags)
- Understanding of Laravel console components (choice, confirm, table)

### Outputs for Next Phase

- Complete wizard flow implementation
- Component installation logic
- laravel/workos migration logic
- Env var management utilities

## Acceptance Criteria

- [ ] Running `php artisan workos:install` (no flags) enters interactive wizard
- [ ] Wizard detects laravel/workos and asks about relationship handling
- [ ] Selecting "Routes only" installs only auth routes, not webhooks or full system
- [ ] Selecting "Full auth system" configures guards, providers, and user model guidance
- [ ] Selecting "Webhooks" adds webhook routes and handlers
- [ ] Wizard shows what env vars will be added before adding them
- [ ] Wizard shows what migrations will run before running them
- [ ] "Replace entirely" for laravel/workos removes package and migrates config
- [ ] Existing WORKOS_REDIRECT_URL in .env is preserved
- [ ] Integration tests exist for wizard flow with mocked prompts

## Open Questions

- Should "Augment/extend" disable conflicting authkit-laravel features that overlap with laravel/workos?
- What happens if user chooses "Replace entirely" but has custom code depending on laravel/workos?

---

*Review this PRD and provide feedback before spec generation.*
