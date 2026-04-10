# Contract: Drop Laravel 10 Support

## Problem Statement
Laravel 10 reaches End-of-Life (EOL) in early 2025. Tests keep failing due to Laravel 10 compatibility differences, creating maintenance burden. Supporting Laravel 10 requires version-conditional code that adds complexity.

## Goals
1. Remove Laravel 10 from supported versions
2. Eliminate Laravel 10 compatibility code
3. Simplify codebase using Laravel 11+ features/syntax
4. Update documentation to reflect new requirements

## Success Criteria
- [ ] `composer.json` requires Laravel 11+ (`^11.0|^12.0`)
- [ ] No version checks for Laravel 10 remain in codebase
- [ ] Tests pass on Laravel 11 and 12
- [ ] README accurately lists supported versions (11, 12)

## Scope

### In Scope
- Update `composer.json` dependencies
- Remove `publishesMigrations()` compatibility code in `WorkOSServiceProvider.php`
- Update `orchestra/testbench` version constraints
- Update README requirements section
- Any other Laravel 10 specific handling found

### Out of Scope
- Adding new features
- Refactoring unrelated code
- PHP version changes (staying at 8.2+)
