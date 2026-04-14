# Implementation Spec: Platform Parity - Phase 1 (Session Token Parity)

**Contract**: ./contract.md
**Estimated Effort**: S

## Technical Approach

Extract `feature_flags`, `entitlements`, and arbitrary custom claims from the WorkOS JWT access token and expose them through `WorkOSSession`, `HasWorkOSPermissions` trait, facade methods, and Blade directives. This is foundational — later phases (Feature Flags, FGA) build on these session fields.

The WorkOS access token JWT contains these claims (confirmed from authkit-nextjs `interfaces.ts`):
- `sid` — session ID
- `org_id` — organization ID
- `role` / `roles` — user roles
- `permissions` — user permissions
- `entitlements` — plan/subscription entitlements
- `feature_flags` — enabled feature flags

Currently, `WorkOSSession` only extracts `roles`, `permissions`, `organizationId`, `sessionId`, and `impersonator`. We need to also extract `entitlements`, `feature_flags`, and provide a generic `claim(string $key)` accessor for custom claims.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/WorkOSSessionTest.php`

**Playground**: Test suite.

**Why this approach**: All changes are to value objects and traits — pure PHP, no external calls needed.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `src/Auth/WorkOSSession.php` | Add `featureFlags`, `entitlements` properties; add `hasFeatureFlag()`, `hasEntitlement()`, `claim()` methods; update `fromArray()` factory |
| `src/Auth/SessionManager.php` | Pass `feature_flags` and `entitlements` through when building WorkOSSession |
| `src/Models/Concerns/HasWorkOSPermissions.php` | Add `hasFeatureFlag()`, `hasEntitlement()` delegation methods |
| `src/WorkOS.php` | Add `hasFeatureFlag()`, `hasEntitlement()` convenience methods |
| `src/Facades/WorkOS.php` | Add `@method` docblock entries for new methods |
| `src/WorkOSServiceProvider.php` | Register `@workosFeature` and `@workosEntitlement` Blade directives |
| `src/Testing/WorkOSFake.php` | Support `featureFlags` and `entitlements` in `actingAs()` and assertions |
| `tests/Unit/WorkOSSessionTest.php` | Add tests for new properties and methods |
| `tests/Unit/HasWorkOSPermissionsTest.php` | Add tests for new delegation methods |
| `tests/Unit/WorkOSFakeTest.php` | Add tests for new fake capabilities |

## Implementation Details

### WorkOSSession Value Object

**Pattern to follow**: `src/Auth/WorkOSSession.php` (existing `roles`, `permissions` properties)

**Overview**: Add `featureFlags`, `entitlements`, and raw `claims` to the session value object.

```php
// New properties on WorkOSSession
/** @var array<string> */
public readonly array $featureFlags;

/** @var array<string> */
public readonly array $entitlements;

/** @var array<string, mixed> */
public readonly array $claims;

// New methods
public function hasFeatureFlag(string $flag): bool
{
    return in_array($flag, $this->featureFlags, true);
}

public function hasEntitlement(string $entitlement): bool
{
    return in_array($entitlement, $this->entitlements, true);
}

public function claim(string $key, mixed $default = null): mixed
{
    return $this->claims[$key] ?? $default;
}
```

**Key decisions**:
- `featureFlags` and `entitlements` are `array<string>` — simple string lists matching the JWT claim format
- `claims` stores the full decoded access token payload for arbitrary access via `claim()`
- The `fromArray()` factory reads `feature_flags` and `entitlements` from the decoded token, defaulting to `[]`

### HasWorkOSPermissions Trait

Add delegation methods that call through to the session:

```php
public function hasFeatureFlag(string $flag): bool
{
    return $this->getWorkOSSession()?->hasFeatureFlag($flag) ?? false;
}

public function hasEntitlement(string $entitlement): bool
{
    return $this->getWorkOSSession()?->hasEntitlement($entitlement) ?? false;
}
```

### Blade Directives

```php
// In WorkOSServiceProvider::configureBladeDirectives()
Blade::if('workosFeature', fn (string $flag) => WorkOS::hasFeatureFlag($flag));
Blade::if('workosEntitlement', fn (string $entitlement) => WorkOS::hasEntitlement($entitlement));
```

### WorkOSFake

Extend `actingAs()` to accept `featureFlags` and `entitlements`:

```php
public static function actingAs(
    Authenticatable $user,
    array $roles = [],
    array $permissions = [],
    ?string $organizationId = null,
    array $featureFlags = [],
    array $entitlements = [],
): WorkOSFake
```

Add assertions: `assertHasFeatureFlag(string)`, `assertHasEntitlement(string)`.

## Testing Requirements

### Unit Tests

**Key test cases**:
- `WorkOSSession::fromArray()` extracts `feature_flags` and `entitlements`
- `WorkOSSession::hasFeatureFlag()` returns true/false correctly
- `WorkOSSession::hasEntitlement()` returns true/false correctly
- `WorkOSSession::claim()` returns arbitrary claims with default fallback
- `HasWorkOSPermissions::hasFeatureFlag()` delegates to session
- `HasWorkOSPermissions::hasEntitlement()` delegates to session
- Both return false when no session exists
- `WorkOSFake::actingAs()` with featureFlags/entitlements populates session
- `assertHasFeatureFlag()` and `assertHasEntitlement()` work on the fake

## Validation Commands

```bash
composer analyse
composer test
```
