# Phase 5: Authorization (RBAC + FGA)

Follow `spec-template-feature-area.md`; inputs below. This delta fills every item in that template's "Delta Must Fill" list. Do not repeat template content — only phase-specific inputs, designs, and deviations follow.

## 1. Phase Header

| Field | Value |
|---|---|
| Phase | 5 of 13 |
| Title | Authorization (RBAC + FGA) |
| Risk | Medium (per contract) |
| Blocking | No |
| Prereqs (contract-declared) | Auth Core & Sealed Sessions (Phase 2) |
| Prereqs (this spec's actual data dependency — see §3a) | Organizations & Org Context (Phase 3) for the FGA membership resolver's data, NOT its API surface |
| Estimated Effort | **L** — six non-trivial components, two test paths (emulate + MockHandler), zero new migrations/routes (bounded blast radius), but real cross-phase integration risk (guard claims contract, memberships projection) that inflates review/verification time beyond a typical single-mechanism phase |

## 2. Scope Rows Implemented

Verbatim from `contract-data.json` → `scope.mvp`:

> **RBAC**: Gate::before reads permissions/roles claims — `$user->can()`, `@can`, policies work with zero-HTTP; role/permission management via facade.
> _Reason: Zero-HTTP authorization doctrine from JWT claims._

> **FGA**: explicit resource checks via direct Check API calls (no cache — a stale cache is a stale permission decision) + Gate integration for resource-model policies; `HasWorkosResource` trait syncs models as FGA resources.
> _Reason: The escalation path beyond role-shaped authz; caching is opt-in Full-tier with events-driven invalidation._

**Explicitly excluded from this phase** (per `scope.full` / Phase 12 "Depth Extensions"; do not build here):

- Groups API surface (group role assignments, `listGroupRoleAssignments`, `createGroupRoleAssignment`, etc.)
- FGA resource-graph conveniences: resource discovery (`listResourcesForMembership`), effective-permissions traversal (`listEffectivePermissions[ByExternalId]`), "who can access this" queries (`listMembershipsForResource[ByExternalId]`), parent-hierarchy nested traits
- Opt-in FGA check caching with events-driven invalidation

If an implementer is tempted to add any of the above "while they're in the file" — don't. They are Phase 12's job and have their own success criteria there.

## 3. Decisions Considered and Rejected

Carried from `contract-data.json` → `decisions` (relevance-filtered to this phase; all directly-touching entries included per instruction):

| Decision | Rejected Alternative | Reason | Why it binds this phase |
|---|---|---|---|
| RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API | Sync WorkOS roles/permissions into local spatie-style tables | Claims already ride the access token so checks are free; local tables duplicate canonical WorkOS state and drift | This is the phase's entire architecture — Gate::before must never query the network, and FGA must never cache |
| FGA ships without caching — direct Check API per check; opt-in caching with events-driven invalidation is Full tier | Default per-check cache in MVP | No stated latency requirement, and a stale cache entry is a stale permission decision without invalidation wiring | `Authkit::check()` and `FgaChecker` MUST NOT introduce any memoization, request-level or otherwise — see Failure Modes §9 (N+1 Check Calls) for the cost this accepts |
| Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id ↔ external_id` linking, refreshed by the events pipeline | No local state / read-through API calls per request | Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events | FGA's membership resolution reads the memberships projection (Phase 3's table) but Phase 5 must not create any new WorkOS-shaped table of its own — confirmed zero new migrations in this phase (§6) |
| Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state | Exchange code then hydrate Laravel's standard session guard | WorkOS must remain the session source of truth for both authn and authz; the SDK's SessionManager already does unseal/refresh/JWKS | Gate::before must read claims off the `workos` guard specifically, not `Auth::user()` against whatever the app's default guard happens to be — see Failure Modes §3 |
| Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage | SDK fakes only | Wire fidelity where possible; emulate v0.6.0 covers ~62% of endpoints | Determines the test-path split in §8 (check + role CRUD/assignment → emulate; resources + permissions CRUD → MockHandler) |
| Phase 1 ends with an empirical AuthKit token audit: confirm canonical `iss`/`aud` values and default presence of `role`/`permissions`/`feature_flags` claims, recorded in the decision log before Phase 2 starts | Assume the SDK's TODO values and default-populated claims | `SessionManager` defers `iss`/`aud` as unconfirmed, and zero-HTTP RBAC silently depends on claims being present without dashboard setup | `ClaimsGateHook`'s claim-key assumptions (`permissions[]`, `roles[]`) are provisional until that audit lands — flagged as Open Item, not silently assumed as fact |
| v1 targets the Full tier: MVP's 16 areas plus 5 depth extensions, folded into a dedicated Depth Extensions phase | MVP-only v1 with depth extensions deferred | Stakeholder tier selection at contract approval | Confirms the Groups/resource-graph/cache exclusions in §2 are deferred, not dropped — Phase 12 will build on top of the classes this phase creates |

## 3a. Cross-Phase Dependency Note (read before implementing)

The contract's `execution.phases[4].prereqs` lists only **Auth Core & Sealed Sessions**. That is accurate for the RBAC half of this phase (Gate::before needs nothing but the guard's decoded claims). It is **incomplete** for the FGA half: resolving an `organization_membership_id` from a permission/resource pair requires the memberships projection that Phase 3 (**Organizations & Org Context**) owns, and Phase 3 is not a declared prereq.

This spec does not silently assume Phase 3's table/column names (they aren't decided yet at time of writing). Instead it introduces a swappable contract (`ResolvesOrganizationMembershipId`, §5.4) with a safe, loud-failing default. Concretely:

- RBAC (Gate::before, RoleManager, PermissionManager) can be built and tested **today**, independent of Phase 3.
- FGA's `Authkit::check()` will build and unit-test today, but will **throw `MembershipNotResolvedException`** in real usage until something binds a real `ResolvesOrganizationMembershipId` implementation over the shipped `NullMembershipResolver` default — which is Phase 3's job, or a follow-up patch once Phase 3's actual schema is known.
- **Open Item for orchestration**: consider adding "Organizations & Org Context" to this phase's prereqs in the contract, or scheduling a small follow-up task after Phase 3 lands to bind the real resolver. Either way, ship Phase 5 now — RBAC's success criteria have zero dependency on Phase 3.

## 4. Components

Six components. All are non-trivial (they call the SDK boundary or change Gate resolution semantics) except the two DTOs/exceptions called out as trivial at the end.

### 4.1 `ClaimsGateHook` — RBAC's `Gate::before` (zero-HTTP)

**Laravel mechanism**: `Gate::before($callback)` registered in `AuthkitServiceProvider::boot()`.

**SDK methods wrapped**: none. This component never touches the network — it reads claims the `workos` guard (Phase 2) already decoded and verified while authenticating the request.

**Dependency on Phase 2 (explicit, named)**: this spec assumes the `workos` guard built in Phase 2 implements a new contract:

```php
namespace Authkit\Authkit\Contracts;

interface HasAccessTokenClaims
{
    /**
     * The decoded, signature-verified access-token claims for the current
     * request, or null if there is no authenticated session.
     *
     * @return array<string, mixed>|null
     */
    public function accessTokenClaims(): ?array;
}
```

**Before implementing**: read the actual `workos` guard class Phase 2 left in the repo (likely `src/Guards/WorkosGuard.php` or similar — grep for `implements Guard` under `src/`). If it doesn't yet implement `HasAccessTokenClaims`, add the implementation as part of this phase's work (it's a thin accessor over data the guard's `authenticate()`/`unsealData()` call already produced — no new unsealing, no new HTTP). If the guard's actual class/method names differ from what's assumed here, adapt the call site in `ClaimsGateHook`, not the guard's public contract elsewhere in the codebase.

**Assumed claim shape** (per canonical brief, provisional pending Phase 1's token audit — see §3 Open Item): `permissions: string[]`, `roles: string[]` (fallback to singular `role: string` if the audit finds no plural claim). `ClaimsGateHook` checks both defensively.

**Key design**:

```php
namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

final class ClaimsGateHook
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __invoke(?Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        $guard = Auth::guard('workos');

        if (! $guard instanceof HasAccessTokenClaims) {
            return null; // Phase 2's guard hasn't wired claims yet — never deny, let policies run
        }

        $claims = $guard->accessTokenClaims();

        if ($claims === null) {
            return null; // unauthenticated — nothing to short-circuit
        }

        $permissions = $claims['permissions'] ?? [];
        $roles = $claims['roles'] ?? (isset($claims['role']) ? [$claims['role']] : []);

        if (in_array($ability, $permissions, true) || in_array($ability, $roles, true)) {
            return true;
        }

        return null; // NEVER return false — see Failure Modes §1
    }
}
```

**Why never `false`** (verified against the vendored framework, not assumed): `Illuminate\Auth\Access\Gate::callBeforeCallbacks()` (`vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:558-569`) returns the **first non-null** result from any registered before-callback, and `Gate::raw()` (`Gate.php:428-453`) uses that result immediately, **skipping `callAuthCallback` (and therefore every policy) entirely** whenever a before-callback returns non-null. `false` is non-null. Returning `false` here would deny every ability for every authenticated user, globally, forever — not "deny this one claim mismatch." This is the load-bearing constraint of the whole component; see Failure Modes §1.

**Feedback loop**:
- Playground: `tests/Unit/Authorization/ClaimsGateHookTest.php` — instantiate `ClaimsGateHook` directly with a stub implementing `HasAccessTokenClaims`, no HTTP, no container.
- Experiment: swap the stub's returned claims array between test cases (`['permissions' => ['posts.edit']]`, `['roles' => ['admin']]`, `[]`, `null`) and assert the three possible return values (`true`/`null`, never `false`).
- Check: `vendor/bin/pest --filter=ClaimsGateHook` (sub-second — no boot of Testbench's HTTP kernel needed for the pure invocation cases; a thin Feature-level test also registers it via the real provider and asserts `Gate::before` is actually wired).

### 4.2 `RoleManager` — `Authkit::roles()`

**Laravel mechanism**: manager class resolved via `Authkit::roles()` (facade accessor method on the `Authkit` manager, per the shared convention — no new dedicated Facade class).

**SDK methods wrapped** (exact names, from `vendor/workos/workos-php/lib/Service/Authorization.php`):

| RoleManager method | SDK method |
|---|---|
| `environment(): array<Role>` | `listEnvironmentRoles()` |
| `createEnvironmentRole(...)` | `createEnvironmentRole(string $slug, string $name, ?string $description, ?string $resourceTypeSlug)` |
| `getEnvironmentRole(string $slug)` | `getEnvironmentRole(string $slug)` |
| `updateEnvironmentRole(...)` | `updateEnvironmentRole(string $slug, ?string $name, ?string $description)` |
| `addEnvironmentRolePermission(...)` | `addEnvironmentRolePermission(string $slug, string $bodySlug)` |
| `setEnvironmentRolePermissions(...)` | `setEnvironmentRolePermissions(string $slug, array $permissions)` |
| `forOrganization(string $organizationId): array<Role>` | `listOrganizationRoles(string $organizationId)` |
| `createOrganizationRole(...)` | `createOrganizationRole(string $organizationId, string $name, ?string $slug, ?string $description, ?string $resourceTypeSlug)` |
| `getOrganizationRole(...)` | `getOrganizationRole(string $organizationId, string $slug)` |
| `updateOrganizationRole(...)` | `updateOrganizationRole(string $organizationId, string $slug, ?string $name, ?string $description)` |
| `deleteOrganizationRole(...)` | `deleteOrganizationRole(string $organizationId, string $slug)` |
| `addOrganizationRolePermission(...)` | `addOrganizationRolePermission(string $organizationId, string $slug, string $bodySlug)` |
| `setOrganizationRolePermissions(...)` | `setOrganizationRolePermissions(string $organizationId, string $slug, array $permissions)` |
| `removeOrganizationRolePermission(...)` | `removeOrganizationRolePermission(string $organizationId, string $slug, string $permissionSlug)` |
| `assign(...)` | `assignRole(string $organizationMembershipId, string $roleSlug, ResourceTargetById|ResourceTargetByExternalId $resourceTarget)` |
| `remove(...)` | `removeRole(string $organizationMembershipId, string $roleSlug, ResourceTargetById|ResourceTargetByExternalId $resourceTarget)` |
| `removeAssignment(...)` | `removeRoleAssignment(string $organizationMembershipId, string $roleAssignmentId)` |
| `assignmentsFor(...)` | `listRoleAssignments(string $organizationMembershipId, ...)` |

**Confirmed SDK gap — do not "fix" it**: there is no `deleteEnvironmentRole` method anywhere in `Authorization.php`. Environment roles are undeletable via the API. `RoleManager` does not expose a delete for environment roles; adding one would require inventing a client-side no-op or throwing, which is speculative — just don't offer the method.

**Confirmed SDK constraint — drives the design below**: `assignRole`/`removeRole`'s `$resourceTarget` parameter is a **required, non-nullable** `ResourceTargetById|ResourceTargetByExternalId` union (`Authorization.php:210-232` for `check`, `:412-434` for `assignRole`). There is no "organization-level" variant. Assigning or changing a membership's **default org-level role** (the one that produces the `role`/`roles[]` JWT claim) is a different SDK surface entirely — `WorkOS\Service\OrganizationMembershipService::updateOrganizationMembership(string $id, RoleSingle|RoleMultiple|null $role)` (`OrganizationMembershipService.php:127-146`). That method lives on the organization-membership projection Phase 3 owns, not on the `Authorization` service. **Scope boundary**: `Authkit::roles()` wraps the `Authorization` service only — resource-scoped role assignment (project-level roles feeding FGA's ancestor-role inheritance), plus env/org role CRUD. Changing a membership's default org-level role is Phase 3's territory (it manages the memberships projection) and is intentionally not duplicated here.

**Key design** (`ResourceTarget` DTO — see §4.6 — keeps SDK types out of this class's public signature):

```php
namespace Authkit\Authkit\Authorization;

