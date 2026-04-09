# Implementation Spec: FGA Integration - Phase 3

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Add resource-scoped authorization checks to the middleware, trait, and blade layers. This extends the existing org-level authorization (from JWT) with FGA resource-scoped checks (via API calls to WorkOS).

The `CheckResourcePermission` middleware resolves resources from route model binding and calls `authorization()->check()`. The `HasWorkOSPermissions` trait gains a `canOnResource()` method. Blade gets `@canOnResource` / `@endcanOnResource` directives. All three delegate to the same underlying Authorization service from Phase 1.

A key design constraint: resource-scoped checks require the user's `organizationMembershipId`, which is not currently stored in `WorkOSSession`. We'll need to either fetch it on-demand from the UserManagement API or add it to the session data. The most practical approach is to resolve it via the UserManagement API using the user's `userId` and `organizationId` from the session, with request-level caching.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter="CheckResourcePermission|canOnResource|BladeDirective"`

**Playground**: Test suite (Pest) with HTTP test helpers from Orchestra Testbench

**Why this approach**: Middleware tests use Laravel's HTTP test infrastructure; blade directive tests render templates. Both are fast in Pest.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `src/Http/Middleware/CheckResourcePermission.php` | Middleware for resource-scoped permission checks via route model binding |
| `src/Authorization/MembershipResolver.php` | Resolves organizationMembershipId from userId + organizationId with caching |
| `tests/Unit/Http/Middleware/CheckResourcePermissionTest.php` | Tests for middleware |
| `tests/Unit/Authorization/MembershipResolverTest.php` | Tests for membership resolution |
| `tests/Unit/BladeDirectives/CanOnResourceTest.php` | Tests for blade directive |

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/Models/Concerns/HasWorkOSPermissions.php` | Add `canOnResource()` method |
| `src/WorkOSServiceProvider.php` | Register `@canOnResource` blade directive; register `CheckResourcePermission` middleware alias; register `MembershipResolver` singleton |
| `src/WorkOS.php` | Add `canOnResource()` convenience method on facade |

## Implementation Details

### MembershipResolver

**Pattern to follow**: `src/Auth/SessionManager.php` (for request-scoped caching pattern)

**Overview**: Resolves the `organizationMembershipId` needed for FGA check/assign calls. The current `WorkOSSession` has `userId` and `organizationId` but not the membership ID. This resolver fetches it from the UserManagement API and caches per-request.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Authorization;

use WorkOS\UserManagement;

class MembershipResolver
{
    /** @var array<string, string> */
    private array $cache = [];

    public function resolve(string $userId, string $organizationId): ?string
    {
        $key = "{$userId}:{$organizationId}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $userManagement = new UserManagement();
        $response = $userManagement->listOrganizationMemberships(
            userId: $userId,
            organizationId: $organizationId,
            limit: 1,
        );

        $membershipId = $response->data[0]->id ?? null;

        if ($membershipId) {
            $this->cache[$key] = $membershipId;
        }

        return $membershipId;
    }
}
```

**Key decisions**:
- Request-scoped cache (instance property) — the resolver is registered as a singleton scoped to the request, so the cache resets per-request
- Returns nullable — if no membership found, callers must handle gracefully
- Uses `listOrganizationMemberships` with both userId and orgId filters — most targeted query

**Implementation steps**:
1. Create MembershipResolver with resolve method
2. Add per-request caching
3. Register as singleton in ServiceProvider
4. Write tests with mocked UserManagement

### CheckResourcePermission Middleware

**Pattern to follow**: `src/Http/Middleware/CheckPermission.php`

**Overview**: Middleware that checks if the authenticated user has a specific permission on a specific resource. Resources are resolved from route model binding.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Authorization\MembershipResolver;
use WorkOS\AuthKit\Exceptions\MissingPermissionException;

class CheckResourcePermission
{
    public function __construct(
        private readonly MembershipResolver $membershipResolver,
    ) {}

