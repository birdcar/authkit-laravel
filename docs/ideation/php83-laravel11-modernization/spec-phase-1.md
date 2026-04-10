# Implementation Spec: PHP 8.3 & Laravel 11 Modernization - Phase 1

**PRD**: ./prd-phase-1.md
**Estimated Effort**: S (Small)

## Technical Approach

This modernization focuses on three PHP 8.3 features:

1. **Typed Class Constants** (PHP 8.3) - Adding explicit types to `const` declarations
2. **`#[Override]` Attribute** (PHP 8.3) - Marking methods that implement interfaces
3. **`json_validate()`** (PHP 8.3) - Native JSON validation before decoding

The `#[Override]` attribute import comes from the global namespace (`\Override`), so no use statement is needed - just the attribute itself.

## File Changes

### New Files

None

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOS.php` | Add typed constant for SERVICE_MAP |
| `src/Http/Controllers/WebhookController.php` | Add typed constant for EVENT_MAP |
| `src/Auth/SessionManager.php` | Add typed constant, add `#[Override]` to all interface methods |
| `src/Auth/CookieSessionManager.php` | Add `#[Override]` to all interface methods |
| `src/Auth/WorkOSGuard.php` | Add `#[Override]` to all Guard interface methods |
| `src/Http/Controllers/AuthController.php` | Use `json_validate()` in extractReturnTo |
| `src/Install/Plans/BaseMigrationPlan.php` | Add `#[Override]` to MigrationPlan methods |
| `src/Install/Plans/BreezeMigrationPlan.php` | Add `#[Override]` to generate() method |
| `src/Install/Plans/JetstreamMigrationPlan.php` | Add `#[Override]` to generate() method |
| `src/Install/Plans/FortifyMigrationPlan.php` | Add `#[Override]` to generate() method |

### Deleted Files

| File Path | Reason |
|-----------|--------|
| `src/Traits/HasWorkOSPermissions.php` | Deprecated alias - canonical is `Models/Concerns/HasWorkOSPermissions` |
| `src/Traits/HasWorkOSId.php` | Deprecated alias - canonical is `Models/Concerns/HasWorkOSId` |

## Implementation Details

### 1. Typed Class Constants

**Overview**: PHP 8.3 allows type declarations on class constants.

```php
// Before
private const SERVICE_MAP = [...];

// After
/** @var array<string, class-string> */
private const array SERVICE_MAP = [...];
```

**Files to modify**:

1. `src/WorkOS.php` - `SERVICE_MAP` constant:
```php
/** @var array<string, class-string> */
private const array SERVICE_MAP = [
    'auditLogs' => \WorkOS\AuditLogs::class,
    // ...
];
```

2. `src/Http/Controllers/WebhookController.php` - `EVENT_MAP` constant:
```php
/** @var array<string, class-string> */
public const array EVENT_MAP = [
    'user.created' => WorkOSUserCreated::class,
    // ...
];
```

3. `src/Auth/SessionManager.php` - `SESSION_KEY` constant:
```php
private const string SESSION_KEY = 'workos_session';
```

### 2. Override Attributes

**Overview**: Add `#[Override]` attribute to methods implementing interfaces.

**Pattern**:
```php
#[\Override]
public function methodName(): ReturnType
{
    // ...
}
```

**SessionManager.php** - Add `#[Override]` to these methods:
- `getSession()`
- `getValidSession()`
- `store()`
- `destroy()`
- `isImpersonating()`
- `getOrganizationId()`
- `setOrganizationId()`
- `hasPermission()`
- `hasRole()`

**CookieSessionManager.php** - Same methods as SessionManager

**WorkOSGuard.php** - Add `#[Override]` to Guard interface methods:
- `check()`
- `guest()`
- `user()`
- `id()`
- `validate()`
- `hasUser()`
- `setUser()`

**Migration Plan Classes** - Add `#[Override]` to:
- `BaseMigrationPlan` abstract methods (if implementing interface)
- `BreezeMigrationPlan::generate()`
- `JetstreamMigrationPlan::generate()`
- `FortifyMigrationPlan::generate()`

### 3. json_validate() Usage

**File**: `src/Http/Controllers/AuthController.php`

**Before**:
```php
protected function extractReturnTo(Request $request): ?string
{
    $state = $request->query('state');

    if (is_string($state)) {
        $decoded = json_decode($state, true);
        if (is_array($decoded) && isset($decoded['return_to'])) {
            return (string) $decoded['return_to'];
        }
    }

    return null;
}
```

**After**:
```php
protected function extractReturnTo(Request $request): ?string
{
    $state = $request->query('state');

    if (is_string($state) && json_validate($state)) {
        $decoded = json_decode($state, true);
        if (is_array($decoded) && isset($decoded['return_to'])) {
            return (string) $decoded['return_to'];
        }
    }

    return null;
}
```

### 4. Remove Deprecated Trait Aliases

**Implementation steps**:
1. Delete `src/Traits/HasWorkOSPermissions.php`
2. Delete `src/Traits/HasWorkOSId.php`
3. Delete `src/Traits/` directory if empty

## Testing Requirements

### Unit Tests

No new tests needed - existing tests validate functionality is unchanged.

**Key validation**:
- All existing tests in `tests/` must pass
- Tests already cover the trait functionality via `Models/Concerns/` path

### Integration Tests

None required - no behavioral changes.

### Manual Testing

- [ ] Verify `composer test` passes
- [ ] Verify `composer analyse` passes
- [ ] Verify `composer format:test` passes
- [ ] Verify package can be installed in a fresh Laravel 11 project

## Error Handling

No changes to error handling - this is a syntactic modernization only.

## Validation Commands

```bash
# Run all tests
composer test

# Static analysis (PHPStan level 8)
composer analyse

# Code formatting check
composer format:test

# Fix formatting if needed
composer format

# Full validation sequence
composer format && composer analyse && composer test
```

## Rollout Considerations

- **Feature flag**: N/A - compile-time changes only
- **Monitoring**: N/A
- **Alerting**: N/A
- **Rollback plan**: Revert commits if any issues discovered

## Open Items

None - spec is complete and ready for implementation.

---

*This spec is ready for implementation. Apply changes file-by-file and validate after each major change.*