use WorkOS\Resource\Role;
use WorkOS\Resource\UserRoleAssignment;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;

final class RoleManager
{
    public function __construct(private readonly \Authkit\Authkit\Support\WorkosClientManager $clients) {}

    /** @return array<int, Role> */
    public function environment(): array
    {
        return $this->clients->client()->authorization()->listEnvironmentRoles()->data;
    }

    public function createEnvironmentRole(string $slug, string $name, ?string $description = null, ?string $resourceTypeSlug = null): Role
    {
        return $this->clients->client()->authorization()->createEnvironmentRole($slug, $name, $description, $resourceTypeSlug);
    }

    // ...getEnvironmentRole/updateEnvironmentRole/addEnvironmentRolePermission/setEnvironmentRolePermissions
    // mirror the table above 1:1 — thin pass-throughs, no branching logic. Each also carries the SDK's
    // trailing `?RequestOptions $options = null` the same way assign()/remove()/removeAssignment() do below.

    /** @return array<int, Role> */
    public function forOrganization(string $organizationId): array
    {
        return $this->clients->client()->authorization()->listOrganizationRoles($organizationId)->data;
    }

    // ...createOrganizationRole/getOrganizationRole/updateOrganizationRole/deleteOrganizationRole/
    // addOrganizationRolePermission/setOrganizationRolePermissions/removeOrganizationRolePermission
    // mirror the table above 1:1 — same trailing `?RequestOptions $options = null` pass-through.