    /**
     * Usage: middleware('workos.resource:project,edit')
     * - First param: route parameter name (resolved via route model binding)
     * - Second param: permission slug to check
     */
    public function handle(Request $request, Closure $next, string $resourceParam, string $permission): Response
    {
        $user = $request->user();
        if (! $user) {
            throw new MissingPermissionException([$permission], 'Unauthenticated');
        }

        $session = app('workos')->validSession();
        if (! $session || ! $session->organizationId) {
            throw new MissingPermissionException([$permission], 'No active organization');
        }

        $model = $request->route($resourceParam);

        // Resolve resource identity
        if ($model && method_exists($model, 'getResourceTypeSlug')) {
            $resourceTypeSlug = $model->getResourceTypeSlug();
            $externalId = $model->getFGAExternalId();
        } else {
            throw new MissingPermissionException([$permission], "Route parameter [{$resourceParam}] is not an FGA resource");
        }

        $membershipId = $this->membershipResolver->resolve($session->userId, $session->organizationId);
        if (! $membershipId) {
            throw new MissingPermissionException([$permission], 'Organization membership not found');
        }

        $result = app('workos')->authorization()->check(
            organizationMembershipId: $membershipId,
            permissionSlug: $permission,
            resourceExternalId: $externalId,
            resourceTypeSlug: $resourceTypeSlug,
        );

        if (! $result->authorized) {
            throw new MissingPermissionException([$permission]);
        }

        return $next($request);
    }
}
```

**Key decisions**:
- Middleware signature: `workos.resource:{routeParam},{permission}` — two params, not three. The resource type comes from the model's `getResourceTypeSlug()`
- Requires the route-bound model to use `SyncsWithFGA` (or at least implement `getResourceTypeSlug()` and `getFGAExternalId()`) — enforced at runtime
- Uses `MembershipResolver` to get the membership ID from session's userId + organizationId
- Throws `MissingPermissionException` (reuses existing exception) for all failure cases

**Implementation steps**:
1. Create middleware with constructor-injected MembershipResolver
2. Implement resource resolution from route model binding
3. Wire up authorization check
4. Register middleware alias `workos.resource` in ServiceProvider
5. Write tests

**Feedback loop**:
- **Playground**: Use Orchestra Testbench HTTP test helpers to simulate requests with route model binding
- **Experiment**: Test with authorized user → 200; unauthorized → MissingPermissionException; missing model → exception
- **Check command**: `composer test -- --filter=CheckResourcePermission`

### HasWorkOSPermissions Trait Extension

**Pattern to follow**: Existing `hasWorkOSPermission()` and `hasWorkOSRole()` methods in `src/Models/Concerns/HasWorkOSPermissions.php`

**Overview**: Add `canOnResource()` method that performs an FGA resource-scoped check.

```php
public function canOnResource(string $permission, mixed $resource): bool
{
    $session = $this->getWorkOSSession();
    if (! $session || ! $session->organizationId) {
        return false;
    }

    if (! method_exists($resource, 'getResourceTypeSlug') || ! method_exists($resource, 'getFGAExternalId')) {
        return false;
    }

    $resolver = app(MembershipResolver::class);
    $membershipId = $resolver->resolve($session->userId, $session->organizationId);

    if (! $membershipId) {
        return false;
    }

    $result = app('workos')->authorization()->check(
        organizationMembershipId: $membershipId,
        permissionSlug: $permission,
        resourceExternalId: $resource->getFGAExternalId(),
        resourceTypeSlug: $resource->getResourceTypeSlug(),
    );

    return $result->authorized;
}
```

**Key decisions**:
- Returns `bool` (not throws) — matches `hasWorkOSPermission()` pattern
- Accepts `mixed $resource` and checks for required methods — avoids coupling to SyncsWithFGA trait type
- Returns `false` on any resolution failure — safe default, no exception

**Implementation steps**:
1. Add `canOnResource()` method to trait
2. Add import for `MembershipResolver`
3. Write tests with mock Authorization service and MembershipResolver

### Blade Directives

**Pattern to follow**: Existing `@workosPermission` / `@endworkosPermission` directives in `WorkOSServiceProvider.php`

**Overview**: Add `@canOnResource('permission', $resource)` / `@endcanOnResource` conditional blade directives.

```php
// In WorkOSServiceProvider::registerBladeDirectives()
Blade::if('canOnResource', function (string $permission, mixed $resource) {
    $user = auth(config('workos.guard', 'workos'))->user();
    if (! $user || ! method_exists($user, 'canOnResource')) {
        return false;
    }
    return $user->canOnResource($permission, $resource);
});
```

**Key decisions**:
- Uses `Blade::if()` for automatic `@canOnResource` / `@elsecanOnResource` / `@endcanOnResource` support
- Delegates to the trait's `canOnResource()` — single source of truth
- Fails closed (returns false) if user doesn't have the trait

**Implementation steps**:
1. Add `Blade::if('canOnResource', ...)` in ServiceProvider
2. Write test rendering a blade template with the directive

**Failure Modes**:

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| MembershipResolver | No membership found | User not a member of the org | null return, check fails | `canOnResource` returns false; middleware throws |
| MembershipResolver | API call fails | Network error | Exception from UserManagement | Let propagate — middleware catches, blade fails closed |
| CheckResourcePermission | Route param not an FGA model | Developer misconfiguration | Exception with descriptive message | Clear error message naming the route parameter |
| CheckResourcePermission | No active organization | User logged in without org context | Exception | Descriptive message — user needs org context for FGA |
| canOnResource | API latency | Slow WorkOS API response | Blade rendering delayed | Document: resource-scoped checks are API calls, consider caching (future) |
| Blade directive | Resource variable undefined | Template error | PHP error in blade | Standard blade error handling applies |

## Testing Requirements

### Unit Tests

| Test File | Coverage |
| --- | --- |
| `tests/Unit/Http/Middleware/CheckResourcePermissionTest.php` | Middleware happy path, auth failures, missing resource |
| `tests/Unit/Authorization/MembershipResolverTest.php` | Resolution, caching, not-found |
| `tests/Unit/BladeDirectives/CanOnResourceTest.php` | Directive rendering with authorized/unauthorized |

**Key test cases**:
- Middleware: authorized user with valid resource → passes through
- Middleware: unauthorized user → throws MissingPermissionException
- Middleware: unauthenticated user → throws
- Middleware: route param missing `getResourceTypeSlug` → throws with descriptive message
- Middleware: no organization in session → throws
- MembershipResolver: resolves and caches on first call
- MembershipResolver: returns cached value on second call (no API hit)
- MembershipResolver: returns null when no membership
- canOnResource: true when check returns authorized
- canOnResource: false when check returns unauthorized
- canOnResource: false when no session
- canOnResource: false when no organization
- Blade: renders content when authorized
- Blade: hides content when unauthorized

## Validation Commands

```bash
composer test -- --filter="CheckResourcePermission|canOnResource|MembershipResolver|BladeDirective"
composer analyse
composer format
```

## Open Items

- [ ] Determine if `organizationMembershipId` can be added to `WorkOSSession` in the future (would eliminate MembershipResolver API calls). Requires changes to the auth response from WorkOS.

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
