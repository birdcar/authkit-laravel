# PRD Phase 1: Drop Laravel 10 Support

## Overview
Single-phase implementation to remove Laravel 10 support and simplify the codebase. This is a straightforward deprecation that affects dependency constraints and one compatibility shim.

## User Stories
- As a maintainer, I want to stop supporting Laravel 10 so I can focus on current LTS versions
- As a developer, I want simpler code without version conditionals so the codebase is easier to understand

## Functional Requirements

### FR1: Update Composer Dependencies
- Update `illuminate/contracts` from `^10.0|^11.0|^12.0` to `^11.0|^12.0`
- Update `illuminate/support` from `^10.0|^11.0|^12.0` to `^11.0|^12.0`
- Update `orchestra/testbench` from `^8.0|^9.0|^10.0` to `^9.0|^10.0`
- Update `pestphp/pest` from `^2.0|^3.0` to `^3.0` (Pest 3 requires PHP 8.2+, matches our stack)

### FR2: Remove Compatibility Code
- Remove the `publishesMigrations()` conditional in `WorkOSServiceProvider.php`
- Use `publishesMigrations()` directly (available since Laravel 11.0)
- Remove the `@phpstan-ignore-next-line` comment
- Remove the `Application::VERSION` import if no longer needed

### FR3: Update Documentation
- Update README requirements from "Laravel 10, 11, or 12" to "Laravel 11 or 12"

## Non-Functional Requirements
- All existing tests must pass
- No breaking changes to public API

## Acceptance Criteria
- [ ] `composer validate` passes
- [ ] `composer test` passes on Laravel 11
- [ ] `composer test` passes on Laravel 12
- [ ] No references to Laravel 10 in codebase (except historical git)
- [ ] README accurately reflects requirements