    public function assign(string $organizationMembershipId, string $roleSlug, ResourceTarget $resource, ?RequestOptions $options = null): UserRoleAssignment
    {
        return $this->clients->client()->authorization()->assignRole(
            $organizationMembershipId,
            $roleSlug,
            $resource->toSdkTarget(),
            $options,
        );
    }

    public function remove(string $organizationMembershipId, string $roleSlug, ResourceTarget $resource, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->removeRole($organizationMembershipId, $roleSlug, $resource->toSdkTarget(), $options);
    }

    public function removeAssignment(string $organizationMembershipId, string $roleAssignmentId, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->removeRoleAssignment($organizationMembershipId, $roleAssignmentId, $options);
    }

    /** @return PaginatedResponse<UserRoleAssignment> */
    public function assignmentsFor(string $organizationMembershipId): PaginatedResponse
    {
        return $this->clients->client()->authorization()->listRoleAssignments($organizationMembershipId);
    }
}
```

**Idempotency, restated**: `assign()`/`remove()`/`removeAssignment()` take a trailing `?RequestOptions $options = null` — the same SDK type the vendored `Authorization` service already exposes on every one of these methods (`Authorization.php:412-488`) — so a caller retrying a form submission or job re-dispatch can pass `new RequestOptions(idempotencyKey: ...)` and get the SDK's existing idempotency mechanics for free. `RequestOptions` is a plain data-carrying value object (no behavior, no union type to hide), so accepting it directly here is consistent with this class's existing practice of returning SDK types (`Role`, `UserRoleAssignment`, `PaginatedResponse`) rather than wrapping every SDK shape — the `ResourceTarget` DTO exists specifically to hide the `ResourceTargetById|ResourceTargetByExternalId` *input* union, not as a blanket ban on SDK types in this class's signatures. See Failure Modes §12 for the one case (`HasWorkosResource`'s automatic hooks) this does not cover.

`WorkosClientManager` note: this spec assumes Phase 1 exposes the constructed SDK client via a `->client(): \WorkOS\WorkOS` method (per the shared template's description of the manager's responsibility). Confirm the exact accessor name against Phase 1's actual class in the repo before wiring — adapt call sites here if it differs (e.g. `->sdk()`), do not change the manager's public API to match this guess.

**Implementation steps**:
1. Read Phase 1's actual `WorkosClientManager` (or equivalent) to confirm the SDK-client accessor name.
2. Hand-author `src/Authorization/RoleManager.php` (no `php artisan make:` generator applies — this is a plain package service class outside the app namespace Laravel's generators target).
3. Add `roles(): RoleManager` to `src/Authkit.php` (container-resolved, not constructor-injected — see §7 for why).
4. Add `@method static RoleManager roles()` to the Facade docblock.

**Feedback loop**:
- Playground: `workos/emulate` (seeded with an organization + one custom org role via `workos-emulate.config.yaml`).
- Experiment: create → assign → list → remove, asserting each SDK call round-trips through emulate with the right shape.
- Check: `vendor/bin/pest --filter=Authorization` (emulate-backed suite, `tests/Feature/AuthorizationTest.php`).

### 4.3 `PermissionManager` — `Authkit::permissions()`

**Laravel mechanism**: manager class via `Authkit::permissions()`.

**SDK methods wrapped**: `listPermissions()`, `createPermission(string $slug, string $name, ?string $description, ?string $resourceTypeSlug)`, `getPermission(string $slug)`, `updatePermission(string $slug, ?string $name, ?string $description)`, `deletePermission(string $slug)` — all on `Authorization`, all environment-scoped (there is no org-scoped permission CRUD in the SDK; permissions are environment-global, optionally tagged with a `resourceTypeSlug`).

**Key design**:

```php
namespace Authkit\Authkit\Authorization;

use WorkOS\Resource\AuthorizationPermission;
use WorkOS\Resource\Permission;
use WorkOS\RequestOptions;

final class PermissionManager
{
    public function __construct(private readonly \Authkit\Authkit\Support\WorkosClientManager $clients) {}

    /** @return array<int, AuthorizationPermission> */
    public function all(): array
    {
        return $this->clients->client()->authorization()->listPermissions()->data;
    }

    public function create(string $slug, string $name, ?string $description = null, ?string $resourceTypeSlug = null, ?RequestOptions $options = null): Permission
    {
        return $this->clients->client()->authorization()->createPermission($slug, $name, $description, $resourceTypeSlug, $options);
    }

    public function get(string $slug): AuthorizationPermission
    {
        return $this->clients->client()->authorization()->getPermission($slug);
    }

    public function update(string $slug, ?string $name = null, ?string $description = null, ?RequestOptions $options = null): AuthorizationPermission
    {
        return $this->clients->client()->authorization()->updatePermission($slug, $name, $description, $options);
    }

    public function delete(string $slug, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->deletePermission($slug, $options);
    }
}
```

**Idempotency**: `create()`/`update()`/`delete()` take the same trailing `?RequestOptions $options = null` as the vendored `Authorization` service (`Authorization.php:1330-1418`), for the same reason as `RoleManager` above — a retried permission-CRUD call can carry an idempotency key.

**Implementation steps**: hand-author `src/Authorization/PermissionManager.php`; add `permissions(): PermissionManager` to `Authkit.php`; update Facade docblock.

**Feedback loop**:
- Playground: MockHandler (this is one of the "gaps" — see §8 for why).
- Experiment: queue a `201`/`200`/`404` response per method and assert `PermissionManager` sends the right verb/path/body and maps the response correctly, including the `system: true` permission case where `deletePermission` should surface the API's rejection rather than swallow it.
- Check: `vendor/bin/pest --filter=Authorization` (picks up `AuthorizationMockTest.php` too, since both files share the "Authorization" substring).

### 4.4 `FgaChecker` / `Authkit::check()`

**Laravel mechanism**: top-level method on the `Authkit` manager (per phase direction — `Authkit::check()`, not nested under a sub-accessor).

**SDK method wrapped**: `Authorization::check(string $organizationMembershipId, string $permissionSlug, ResourceTargetById|ResourceTargetByExternalId $resourceTarget): AuthorizationCheck` (`AuthorizationCheck::$authorized: bool`). **No batch check exists** — see Failure Modes §6.

**The membership-resolution contract** (see §3a for why this is a swappable seam, not a guess):

```php
namespace Authkit\Authkit\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ResolvesOrganizationMembershipId
{
    /**
     * Resolve the WorkOS organization_membership_id for the given (user, organization)
     * pair, or null if none exists (yet, or ever).
     */
    public function resolve(Authenticatable $user, string $organizationId): ?string;
}
```

Default binding (`bindIf`, so anything — Phase 3, the app, a later patch — can override without a container conflict):

```php
namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Illuminate\Contracts\Auth\Authenticatable;

final class NullMembershipResolver implements ResolvesOrganizationMembershipId
{
    public function resolve(Authenticatable $user, string $organizationId): ?string
    {
        return null;
    }
}
```

Named exception when resolution fails (fail loud, never fail silently-wrong):

```php
namespace Authkit\Authkit\Exceptions;

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use RuntimeException;

