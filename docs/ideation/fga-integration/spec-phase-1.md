# Implementation Spec: FGA Integration - Phase 1

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

Build an `Authorization` service class that wraps all `/authorization/*` FGA endpoints using `WorkOS\Client::request()` directly. This mirrors the Node SDK's `Authorization` class shape so when the PHP SDK ships its own version (PR #351), we can swap to it with minimal changes.

The service encompasses everything under `/authorization/*`: the existing RBAC (roles, permissions) plus new FGA capabilities (resources, assignments, checks, discovery). Data flows through immutable value objects (`AuthorizationResource`, `RoleAssignment`, `AuthorizationCheckResult`) following the `WorkOSSession` pattern.

The service is registered in the `WorkOS` facade as `authorization()`, added to the `SERVICE_MAP`, and gated behind `config('workos.features.fga')` for FGA-specific methods. The existing RBAC passthrough (roles/permissions) remains always-available.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=Authorization`

**Playground**: Test suite (Pest)

**Why this approach**: This phase is pure service layer — no UI, no middleware. A fast test runner targeting the Authorization test files is the tightest loop.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `src/Authorization/Authorization.php` | Main service class wrapping all `/authorization/*` endpoints |
| `src/Authorization/AuthorizationResource.php` | Readonly value object for FGA resources |
| `src/Authorization/RoleAssignment.php` | Readonly value object for role assignments |
| `src/Authorization/AuthorizationCheckResult.php` | Readonly value object for check results |
| `src/Authorization/PaginatedResult.php` | Generic paginated result wrapper for list endpoints |
| `tests/Unit/Authorization/AuthorizationTest.php` | Tests for resource CRUD, checks, assignments, discovery |
| `tests/Unit/Authorization/AuthorizationResourceTest.php` | Tests for value object construction |
| `tests/Fixtures/authorization-resource.json` | Fixture: single resource API response |
| `tests/Fixtures/authorization-resources-list.json` | Fixture: paginated resource list response |
| `tests/Fixtures/authorization-check.json` | Fixture: check result response |
| `tests/Fixtures/role-assignment.json` | Fixture: role assignment response |
| `tests/Fixtures/role-assignments-list.json` | Fixture: paginated assignments list response |

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/WorkOS.php` | Add `authorization()` method and add to `SERVICE_MAP` |
| `src/Facades/WorkOS.php` | Add `@method` phpdoc for `authorization()` |
| `config/workos.php` | Add `features.fga` toggle (default false) |
| `src/WorkOSServiceProvider.php` | Register `Authorization` as singleton in container |

## Implementation Details

### Authorization Service Class

**Pattern to follow**: `vendor/workos/workos-php/lib/RBAC.php` (for request pattern) and Node SDK's `src/authorization/authorization.ts` (for API surface)

**Overview**: Central service class that wraps all `/authorization/*` API endpoints. Uses `WorkOS\Client::request()` for HTTP calls. Returns typed value objects.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Authorization;

use WorkOS\Client;

class Authorization
{
    // --- Resources ---
    public function createResource(
        string $resourceTypeSlug,
        string $externalId,
        string $organizationId,
        string $name,
        ?string $description = null,
        ?string $parentResourceId = null,
        ?string $parentResourceTypeSlug = null,
        ?string $parentResourceExternalId = null,
    ): AuthorizationResource {}

    public function getResource(string $resourceId): AuthorizationResource {}

    public function getResourceByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
    ): AuthorizationResource {}

    public function updateResource(
        string $resourceId,
        ?string $name = null,
        ?string $description = null,
        ?string $parentResourceId = null,
        ?string $parentResourceTypeSlug = null,
        ?string $parentResourceExternalId = null,
    ): AuthorizationResource {}

    public function updateResourceByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        ?string $name = null,
        ?string $description = null,
        ?string $parentResourceId = null,
        ?string $parentResourceTypeSlug = null,
        ?string $parentResourceExternalId = null,
    ): AuthorizationResource {}

    public function deleteResource(string $resourceId, bool $cascadeDelete = false): void {}

    public function deleteResourceByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        bool $cascadeDelete = false,
    ): void {}

    public function listResources(
        ?string $resourceTypeSlug = null,
        ?string $organizationId = null,
        int $limit = 10,
        ?string $before = null,
        ?string $after = null,
    ): PaginatedResult {}

    // --- Access Checks ---
    public function check(
        string $organizationMembershipId,
        string $permissionSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): AuthorizationCheckResult {}

    // --- Role Assignments ---
    public function assignRole(
        string $organizationMembershipId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): RoleAssignment {}

    public function removeRoleAssignment(
        string $organizationMembershipId,
        string $roleAssignmentId,
    ): void {}

    public function listRoleAssignments(
        string $organizationMembershipId,
        int $limit = 10,
        ?string $before = null,
        ?string $after = null,
    ): PaginatedResult {}

    // --- Resource Discovery ---
    public function listResourcesForMembership(
        string $organizationMembershipId,
        ?string $resourceTypeSlug = null,
        ?string $permissionSlug = null,
        int $limit = 10,
        ?string $before = null,
        ?string $after = null,
    ): PaginatedResult {}

    public function listMembershipsForResource(
        string $resourceId,
        ?string $roleSlug = null,
        int $limit = 10,
        ?string $before = null,
        ?string $after = null,
    ): PaginatedResult {}

    public function listMembershipsForResourceByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        ?string $roleSlug = null,
        int $limit = 10,
        ?string $before = null,
        ?string $after = null,
    ): PaginatedResult {}
}
```

**Key decisions**:
- Use nullable params with defaults rather than options arrays — matches the existing PHP SDK pattern in RBAC.php
- Support both `resourceId` (WorkOS internal ID) and `externalId + typeSlug` variants for check/assign — mirrors how the Node SDK does it with union types, but PHP uses nullable params instead
- Return typed value objects, not raw arrays — consistent with `WorkOSSession` pattern
- No wrapping of existing RBAC methods yet — the SDK's `RBAC` class already handles those; we just expose it alongside. The full merge happens when the PHP SDK ships `Authorization`

**Implementation steps**:
1. Create `AuthorizationResource`, `RoleAssignment`, `AuthorizationCheckResult`, and `PaginatedResult` value objects
2. Create `Authorization` service class with resource CRUD methods
3. Add check, assignment, and discovery methods
4. Register in `WorkOS.php` SERVICE_MAP and add explicit `authorization()` typed method
5. Add `features.fga` to config
6. Register as singleton in service provider
7. Write comprehensive tests

**Feedback loop**:
- **Playground**: Create `tests/Unit/Authorization/AuthorizationTest.php` with a describe block and mock `WorkOS\Client`
- **Experiment**: Test each endpoint method: create resource, get by ID, get by external ID, check, assign, list
- **Check command**: `composer test -- --filter=Authorization`

### Value Objects

**Pattern to follow**: `src/Auth/WorkOSSession.php`

**Overview**: Immutable readonly data classes for API responses. Each has a static `fromResponse()` factory.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Authorization;

readonly class AuthorizationResource
{
    public function __construct(
        public string $id,
        public string $externalId,
        public string $name,
        public ?string $description,
        public string $resourceTypeSlug,
        public string $organizationId,
        public ?string $parentResourceId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromResponse(array $response): self {}

    /** @return array<string, mixed> */
    public function toArray(): array {}
}
```

```php
readonly class RoleAssignment
{
    public function __construct(
        public string $id,
        public string $organizationMembershipId,
        public string $roleSlug,
        public ?string $resourceId,
        public string $createdAt,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromResponse(array $response): self {}
}
```

```php
readonly class AuthorizationCheckResult
{
    public function __construct(
        public bool $authorized,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromResponse(array $response): self {}
}
```

```php
/** @template T */
readonly class PaginatedResult
{
    /**
     * @param array<T> $data
     */
    public function __construct(
        public array $data,
        public ?string $before,
        public ?string $after,
    ) {}
}
```

**Key decisions**:
- `AuthorizationResource` stores `createdAt`/`updatedAt` as strings (ISO 8601) rather than Carbon — these are informational, not used for expiry logic like `WorkOSSession`
- `PaginatedResult` is generic over the item type to reuse across resources, assignments, and memberships

**Implementation steps**:
1. Create all four value object classes
2. Implement `fromResponse()` factories that map snake_case API keys to camelCase properties
3. Add `toArray()` where useful (AuthorizationResource)
4. Write unit tests for construction from sample API responses

### WorkOS Facade & Config Integration

**Pattern to follow**: `src/WorkOS.php` (SERVICE_MAP and typed methods)

**Overview**: Wire the Authorization service into the existing facade pattern and add the FGA feature flag.

**Implementation steps**:
1. Add `Authorization::class` (our custom class, not the SDK's RBAC) to `SERVICE_MAP` under key `'authorization'`
2. Add typed `authorization()` method returning `Authorization` with proper PHPDoc
3. Add `'fga' => env('WORKOS_FEATURE_FGA', false)` to `config/workos.php` features array
4. In `WorkOSServiceProvider`, register `Authorization` as a singleton (it's stateless, uses static Client calls)
5. Update `Facades/WorkOS.php` with `@method` annotation

**Failure Modes**:

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| Authorization service | API authentication failure | Invalid/expired API key | 401 from WorkOS | WorkOS\Client already throws `AuthenticationException`; let it propagate |
| Authorization service | Resource not found | Wrong ID or external ID | 404 from WorkOS | `NotFoundException` from Client; let callers handle |
| Authorization service | FGA not enabled for environment | Using FGA endpoints on a plan that doesn't include it | 403 or feature-gated response | `AuthorizationException` propagates; document in README |
| PaginatedResult | Empty result set | No resources match query | Empty `data` array | Not an error — return empty PaginatedResult |
| Value objects | Unexpected response shape | API version mismatch or SDK drift | Missing keys in fromResponse | Use null coalescing for optional fields; required fields throw if missing |

## Testing Requirements

### Unit Tests

| Test File | Coverage |
| --- | --- |
| `tests/Unit/Authorization/AuthorizationTest.php` | All service methods: CRUD, check, assign, discovery |
| `tests/Unit/Authorization/AuthorizationResourceTest.php` | Value object construction and serialization |

**Key test cases**:
- Create resource with required params only
- Create resource with parent (by ID and by external ID)
- Get resource by ID
- Get resource by external ID
- Update resource (name change)
- Delete resource (with and without cascade)
- List resources with filters
- Check authorization → true
- Check authorization → false
- Check with resource ID vs external ID
- Assign role to membership on resource
- Remove role assignment
- List role assignments
- List resources for membership (discovery)
- List memberships for resource (discovery)
- Value object construction from API response fixture
- PaginatedResult with empty data

### Manual Testing

- [ ] Call `WorkOS::authorization()->createResource(...)` in Tinker with real API key
- [ ] Call `WorkOS::authorization()->check(...)` and verify response

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| API key missing/invalid | `WorkOS\Client` throws `AuthenticationException` — propagate |
| Resource not found | `WorkOS\Client` throws `NotFoundException` — propagate |
| Bad request (missing params) | `WorkOS\Client` throws `BadRequestException` — propagate |
| FGA feature not enabled | `WorkOS\Client` throws appropriate exception — propagate |
| Network failure | `WorkOS\Client` throws `GenericException` — propagate |

All error handling delegates to the existing `WorkOS\Client` exception hierarchy. No custom exception wrapping needed.

## Validation Commands

```bash
# Run authorization tests
composer test -- --filter=Authorization

# Static analysis
composer analyse

# Code style
composer format

# All checks
composer test && composer analyse && composer format
```

## Rollout Considerations

- **Feature flag**: `config('workos.features.fga')` — defaults to false, must be explicitly enabled
- **Backwards compatibility**: No breaking changes — `authorization()` is additive; existing `hasPermission()`/`hasRole()` continue working from JWT
- **Monitoring**: N/A for service layer; consumers will add their own
- **Rollback plan**: Remove `features.fga` from config or set to false

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
