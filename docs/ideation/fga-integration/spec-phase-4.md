# Implementation Spec: FGA Integration - Phase 4

**Contract**: ./contract.md
**Estimated Effort**: S

## Technical Approach

Extend the existing test helpers (`WorkOS::actingAs()`, `WorkOS::fake()`, `WorkOSFake`) to support FGA scenarios. Developers need to test resource-scoped authorization without hitting the WorkOS API.

The approach: extend `WorkOSFake` to intercept `authorization()->check()` calls and return pre-configured results based on resource + permission pairs. `WorkOS::actingAs()` gains a `resourcePermissions` parameter that maps `"permission:resource_type/external_id"` strings to authorized/denied.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=WorkOSFake`

**Playground**: Test suite (Pest)

**Why this approach**: Testing the test helpers — tests all the way down. Fast and self-contained.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `tests/Unit/Testing/WorkOSFakeResourceCheckTest.php` | Tests for FGA test helper extensions |

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/Testing/WorkOSFake.php` | Add FGA check stubbing, resource permission storage, and fake Authorization service |
| `src/WorkOS.php` | Extend `actingAs()` signature with `resourcePermissions` parameter |
| `src/Testing/Concerns/InteractsWithWorkOS.php` | Add FGA assertion helpers if trait is used |

## Implementation Details

### WorkOSFake FGA Extensions

**Pattern to follow**: `src/Testing/WorkOSFake.php` (existing fake implementation)

**Overview**: Extend the fake to intercept `authorization()` calls and return a fake Authorization service that stubs check results.

```php
// In WorkOSFake

/** @var array<string, bool> permission:type/id => authorized */
private array $resourcePermissions = [];

/**
 * @param array<string> $resourcePermissions e.g. ['edit:project/proj_123', 'view:workspace/ws_456']
 */
public function withResourcePermissions(array $resourcePermissions): self
{
    foreach ($resourcePermissions as $grant) {
        $this->resourcePermissions[$grant] = true;
    }
    return $this;
}

/**
 * @param array<string> $deniedPermissions e.g. ['delete:project/proj_123']
 */
public function withoutResourcePermissions(array $deniedPermissions): self
{
    foreach ($deniedPermissions as $denial) {
        $this->resourcePermissions[$denial] = false;
    }
    return $this;
}

public function checkResourcePermission(string $permission, string $resourceTypeSlug, string $externalId): bool
{
    $key = "{$permission}:{$resourceTypeSlug}/{$externalId}";
    return $this->resourcePermissions[$key] ?? false;
}
```

The fake also needs a `FakeAuthorization` inner class or approach that returns `AuthorizationCheckResult` from `check()`:

```php
public function authorization(): FakeAuthorization
{
    return new FakeAuthorization($this);
}
```

Where `FakeAuthorization` extends or wraps `Authorization` and overrides `check()`:

```php
class FakeAuthorization
{
    public function __construct(private readonly WorkOSFake $fake) {}

    public function check(
        string $organizationMembershipId,
        string $permissionSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): AuthorizationCheckResult {
        $authorized = $this->fake->checkResourcePermission(
            $permissionSlug,
            $resourceTypeSlug ?? '',
            $resourceExternalId ?? $resourceId ?? '',
        );
        return new AuthorizationCheckResult($authorized);
    }

    // Stub other methods as needed — createResource, etc. can return fixtures or no-op
}
```

**Key decisions**:
- Permission format `"permission:type/external_id"` — compact, readable, and grep-friendly in tests
- Default to unauthorized (false) for unconfigured permissions — fail closed
- `FakeAuthorization` as a separate class rather than mocking — gives full control, easier to extend
- Resource CRUD methods on FakeAuthorization are no-ops or return dummy data — the focus is on check stubbing for test assertions

**Implementation steps**:
1. Add `$resourcePermissions` storage and `withResourcePermissions()`/`withoutResourcePermissions()` to `WorkOSFake`
2. Create `FakeAuthorization` class that stubs `check()` using stored permissions
3. Override `authorization()` in `WorkOSFake` to return `FakeAuthorization`
4. Wire `FakeAuthorization` resource CRUD to no-op returns
5. Extend `WorkOS::actingAs()` to accept `resourcePermissions` param

### Extended actingAs()

**Pattern to follow**: Existing `WorkOS::actingAs()` in `src/WorkOS.php`

**Overview**: Add optional `resourcePermissions` parameter to `actingAs()`.

```php
/**
 * @param array<string> $roles
 * @param array<string> $permissions
 * @param array<string> $resourcePermissions e.g. ['edit:project/proj_123']
 */
public static function actingAs(
    Authenticatable $user,
    array $roles = [],
    array $permissions = [],
    ?string $organizationId = null,
    array $resourcePermissions = [],
): WorkOSFake {
    $fake = static::fake()->actingAs($user, $roles, $permissions, $organizationId);

    if (! empty($resourcePermissions)) {
        $fake->withResourcePermissions($resourcePermissions);
    }

    return $fake;
}
```

**Key decisions**:
- `resourcePermissions` defaults to empty array — fully backwards compatible
- Added as last parameter — doesn't break existing call sites
- Delegates to `withResourcePermissions()` — same underlying mechanism

**Implementation steps**:
1. Add `resourcePermissions` parameter to `WorkOS::actingAs()`
2. Add `resourcePermissions` parameter to `WorkOSFake::actingAs()`
3. Wire through to `withResourcePermissions()`

**Feedback loop**:
- **Playground**: Write a test that uses `WorkOS::actingAs($user, resourcePermissions: ['edit:project/proj_1'])` then calls `$user->canOnResource('edit', $project)`
- **Experiment**: Assert true for granted permissions, false for ungranted
- **Check command**: `composer test -- --filter=WorkOSFake`

**Failure Modes**:

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| FakeAuthorization::check | Permission key format mismatch | Developer passes different format than expected | Always returns false (fail-closed) | Document the format clearly, add validation with helpful error message |
| WorkOSFake::authorization | Method called before fake() | Using authorization() on the real service in a test | Real API call | Document: always call WorkOS::fake() first. This is existing convention. |
| FakeAuthorization CRUD | Return value not matching real API | Test assertions depend on created resource shape | Test passes but would fail with real API | FakeAuthorization returns realistic fixture data for CRUD |

## Testing Requirements

### Unit Tests

| Test File | Coverage |
| --- | --- |
| `tests/Unit/Testing/WorkOSFakeResourceCheckTest.php` | All FGA fake functionality |

**Key test cases**:
- `withResourcePermissions(['edit:project/p1'])` → `check` returns authorized for that combo
- Unconfigured permission → check returns unauthorized (fail closed)
- `withoutResourcePermissions(['edit:project/p1'])` → explicitly denied
- `actingAs` with `resourcePermissions` → `canOnResource` returns true
- `actingAs` without `resourcePermissions` → `canOnResource` returns false
- Multiple resource permissions → each independently checked
- `authorization()` on fake returns `FakeAuthorization` instance
- `FakeAuthorization::createResource()` returns a dummy AuthorizationResource
- `FakeAuthorization::check()` with resource ID (not just external ID)
- Backwards compatibility: `actingAs` without `resourcePermissions` works identically to before

## Validation Commands

```bash
composer test -- --filter=WorkOSFake
composer analyse
composer format
```

## Rollout Considerations

- **Backwards compatibility**: Fully backwards compatible — new parameter is optional with empty default
- **Developer documentation**: Include usage examples in README showing test patterns

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