final class MembershipNotResolvedException extends RuntimeException
{
    public static function forContext(int|string $userId, string $organizationId): self
    {
        return new self(sprintf(
            'No WorkOS organization membership could be resolved for user [%s] in organization [%s]. '
            .'Bind %s to a real implementation once the memberships projection is available, '
            .'or pass organizationMembershipId explicitly to Authkit::check().',
            $userId,
            $organizationId,
            ResolvesOrganizationMembershipId::class,
        ));
    }
}
```

**Key design**:

```php
namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Exceptions\MembershipNotResolvedException;
use Authkit\Authkit\Support\WorkosClientManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use WorkOS\RequestOptions;

final class FgaChecker
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly ResolvesOrganizationMembershipId $membershipResolver,
    ) {}

    public function check(
        string $permissionSlug,
        string $resourceExternalId,
        string $resourceTypeSlug,
        ?string $organizationMembershipId = null,
        ?Authenticatable $user = null,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): bool {
        $membershipId = $organizationMembershipId ?? $this->resolveMembershipId($user, $organizationId);

        $result = $this->clients->client()->authorization()->check(
            $membershipId,
            $permissionSlug,
            ResourceTarget::byExternalId($resourceExternalId, $resourceTypeSlug)->toSdkTarget(),
            $options,
        );

        return $result->authorized;
    }

    private function resolveMembershipId(?Authenticatable $user, ?string $organizationId): string
    {
        $user ??= Auth::guard('workos')->user();
        $organizationId ??= $this->currentOrganizationIdFromClaims();

        $membershipId = $user !== null && $organizationId !== null
            ? $this->membershipResolver->resolve($user, $organizationId)
            : null;

        if ($membershipId === null) {
            throw MembershipNotResolvedException::forContext(
                $user?->getAuthIdentifier() ?? 'guest',
                $organizationId ?? 'unknown',
            );
        }

        return $membershipId;
    }

    private function currentOrganizationIdFromClaims(): ?string
    {
        $guard = Auth::guard('workos');

        if (! $guard instanceof HasAccessTokenClaims) {
            return null;
        }

        return $guard->accessTokenClaims()['org_id'] ?? null;
    }
}
```

`Authkit::check()` delegates to `app(FgaChecker::class)->check(...)` with the same signature (see §7 for why `Authkit` resolves this via the container rather than its constructor).

**Why `check()` also takes `?RequestOptions $options`, despite being a read**: `check()` has no side effects — retrying an identical check is naturally safe without an idempotency key — so this trailing parameter isn't closing an idempotency gap the way the mutating managers' `$options` do (see Failure Modes §12). It's here for SDK-signature parity (`Authorization.php:210-232`) and so a caller can still override per-call `timeout`/`maxRetries`/`extraHeaders` on the one FGA call this package makes without going through `WorkosClientManager`'s global defaults.

**No cache — by design, restated**: every call to `Authkit::check()` hits the network. This is the contract's explicit decision (§3); do not add request-level memoization "just to avoid duplicate calls in one request" — that's still a cache, and it's still out of scope. If a caller needs to check the same permission/resource pair twice in one request, that's a caller-side concern, not this component's.

**Feedback loop**:
- Playground: `workos/emulate` seeded with an organization, a user, an active organization membership, and a role/permission — per phase direction, check() is confirmed emulate-covered. **Open Item**: emulate's handling of arbitrary/undeclared `resource_type_slug` values on the `check` endpoint is not documented in the canonical brief's coverage notes (only "authorization... partial, no group endpoints" is confirmed). Verify empirically against a running emulate instance before relying on it; if emulate rejects undeclared resource types, downgrade this component's test path to MockHandler-only and update §8 accordingly.
- Experiment: assert `authorized: true` and `authorized: false` cases; assert the exact request body shape (`permission_slug`, `resource_external_id`, `resource_type_slug`) via emulate's request log or a MockHandler spy fallback.
- Check: `vendor/bin/pest --filter=Authorization`.

### 4.5 `HasWorkosResource` (trait) + `ResourceManager`

**Laravel mechanism**: Eloquent trait with model-event hooks (`created`, `deleted`), backed by a thin manager class.

**SDK methods wrapped**: `createResource(string $externalId, string $name, string $resourceTypeSlug, string $organizationId, ?string $description, ...)`, `deleteResourceByExternalId(string $organizationId, string $resourceTypeSlug, string $externalId, ?bool $cascadeDelete)`. Per phase direction, the trait's SDK footprint is exactly these two — no `getResourceByExternalId`/`updateResource*` wiring (not requested by the scope row; add only if a future phase names a real need).

**FGA resource types are Dashboard-configured only — do not build API-side type creation.** Per the live API facts: resource types (max 5 hierarchy levels, 50 types per environment) are configured exclusively in the WorkOS Dashboard; there is no create-type endpoint in the SDK. `HasWorkosResource` takes a type **slug** as a plain string the model must already know is Dashboard-registered — this trait has no way to validate that slug is real, and does not try. A typo here fails at `createResource` call time in production, not at development time (Failure Modes §10).

**Why the trait never persists the WorkOS resource's internal `id`**: the projection-boundary doctrine forbids new WorkOS-shaped local columns. That's why deletion goes through `deleteResourceByExternalId` (keyed by the model's own primary key as the external ID) rather than `deleteResource($resourceId)` — there is nowhere compliant to have stored that `$resourceId`.

**Key design**:

```php
namespace Authkit\Authkit\Authorization;

use WorkOS\Resource\AuthorizationResource;
use WorkOS\RequestOptions;

final class ResourceManager
{
    public function __construct(private readonly \Authkit\Authkit\Support\WorkosClientManager $clients) {}

    public function create(
        string $externalId,
        string $name,
        string $resourceTypeSlug,
        string $organizationId,
        ?string $description = null,
        ?RequestOptions $options = null,
    ): AuthorizationResource {
        return $this->clients->client()->authorization()->createResource(
            externalId: $externalId,
            name: $name,
            resourceTypeSlug: $resourceTypeSlug,
            organizationId: $organizationId,
            description: $description,
            options: $options,
        );
    }

    public function deleteByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        bool $cascadeDelete = false,
        ?RequestOptions $options = null,
    ): void {
        $this->clients->client()->authorization()->deleteResourceByExternalId(
            organizationId: $organizationId,
            resourceTypeSlug: $resourceTypeSlug,
            externalId: $externalId,
            cascadeDelete: $cascadeDelete,
            options: $options,
        );
    }
}
```

**Idempotency, and the gap this does not close**: `create()`/`deleteByExternalId()` take the same trailing `?RequestOptions $options = null` as the vendored SDK (`Authorization.php:942-971` for `createResource`, `:774-790` for `deleteResourceByExternalId`), so a caller invoking `Authkit::resources()->create(...)` directly can pass an idempotency key on retry. This does **not** by itself make `HasWorkosResource`'s automatic `created`/`deleted` hooks (below) idempotent — those call sites fire from Eloquent model events with no caller in the loop to supply a key. See Failure Modes §12.

```php
namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Facades\Authkit;
use Illuminate\Database\Eloquent\Model;

trait HasWorkosResource
{
    /**
     * The Dashboard-configured resource-type slug for this model. Must match
     * an existing type exactly — there is no API to create or validate one.
     */
    abstract public function workosResourceType(): string;

    /**
     * The WorkOS organization ID that owns this resource. Default assumes an
     * `organization()` relation exposing a `workos_id` column; override if
     * the model resolves its org differently.
     */
    public function workosResourceOrganizationId(): string
    {
        return $this->organization->workos_id;
    }

    public function workosResourceName(): string
    {
        return (string) ($this->name ?? $this->getKey());
    }

