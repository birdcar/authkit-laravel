# PRD: Smart Install - Phase 3

**Contract**: ./contract.md
**Phase**: 3 of 3
**Focus**: Migration Assistant & Comprehensive Testing

## Phase Overview

Phase 3 completes the smart-install feature by adding the migration assistant for existing apps and ensuring comprehensive test coverage. This phase handles the most complex scenario: production apps migrating from Breeze, Jetstream, or Fortify to WorkOS AuthKit.

This phase is sequenced last because migration assistance requires the most careful handling and benefits from the stable foundation of Phases 1-2. It also allows us to ensure the happy path (fresh apps, starter kit users) is rock-solid before tackling migration complexity.

After this phase completes:
- Developers migrating from any Laravel auth system get actionable, project-specific guidance
- All installation scenarios have comprehensive test coverage
- Edge cases are handled gracefully with helpful error messages

## User Stories

1. As a developer migrating an existing Breeze app, I want the installer to tell me exactly what to change so that I don't break authentication for my users
2. As a developer with a Jetstream app, I want to understand what features I'll lose and gain so that I can make an informed decision
3. As a maintainer, I want comprehensive tests so that future changes don't break existing installation scenarios

## Functional Requirements

### Migration Detection

- **FR-3.1**: Detect Breeze by checking for `laravel/breeze` and presence of Breeze auth files
- **FR-3.2**: Detect Jetstream by checking for `laravel/jetstream` and presence of Jetstream service provider
- **FR-3.3**: Detect Fortify by checking for `laravel/fortify` and `FortifyServiceProvider`
- **FR-3.4**: Detect custom auth by presence of auth routes without known packages

### Migration Plan Generation

- **FR-3.5**: Generate a markdown migration plan document tailored to detected setup
- **FR-3.6**: Migration plan lists files to remove (e.g., Breeze auth views)
- **FR-3.7**: Migration plan lists files to modify (e.g., User model, routes/web.php)
- **FR-3.8**: Migration plan lists database changes needed (add workos_id, make password nullable)
- **FR-3.9**: Migration plan includes data migration guidance (link existing users to WorkOS)
- **FR-3.10**: Save migration plan to `storage/workos-migration-plan.md`

### Migration Guidance Display

- **FR-3.11**: Display high-level summary of migration steps in console
- **FR-3.12**: Indicate risk level for each step (low/medium/high)
- **FR-3.13**: Offer to open migration plan file in default editor
- **FR-3.14**: Warn about data migration requirements and recommend testing in staging

### Breeze-Specific Guidance

- **FR-3.15**: List Breeze auth controllers to remove
- **FR-3.16**: List Breeze views directory to remove
- **FR-3.17**: Document route changes needed (Breeze routes -> WorkOS routes)
- **FR-3.18**: Note: registration is handled by WorkOS, not local forms

### Jetstream-Specific Guidance

- **FR-3.19**: Explain which Jetstream features have WorkOS equivalents (auth, profile)
- **FR-3.20**: Explain which Jetstream features don't have equivalents (API tokens, teams)
- **FR-3.21**: Provide guidance on keeping teams feature while using WorkOS for auth
- **FR-3.22**: Document Livewire/Inertia component changes

### Fortify-Specific Guidance

- **FR-3.23**: Document Fortify service provider changes
- **FR-3.24**: Document custom auth route changes
- **FR-3.25**: Explain how Fortify features map to WorkOS (2FA, password confirmation)

### Comprehensive Testing

- **FR-3.26**: Unit tests for all detection scenarios (no auth, each package combination)
- **FR-3.27**: Unit tests for migration plan generation
- **FR-3.28**: Feature tests for `--mini` mode
- **FR-3.29**: Feature tests for `--force` mode
- **FR-3.30**: Feature tests for wizard flow with mocked prompts
- **FR-3.31**: Integration test for fresh Laravel install end-to-end
- **FR-3.32**: Integration test for laravel/workos migration end-to-end

## Non-Functional Requirements

- **NFR-3.1**: Migration plan generation must complete in under 2 seconds
- **NFR-3.2**: Generated migration plans must be valid markdown
- **NFR-3.3**: Test suite must cover at least 80% of InstallCommand code
- **NFR-3.4**: All tests must be deterministic (no flaky tests)

## Dependencies

### Prerequisites

- Phase 1 complete (detection infrastructure)
- Phase 2 complete (wizard flow, component installation)
- Sample Breeze/Jetstream/Fortify projects for testing

### Outputs for Next Phase

- Complete smart-install feature ready for release
- Migration plan templates for each auth system
- Comprehensive test suite

## Acceptance Criteria

- [ ] `workos:install` on Breeze app generates Breeze-specific migration plan
- [ ] `workos:install` on Jetstream app generates Jetstream-specific migration plan with team feature guidance
- [ ] `workos:install` on Fortify app generates Fortify-specific migration plan
- [ ] Migration plan saved to `storage/workos-migration-plan.md`
- [ ] Migration plan includes database schema changes needed
- [ ] Console displays summary with risk indicators
- [ ] Unit test coverage for EnvironmentDetector ≥ 90%
- [ ] Feature test coverage for InstallCommand ≥ 80%
- [ ] No regressions in existing functionality
- [ ] All edge cases handled with helpful error messages

## Open Questions

- Should we provide artisan commands to execute parts of the migration plan?
- Should migration plans include rollback guidance?
- How do we handle apps with multiple auth packages (e.g., Breeze + Fortify)?

---

*Review this PRD and provide feedback before spec generation.*
