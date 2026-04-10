# PHP 8.3 & Laravel 11 Modernization Contract

**Created**: 2026-01-27
**Confidence Score**: 96/100
**Status**: Draft

## Problem Statement

The authkit-laravel package recently dropped support for PHP 8.2 and Laravel 10, but the codebase still uses patterns compatible with older versions. This represents missed opportunities to leverage newer language features that improve code safety, readability, and maintainability.

Specifically, PHP 8.3 introduced features like typed class constants and the `#[Override]` attribute that can catch bugs at compile time. The `#[Override]` attribute is particularly valuable in a codebase with multiple interface implementations (SessionManager, CookieSessionManager, WorkOSGuard) where method signature mismatches could silently break functionality.

Additionally, deprecated trait aliases in `src/Traits/` add maintenance burden and could confuse new contributors about the canonical import paths.

## Goals

1. **Type Safety**: Add typed class constants and `#[Override]` attributes to catch interface implementation errors at compile time
2. **Code Cleanup**: Remove deprecated trait aliases now that a major version bump allows breaking changes
3. **Modernization**: Use PHP 8.3's `json_validate()` where appropriate for cleaner validation logic
4. **Consistency**: Apply new patterns uniformly across the codebase

## Success Criteria

- [ ] All class constants that can be typed are typed
- [ ] All methods implementing interfaces have `#[Override]` attribute
- [ ] Deprecated `src/Traits/` directory is removed
- [ ] `json_validate()` used where JSON validation occurs before decoding
- [ ] All existing tests pass
- [ ] Static analysis (PHPStan level 8) passes
- [ ] Code formatting (Pint) passes

## Scope Boundaries

### In Scope

- Adding typed class constants (PHP 8.3)
- Adding `#[Override]` attributes to interface implementations
- Removing deprecated trait aliases in `src/Traits/`
- Using `json_validate()` in `AuthController::extractReturnTo()`
- Updating any related imports or documentation comments

### Out of Scope

- Functional changes to the package behavior
- Adding new features
- Changing public API signatures
- Laravel 11-specific configuration patterns (the package already follows best practices)
- Removing support for Laravel 11 in favor of Laravel 12 only

### Future Considerations

- Consider `readonly` classes for more DTOs if additional ones are added
- Monitor PHP 8.4 for additional modernization opportunities

---

*This contract was generated from the user's request to modernize after dropping PHP 8.2/Laravel 10 support.*