    protected static function bootHasWorkosResource(): void
    {
        static::created(function (Model $model): void {
            Authkit::resources()->create(
                externalId: (string) $model->getKey(),
                name: $model->workosResourceName(),
                resourceTypeSlug: $model->workosResourceType(),
                organizationId: $model->workosResourceOrganizationId(),
            );
        });

        static::deleted(function (Model $model): void {
            Authkit::resources()->deleteByExternalId(
                organizationId: $model->workosResourceOrganizationId(),
                resourceTypeSlug: $model->workosResourceType(),
                externalId: (string) $model->getKey(),
            );
        });
    }
}
```

The `abstract` method inside a trait is deliberate: any consuming model that forgets to implement `workosResourceType()` fails at **class-definition time** (PHP fatal error), not at first save — the earliest possible failure point PHP offers for this.

**Implementation steps**:
1. Hand-author `src/Authorization/ResourceManager.php`, `src/Concerns/HasWorkosResource.php`.
2. Add `resources(): ResourceManager` to `Authkit.php`.
3. Workbench fixture (for the workbench example + acceptance-suite grep target): `vendor/bin/testbench make:model Project -m` → edit the generated migration to add `name string`, `organization_id string` (plain string column storing the WorkOS org ID directly — **not** an FK to a workbench `Organization` model, since Phase 3 may not have landed a workbench `Organization` model yet when this phase executes; this keeps the fixture independent of Phase 3's timing). Add `use HasWorkosResource;` to the generated `Project` model, override `workosResourceType(): string { return 'project'; }` and `workosResourceOrganizationId(): string { return $this->organization_id; }`.

**Feedback loop**:
- Playground: MockHandler (resource CRUD is an unconfirmed emulate gap — see §8).
- Experiment: create a `Project`, assert a `POST authorization/resources` request fired with the right `external_id`/`resource_type_slug`/`organization_id`; delete it, assert `DELETE authorization/resources/{type}/{externalId}` fired.
- Check: `vendor/bin/pest --filter=Authorization` (MockHandler file).

### 4.6 `WorkosResourcePolicy` (abstract base class)

**Laravel mechanism**: abstract Policy base class apps extend, using PHP's `__call` magic method so undefined ability methods fall through to a generic FGA check — Laravel's Gate resolves policy callables via `is_callable([$policy, $method])` (`Gate.php:770`, `:833`), and PHP's `is_callable` returns `true` for any method name on a class defining `__call` (verified: `php -r 'class Foo{function __call($n,$a){return $n;}} var_dump(is_callable([new Foo,"bar"]));'` → `bool(true)`). This is a real PHP/Laravel mechanism, not a hopeful assumption.

**SDK methods wrapped**: none directly — delegates to `Authkit::check()` (§4.4).

**Key design**:

```php
namespace Authkit\Authkit\Policies;

use Authkit\Authkit\Concerns\HasWorkosResource;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Database\Eloquent\Model;

abstract class WorkosResourcePolicy
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $ability, array $arguments): bool
    {
        $resource = $arguments[1] ?? null;

        if (! $resource instanceof Model) {
            // Class-string ability checks (e.g. $user->can('create', Project::class))
            // have no instance to check against — see Failure Modes §7.
            return false;
        }

        if (! in_array(HasWorkosResource::class, class_uses_recursive($resource), true)) {
            throw new \LogicException(sprintf(
                '%s must use %s to be authorized by %s.',
                $resource::class,
                HasWorkosResource::class,
                static::class,
            ));
        }

        return Authkit::check(
            permissionSlug: $this->permissionSlugFor($ability),
            resourceExternalId: (string) $resource->getKey(),
            resourceTypeSlug: $resource->workosResourceType(),
        );
    }

    /**
     * Override to map ability names to different permission slugs.
     * Default: the ability name IS the permission slug (e.g. `view` ability
     * checks the `view` permission slug on the resource).
     */
    protected function permissionSlugFor(string $ability): string
    {
        return $ability;
    }
}
```

Note the deliberate omission of `$user` from the `Authkit::check()` call: the `$user` Laravel's Gate passes into a policy method **is** the currently-authenticated guard user, so it is redundant with (and could only introduce inconsistency against) `FgaChecker`'s own current-context resolution. Explicit `$user`/`$organizationId` overrides on `Authkit::check()` remain available for non-HTTP contexts (queues/console) that call it directly, outside a Policy.

**Implementation steps**:
1. Hand-author `src/Policies/WorkosResourcePolicy.php`.
2. Workbench fixture: `vendor/bin/testbench make:policy ProjectPolicy --model=Project`, then edit it to `extends WorkosResourcePolicy` and delete the generated boilerplate methods (letting `__call` handle everything), keeping the class body otherwise empty except an optional `permissionSlugFor` override if the demo wants non-identity mapping.
3. Register the policy in the workbench's own `AuthServiceProvider`/policy discovery (Laravel's convention-based policy discovery should find `ProjectPolicy` for `Project` automatically; no explicit `Gate::policy()` call needed unless workbench's naming diverges from convention).

**Feedback loop**:
- Playground: MockHandler (inherits `Authkit::check()`'s wire path).
- Experiment: `$user->can('view', $project)` with the mock returning `authorized: true` then `false`; `$user->can('create', Project::class)` (class-string form) always denies — assert that explicitly, don't just leave it untested.
- Check: `vendor/bin/pest --filter=Authorization`.

### Trivial components (no feedback loop — config keys / DTOs / enums)

- `Authkit\Authkit\Authorization\ResourceTarget` — DTO wrapping the SDK's `ResourceTargetById`/`ResourceTargetByExternalId` union at the package boundary:

```php
namespace Authkit\Authkit\Authorization;

use WorkOS\Service\ResourceTargetById;
use WorkOS\Service\ResourceTargetByExternalId;

final class ResourceTarget
{
    private function __construct(
        private readonly ?string $resourceId,
        private readonly ?string $externalId,
        private readonly ?string $typeSlug,
    ) {}

    public static function byId(string $resourceId): self
    {
        return new self($resourceId, null, null);
    }

    public static function byExternalId(string $externalId, string $typeSlug): self
    {
        return new self(null, $externalId, $typeSlug);
    }

    /** @internal package-boundary conversion only */
    public function toSdkTarget(): ResourceTargetById|ResourceTargetByExternalId
    {
        return $this->resourceId !== null
            ? new ResourceTargetById($this->resourceId)
            : new ResourceTargetByExternalId($this->externalId, $this->typeSlug);
    }
}
```

- `Authkit\Authkit\Exceptions\MembershipNotResolvedException` — shown in §4.4, plain constructor-static-factory exception.
- `Authkit\Authkit\Contracts\HasAccessTokenClaims`, `Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId` — interfaces, shown above.
- New `config/authkit.php` keys — no logic, see §6.

## 5. Facade / Manager Diff

Add to `src/Authkit.php` (container-resolved, deliberately **not** constructor-injected):

```php
namespace Authkit\Authkit;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\PermissionManager;
use Authkit\Authkit\Authorization\ResourceManager;
use Authkit\Authkit\Authorization\RoleManager;
use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\RequestOptions;

class Authkit
{
    // ...Phase 1/3's existing members remain untouched...

    public function roles(): RoleManager
    {
        return app(RoleManager::class);
    }

    public function permissions(): PermissionManager
    {
        return app(PermissionManager::class);
    }

    public function resources(): ResourceManager
    {
        return app(ResourceManager::class);
    }

