# Tasks Manifest

**Task List ID**: `php83-laravel11-modernization-1738012800`
**Created**: 2026-01-27
**Project**: php83-laravel11-modernization

## Quick Start

```bash
# Execute the spec
/execute-spec docs/ideation/php83-laravel11-modernization/spec-phase-1.md
```

## Phases

| Phase | Status | Spec File |
|-------|--------|-----------|
| 1 | pending | spec-phase-1.md |

## Summary of Changes

### Typed Class Constants (PHP 8.3)
- `WorkOS::SERVICE_MAP` -> `const array SERVICE_MAP`
- `WebhookController::EVENT_MAP` -> `const array EVENT_MAP`
- `SessionManager::SESSION_KEY` -> `const string SESSION_KEY`

### Override Attributes (PHP 8.3)
Files receiving `#[Override]` attributes:
- `SessionManager.php` (9 methods)
- `CookieSessionManager.php` (9 methods)
- `WorkOSGuard.php` (7 methods)
- Migration plan classes (1-2 methods each)

### Code Cleanup
- Remove `src/Traits/HasWorkOSPermissions.php`
- Remove `src/Traits/HasWorkOSId.php`
- Remove `src/Traits/` directory

### Modern PHP Usage
- Use `json_validate()` in `AuthController::extractReturnTo()`

## Validation

After implementation, run:
```bash
composer format && composer analyse && composer test
```
