# PRD: PHP 8.3 & Laravel 11 Modernization - Phase 1

**Contract**: ./contract.md
**Phase**: 1 of 1
**Focus**: Apply PHP 8.3 features and remove deprecated code

## Phase Overview

This single-phase modernization applies PHP 8.3 features throughout the codebase and removes deprecated backward-compatibility code. The changes are purely syntactic improvements that don't alter runtime behavior but provide better compile-time safety and cleaner code.

The `#[Override]` attribute is the most impactful change - it will cause a compile-time error if a method marked with `#[Override]` doesn't actually override a parent method, catching refactoring errors early.

## User Stories

1. As a package maintainer, I want compile-time errors when interface implementations drift so that refactoring doesn't silently break functionality
2. As a package consumer, I want clear import paths without deprecated aliases so that I know which namespace to use
3. As a contributor, I want modern PHP idioms so that the code is easier to understand and maintain

## Functional Requirements

### Typed Class Constants

- **FR-1.1**: Add type declarations to `WorkOS::SERVICE_MAP` constant
- **FR-1.2**: Add type declarations to `WebhookController::EVENT_MAP` constant
- **FR-1.3**: Add type declaration to `SessionManager::SESSION_KEY` constant

### Override Attributes

- **FR-1.4**: Add `#[Override]` to all `SessionManagerInterface` implementations in `SessionManager`
- **FR-1.5**: Add `#[Override]` to all `SessionManagerInterface` implementations in `CookieSessionManager`
- **FR-1.6**: Add `#[Override]` to all `Guard` interface implementations in `WorkOSGuard`
- **FR-1.7**: Add `#[Override]` to `MigrationPlan` interface implementations in plan classes

### Code Cleanup

- **FR-1.8**: Remove `src/Traits/HasWorkOSPermissions.php` deprecated alias
- **FR-1.9**: Remove `src/Traits/HasWorkOSId.php` deprecated alias
- **FR-1.10**: Remove empty `src/Traits/` directory

### Modern PHP Usage

- **FR-1.11**: Replace `json_decode()` + `is_array()` with `json_validate()` in `AuthController::extractReturnTo()`

## Non-Functional Requirements

- **NFR-1.1**: All changes must be backward compatible within PHP 8.3+ constraint
- **NFR-1.2**: No runtime behavior changes - all improvements are compile-time
- **NFR-1.3**: PHPStan level 8 must pass
- **NFR-1.4**: Pint formatting must pass

## Dependencies

### Prerequisites

- PHP 8.3+ (already set in composer.json)
- All existing tests passing before changes

### Outputs for Next Phase

- N/A - this is a single-phase project

## Acceptance Criteria

- [ ] All typed constants compile without errors
- [ ] All `#[Override]` attributes are correctly applied
- [ ] No files remain in `src/Traits/` directory
- [ ] `json_validate()` used in `extractReturnTo()`
- [ ] `composer test` passes
- [ ] `composer analyse` passes (PHPStan level 8)
- [ ] `composer format:test` passes (Pint)

## Open Questions

None - all decisions have been made.

---

*Single-phase modernization - proceed to spec generation.*