    public function check(
        string $permissionSlug,
        string $resourceExternalId,
        string $resourceTypeSlug,
        ?string $organizationMembershipId = null,
        ?Authenticatable $user = null,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): bool {
        return app(FgaChecker::class)->check(
            $permissionSlug,
            $resourceExternalId,
            $resourceTypeSlug,
            $organizationMembershipId,
            $user,
            $organizationId,
            $options,
        );
    }
}
```

**Why container resolution, not constructor injection**: this phase does not know Phase 1's (or Phase 3's) final constructor signature for `Authkit`, and forcing new constructor parameters onto a class three other phases also touch is exactly the kind of cross-phase collision this spec should avoid causing. `app(...)` resolution keeps this phase's diff to `Authkit.php` additive-only. **Before merging**, grep the current `Authkit.php` for method-name collisions with `roles`/`permissions`/`resources`/`check` (Phase 3 in particular might have already claimed `roles()` for something org-related) — rename on collision, don't silently overwrite.

Facade docblock addition (`src/Facades/Authkit.php`):

```php
/**
 * @method static \Authkit\Authkit\Authorization\RoleManager roles()
 * @method static \Authkit\Authkit\Authorization\PermissionManager permissions()
 * @method static \Authkit\Authkit\Authorization\ResourceManager resources()
 * @method static bool check(string $permissionSlug, string $resourceExternalId, string $resourceTypeSlug, ?string $organizationMembershipId = null, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, ?string $organizationId = null, ?\WorkOS\RequestOptions $options = null)
 *
 * @see \Authkit\Authkit\Authkit
 */
```

## 6. File Changes

### New — `src/`

| Path | Traces to |
|---|---|
| `src/Authorization/RoleManager.php` | Scope: "role/permission management via facade" |
| `src/Authorization/PermissionManager.php` | Scope: "role/permission management via facade" |
| `src/Authorization/ResourceManager.php` | Scope: "HasWorkosResource trait syncs models as FGA resources" |
| `src/Authorization/FgaChecker.php` | Scope: "explicit resource checks via direct Check API calls" |
| `src/Authorization/ResourceTarget.php` | Supports RoleManager/FgaChecker without leaking SDK types |
| `src/Authorization/ClaimsGateHook.php` | Scope: "Gate::before reads permissions/roles claims" |
| `src/Authorization/NullMembershipResolver.php` | §3a cross-phase seam |
| `src/Contracts/HasAccessTokenClaims.php` | Dependency contract on Phase 2's guard |
| `src/Contracts/ResolvesOrganizationMembershipId.php` | Dependency contract on Phase 3's memberships projection |
| `src/Exceptions/MembershipNotResolvedException.php` | Fail-loud requirement, §3a |
| `src/Concerns/HasWorkosResource.php` | Scope: "HasWorkosResource trait" |
| `src/Policies/WorkosResourcePolicy.php` | Scope: "Gate integration for resource-model policies" |

### Modified — `src/`

| Path | Change | Traces to |
|---|---|---|
| `src/Authkit.php` | Add `roles()`, `permissions()`, `resources()`, `check()` accessor methods | §5 |
| `src/Facades/Authkit.php` | Add `@method` docblock entries | §5 |
| `src/AuthkitServiceProvider.php` | `bindIf(ResolvesOrganizationMembershipId::class, config('authkit.authorization.membership_resolver', NullMembershipResolver::class))` in `register()`; `Gate::before($this->app->make(ClaimsGateHook::class))` in `boot()` | §7 |
| The Phase-2-authored `workos` guard class (exact path TBD — grep `src/Guards/` or wherever Phase 2 landed it) | Implement `HasAccessTokenClaims::accessTokenClaims(): ?array` if not already present | §4.1 dependency |

### `config/`

| Path | Change | Traces to |
|---|---|---|
| `config/authkit.php` | Add `'authorization' => ['membership_resolver' => \Authkit\Authkit\Authorization\NullMembershipResolver::class]` — this is the class the provider's `bindIf` call actually reads (see §7); an app overrides FGA's membership resolution by publishing `config/authkit.php` and changing this key to its own `ResolvesOrganizationMembershipId` implementation, no service-provider override needed, matching the config-only-credentials doctrine's spirit of "configure, don't subclass" | §3a |

### `database/`

**No new migrations.** Confirmed zero new local WorkOS-shaped state — RBAC reads claims only, FGA reads/writes the WorkOS Authorization graph only, membership resolution reads Phase 3's existing projection. This is worth stating explicitly because it's the cleanest possible compliance with the projection-boundary arch test — nothing to whitelist, nothing to review for scope creep.

### `tests/`

| Path | Test path | Traces to |
|---|---|---|
| `tests/Unit/Authorization/ClaimsGateHookTest.php` | Zero-HTTP (stub `HasAccessTokenClaims`) | §4.1 |
| `tests/Unit/Authorization/ResourceTargetTest.php` | Zero-HTTP (pure DTO conversion) | §4.6 trivial component, kept simple |
| `tests/Feature/AuthorizationTest.php` | emulate | RoleManager CRUD/assignment, FgaChecker check() |
| `tests/Feature/AuthorizationMockTest.php` | MockHandler | PermissionManager CRUD, ResourceManager create/delete, HasWorkosResource trait, WorkosResourcePolicy |

### `workbench/`

| Path | Change | Traces to |
|---|---|---|
| `workbench/app/Models/Project.php` | New (via `testbench make:model Project -m`), uses `HasWorkosResource` | §4.5 fixture |
| `workbench/database/migrations/xxxx_create_projects_table.php` | New — `name`, `organization_id` (plain string, see §4.5 for why not an FK) | §4.5 fixture |
| `workbench/app/Policies/ProjectPolicy.php` | New (via `testbench make:policy`), extends `WorkosResourcePolicy` | §4.6 fixture |

## 7. Service Provider Registration Diff

`AuthkitServiceProvider::register()` — add:

```php
$this->app->bindIf(
    \Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId::class,
    config(
        'authkit.authorization.membership_resolver',
        \Authkit\Authkit\Authorization\NullMembershipResolver::class,
    ),
);
```

Reading the concrete class name from `config('authkit.authorization.membership_resolver')` (§6) is what makes the config key's override claim true — an app that publishes `config/authkit.php` and changes this key gets a different resolver bound with no service-provider override. The `NullMembershipResolver::class` default in the `config()` call's second argument only applies if the config key itself is somehow absent (e.g. package tests booting without the full config merged); the published config file's own default value is what ships to consuming apps.

`AuthkitServiceProvider::boot()` — add (inside the existing method, order-independent relative to the current publishing/console-only block):

```php
\Illuminate\Support\Facades\Gate::before(
    $this->app->make(\Authkit\Authkit\Authorization\ClaimsGateHook::class)
);
```

No new commands, routes, views, translations, or publish tags. `RoleManager`/`PermissionManager`/`ResourceManager`/`FgaChecker` are not explicitly bound — they're resolved via Laravel's reflection-based auto-wiring on demand from `Authkit.php`, since they carry no state worth sharing across a request and adding explicit `singleton()` calls for them would be ceremony without benefit.

## 8. Testing Requirements

Per the shared template's Test-Path Selection, cross-referenced with this phase's explicit direction ("emulate covers check + roles partially (no group endpoints); MockHandler for the gaps"):

| Component | Test path | Why |
|---|---|---|
| `ClaimsGateHook` | Zero-HTTP unit test | Never touches the network by design — the test itself is the enforcement mechanism (see below) |
| `RoleManager` (env/org CRUD, assign/remove) | emulate | Explicitly confirmed covered by phase direction |
| `FgaChecker`/`Authkit::check()` | emulate | Explicitly confirmed covered by phase direction ("check... "), with the resource-type-validation caveat noted in §4.4 |
| `PermissionManager` | MockHandler | Not confirmed covered ("the gaps"); permissions CRUD isn't called out in the brief's emulate coverage notes |
| `ResourceManager` / `HasWorkosResource` | MockHandler | Not confirmed covered; resource CRUD isn't called out either |
| `WorkosResourcePolicy` | MockHandler | Inherits `FgaChecker`'s wire path, but exercised through a Policy/Gate call rather than direct injection, so it's tested where the mock is already wired (`AuthorizationMockTest.php`) |

**Zero-HTTP enforcement technique for `ClaimsGateHook`**: construct the test's `WorkosClientManager` (or whatever DI path a stray call would go through) with an **empty** `GuzzleHttp\Handler\MockHandler` queue. An empty `MockHandler` throws `OutOfBoundsException: Mock queue is empty` on any request attempt. If `ClaimsGateHook` — or anything it calls — ever makes an HTTP call, the test fails loudly with that exception; if it genuinely stays claims-only, the test passes with the queue untouched. This is a stronger assertion than "assert the response equals X" — it structurally proves the zero-HTTP claim.

**Key cases per suite**:

`tests/Unit/Authorization/ClaimsGateHookTest.php`:
- returns `true` when ability matches a `permissions[]` claim entry
- returns `true` when ability matches a `roles[]` claim entry
- returns `true` when ability matches the singular `role` claim fallback
- returns `null` (not `false`) when claims exist but nothing matches
- returns `null` when the guard doesn't implement `HasAccessTokenClaims`
- returns `null` when there's no authenticated session (`accessTokenClaims()` returns `null`)
- **never returns `false` under any input** — assert this as its own explicit case, not just implied by the above

`tests/Feature/AuthorizationTest.php` (emulate):
- `RoleManager`: create org role → assign to a seeded membership → `assignmentsFor()` lists it → remove → list no longer contains it
- `RoleManager`: environment role CRUD round-trip (no delete — confirm the method genuinely doesn't exist, e.g. via a `method_exists` assertion, so a future SDK bump adding one doesn't silently go unnoticed)
- `FgaChecker::check()`: seeded membership + role/permission → `authorized: true`; a different permission slug the role doesn't grant → `authorized: false`
- `FgaChecker::check()`: `MembershipNotResolvedException` thrown when no membership resolver is bound and no explicit `organizationMembershipId` is passed (this exercises the §3a seam directly — bind `NullMembershipResolver` explicitly in this test rather than relying on Phase 3 having landed)

`tests/Feature/AuthorizationMockTest.php` (MockHandler):
- `PermissionManager`: create/get/update/delete round-trip; delete of a `system: true` permission surfaces the API's error rather than being silently swallowed
- `ResourceManager::create`/`deleteByExternalId`: correct request shape asserted against the mock's recorded request
- `HasWorkosResource`: creating a `Project` fires exactly one `POST authorization/resources` call with the right body; deleting fires exactly one `DELETE .../resources/{type}/{externalId}` call
- `HasWorkosResource` + `SoftDeletes` (if the workbench fixture is given `SoftDeletes` for this one test): confirm the `deleted` event — and therefore the WorkOS resource deletion — fires on soft-delete too (documents Failure Modes §8, doesn't fix it)
- `WorkosResourcePolicy`: `$user->can('view', $project)` → `true`/`false` mapped from the mocked `authorized` value
- `WorkosResourcePolicy`: `$user->can('create', Project::class)` (class-string form) → `false`, and asserts **zero** HTTP calls were made for this case (no resource instance means no check should even be attempted)
- `WorkosResourcePolicy`: a model without `HasWorkosResource` throws the documented `LogicException`

**Seed data**: `workos-emulate.config.yaml` additions for this phase — one organization, one user, one active organization membership linking them, one custom org role (`org-editor`) with one permission (`posts.edit`) attached. Reuse Phase 1/3's existing seed organization/user if already present rather than duplicating.

**Open Item**: whether `workos-emulate.config.yaml`'s seed schema supports organization memberships as a seedable primitive at all is unconfirmed. The canonical brief's seed-schema inventory (`users, organizations(+domains), webhookEndpoints, connections, invitations, apiKeys, roles/permissions, jwtTemplate, connectApplications`) never lists memberships explicitly — "one active organization membership linking them" above is written as a plain fact but is not actually confirmed against a running emulate instance. It's possible memberships are seedable only implicitly (e.g. nesting a seeded user under an organization's seed block) rather than as their own top-level list; that distinction matters because `FgaChecker`'s entire emulate-backed test path (§4.4, §8) depends on a seeded membership existing. Verify empirically before relying on it — the same verification discipline §4.4 already applies to the `resource_type_slug` gap. **Fallback**: if emulate's config schema has no way to seed (explicitly or implicitly) an active organization membership, downgrade `FgaChecker::check()`'s and `RoleManager::assign()`/`assignmentsFor()`'s membership-dependent test cases to MockHandler and update the test-path table in this section accordingly; the role/permission CRUD cases that don't require a seeded membership can stay on emulate.

## 9. Failure Modes

Named, specific failures — not "handle errors generically."

| # | Failure | Trigger | Behavior / Detection | Mitigation |
|---|---|---|---|---|
| 1 | **Before-callback false short-circuit** | `ClaimsGateHook` (or a future edit to it) returns `false` instead of `null` on a claims mismatch | Verified against `Gate.php:558-569`/`:428-453`: a non-null before-result — including `false` — skips every registered policy for every ability, globally, immediately | Explicit unit test case asserting `false` is never returned under any input (§8); code review checklist item; never add an `else { return false; }` branch to this class |
| 2 | **Ability/permission-slug collision** | An app names a Gate ability the same as an unrelated WorkOS permission slug present in the user's claims (e.g. app ability `update`, WorkOS permission slug also `update` on a different resource type) | `ClaimsGateHook` grants blanket access via the claim match before any resource-specific Policy ever runs — the Policy's own logic is never reached for that ability name, for any resource, for that user | Document prominently in the RBAC vs FGA guidance: use distinct ability names for resource-scoped checks (route them through `WorkosResourcePolicy`/`Authkit::check()`), reserve plain `$user->can('slug')` calls for genuinely global permissions |
| 3 | **Wrong default guard** | `config('auth.defaults.guard')` isn't set to `workos` when `Gate::resolveUser()` runs | `ClaimsGateHook` receives the wrong (or null) `$user`; `Auth::guard('workos')` inside the hook is guard-name-explicit so it still finds the right claims *if* a workos session exists independently of the resolved `$user` — but the ability check's `$user` argument may not match, producing confusing partial behavior rather than a clean failure | Phase 2's responsibility to set the default guard; this phase's integration test should assert end-to-end behavior with the real default-guard config, not just a hand-constructed `ClaimsGateHook` in isolation |
| 4 | **Stale role/permission claims post-assignment** | `Authkit::roles()->assign()`/`removeRole()` changes a membership's roles, but the affected user's already-issued access token still carries the old `permissions[]`/`roles[]` claims until their session refreshes (Phase 2's refresh cycle) | `$user->can()` continues to reflect pre-change state for the remainder of the token's lifetime | Document as bounded staleness (same doctrine as feature flags); do not attempt to force-invalidate sessions from this phase — that's a Phase 2/refresh-cycle concern, out of scope here |
| 5 | **Unresolved membership / projection lag** | A just-created user+org pair hasn't synced through Phase 3's events pipeline yet when `Authkit::check()` is called | Without the fail-loud design in §4.4, this would either call the Check API with a garbage ID (400) or silently return `false` indistinguishable from a legitimate deny | `MembershipNotResolvedException` is thrown by name, distinct from a `false` authorization result — callers can catch it specifically and choose to retry, queue, or surface a "still setting up your account" state |
| 6 | **N+1 Check API calls** | No batch-check endpoint exists (confirmed absent from the SDK), and no cache exists (contract decision) — rendering a list of N resources with a per-row `@can` check issues N synchronous HTTP round-trips | Directly visible as N sequential requests in any list view using `@can`/`Authkit::check()` per row; will dominate response time for any non-trivial list length | Document the cost explicitly wherever `Authkit::check()` is introduced in package docs; recommend RBAC (Gate::before, zero-HTTP) for list-wide capability gating and reserve FGA checks for single-resource views/actions; batch/list-scoped resource discovery is explicitly Phase 12's job, not a workaround to improvise here |
| 7 | **Class-string ability miss** | `$user->can('create', Project::class)` — Gate strips the leading class-string argument (`Gate.php:829-831`) before calling the policy method, so `WorkosResourcePolicy::__call` receives no resource instance | Always resolves to deny (`false`) since there is no external ID to check | Document as expected: FGA cannot authorize the creation of a resource that doesn't exist yet — pair `create`-style abilities with a plain RBAC permission claim (§4.1) instead of routing them through a `WorkosResourcePolicy` |
| 8 | **Soft-delete divergence** | A model using both `SoftDeletes` and `HasWorkosResource` gets soft-deleted | Eloquent's `deleted` event fires identically for soft and hard deletes, so the WorkOS FGA resource is deleted from the authorization graph even though the local row still exists (soft-deleted, potentially restorable) | Documented gotcha, not fixed in this phase (would require also listening to `restored` and distinguishing `forceDeleted`, which is unrequested scope); apps combining both traits must override the trait's boot hooks themselves |
| 9 | **Org-level assignment misuse** | A caller tries to use `Authkit::roles()->assign()` to set a membership's default org-level role, expecting it to behave like "no resource target needed" | `assign()` requires a non-null `ResourceTarget` argument (PHP will raise a `TypeError` at the call site, not a WorkOS API error) since the underlying `assignRole` SDK method has no organization-level variant | Documented scope boundary (§4.2): default org-level role changes go through Phase 3's `OrganizationMembership` surface, not `Authkit::roles()` |
| 10 | **Undeclared Dashboard resource type** | A model's `workosResourceType()` returns a slug that was never configured in the WorkOS Dashboard | `createResource` fails at the first model creation in production, not at development/CI time (there's no local way to validate the slug against the Dashboard's live catalog) | Documented in `HasWorkosResource`'s docblock; package docs should tell integrators to configure the type in the Dashboard *before* attaching the trait, and treat the resulting `WorkOSException` as an actionable "resource type not found" signal rather than a generic failure |
| 11 | **WorkOS Authorization API down (retries exhausted)** | 5xx from the Authorization service outlasts the SDK's built-in retry/backoff | `Authkit::check()` propagates `WorkOS\Exception\WorkOSException` uncaught — every FGA-gated action fails loudly (visible 500) rather than silently granting or silently denying | Deliberate: fail-loud over fail-silent for an authorization decision. Apps wanting graceful degradation must catch `WorkOSException` themselves at the call site; this phase does not add a try/catch that could mask an outage as a deny |
| 12 | **Duplicate mutating call on retry (no idempotency key)** | A form re-submit, job re-dispatch, or client retry calls `Authkit::roles()->assign()`/`remove()`/`removeAssignment()`, `Authkit::permissions()->create()`/`update()`/`delete()`, or `Authkit::resources()->create()`/`deleteByExternalId()` a second time for the same operation | Every one of these SDK methods (`Authorization.php`) takes a trailing `?RequestOptions $options` carrying `idempotencyKey` — until this phase threads it through, this package's wrapper methods had no way to expose it, so a caller had no way to make a retried mutation safe through this package's API at all | `RoleManager`/`PermissionManager`/`ResourceManager` now accept a trailing `?RequestOptions $options = null` on every mutating method (§4.2/§4.3/§4.5), matching the SDK 1:1 — callers pass `new RequestOptions(idempotencyKey: ...)` on retry. **Gap that remains, by design**: `HasWorkosResource`'s automatic `created`/`deleted` Eloquent hooks (§4.5) call `ResourceManager::create()`/`deleteByExternalId()` with no `$options` argument — there is no caller-supplied value to thread through an automatic model-event hook. A model re-save that re-fires `created` (e.g. a queued job retry around `Model::create()`) has no idempotency key from this trait alone. Deriving one automatically (e.g. `sprintf('%s:%s:created', $model::class, $model->getKey())`) is deferred as unrequested scope beyond what this phase's trait needs — named here, not silently absorbed, the same way §8's Soft-delete divergence is named rather than fixed. Whether the Authorization API's specific endpoints honor `Idempotency-Key` the same way `AuditLogs::createEvent` is confirmed to (brief, SDK inventory) is unverified for this service — confirm against live docs before advertising it as a guaranteed dedup mechanism in package documentation |

## 10. Deviations from Template

- The template's Shared Conventions table lists no `Concerns/` folder convention explicitly; this phase introduces `src/Concerns/` for `HasWorkosResource` as the idiomatic Laravel-package location for Eloquent trait hooks (mirrors `Illuminate\Database\Eloquent\Concerns`). Later phases adding `HasWorkosUser`, `HasWorkosOrganization`, `HasAuditLogs`, `HasApiKeys` should follow the same folder for consistency — noted here for whoever writes those deltas, not binding on them.
- This phase adds two new `Contracts/` interfaces (`HasAccessTokenClaims`, `ResolvesOrganizationMembershipId`) that reach into Phase 2's and Phase 3's territory respectively. This is a deliberate, documented seam (§3a, §4.1) rather than an undocumented assumption — flagged, not silently absorbed into "the shared technical approach."
- Otherwise: None. SDK access goes through the manager per convention; no new local WorkOS-shaped state; registration lives in the existing provider; facades stay limited to `Authkit`.

## 11. Feedback Strategy

**Inner-loop command** (seconds, scoped to this phase):

```bash
vendor/bin/pest --filter=Authorization
```

Matches all four Authorization-area test files (`ClaimsGateHookTest`, `ResourceTargetTest`, `AuthorizationTest`, `AuthorizationMockTest`) via Pest's substring filter on file/describe names, provided naming keeps "Authorization" in each. For the tightest possible loop while iterating on just the RBAC hook (no Testbench HTTP kernel boot needed for the pure-invocation cases):

```bash
vendor/bin/pest tests/Unit/Authorization/ClaimsGateHookTest.php
```

**Playgrounds**: `workos/emulate` (Phase 1's helper: `EmulateServer::start()` or equivalent, seeded per §8) for `RoleManager` and `FgaChecker`; `GuzzleHttp\Handler\MockHandler` injected through `WorkosClientManager`'s handler hook for `PermissionManager`, `ResourceManager`, `HasWorkosResource`, `WorkosResourcePolicy`.

**Why**: every component here is either pure claims-reading (no wire, unit-testable in isolation) or a thin SDK wrapper (feature-testable against a fake or emulated wire) — there's no component in this phase that needs a slower end-to-end loop to get useful signal.

## 12. Rollout

No feature flags. Lands green on `composer test` or doesn't land. Rollback = `git revert` of this phase's commit(s).

## Validation Commands

```bash
composer analyse                          # PHPStan (larastan)
composer lint:check                       # Pint check-only
composer test:types                       # Pest type coverage --min=100
vendor/bin/pest --filter=Authorization     # this phase's suites
composer test                             # full chain — must be green before commit
```
