# Phase 8 — API Keys Guard

Follow `spec-template-feature-area.md`; inputs below. This is a delta spec — it does not repeat the template's Shared Technical Approach, Shared Conventions, Test-Path Selection, Shared Feedback Strategy, Standard Validation Commands, or Shared Failure-Mode Prompts. Read the template first.

**Phase number / title**: Phase 8 — API Keys Guard
**Prereq phases**: Organizations & Org Context (Phase 3), Authorization (RBAC + FGA) (Phase 5)
**Contract risk rating**: medium
**Estimated Effort**: **M**

---

## 0. Assumed Prior-Phase Surface

This delta is written before Phases 1, 3, and 5 have their own spec files committed (only the contract, the canonical context brief, and this template existed at write time). To stay standalone-implementable, this spec makes the following explicit assumptions about what those phases will have shipped by the time Phase 8 is implemented. If the executing agent finds any of these don't hold, treat it as an integration bug against the earlier phase's spec, not license to invent new package-wide surface here.

| Assumption | Source phase | If it doesn't hold |
|---|---|---|
| `Authkit::client()` returns the bound `WorkOS\WorkOS` SDK client instance (config-driven, base-URL-overridable, MockHandler-injectable) | Phase 1 (client binding) | Replace every `Authkit::client()->apiKeys()` / `Authkit::client()->userManagement()` call below with whatever single accessor Phase 1 actually lands — the method-chain shape after that point is unchanged. |
| `config('auth.providers.users.model')` names the consumer's User model FQCN (Laravel's own stock config key, not an Authkit-specific one) | Laravel default / Phase 2 | If Phase 2 introduces its own `config('authkit.user_model')`, use that instead — same lookup shape. |
| The User model has a `workos_id` string column and the `HasWorkosUser` trait applied | Phase 2 (Users) | User-scoped key principal resolution (§3.2) has nothing to match against; this is a hard blocker, not a workaround situation. |
| `config('authkit.organization_model')` names the consumer's org model FQCN; that model has a `workos_id` string column and the `HasWorkosOrganization` trait applied | Phase 3 (Organizations) | Org-scoped key principal resolution (§3.2) has nothing to match against; hard blocker. |
| `config/authkit.php` exists (renamed from `config/authkit-laravel.php`) and is merged via `mergeConfigFrom` in `AuthkitServiceProvider::register()` | Phase 1 | Add the `api_keys` config block (§3.8) to whichever config file Phase 1 actually ships. |
| Phase 5 registers its own `Gate::before` callback reading JWT `permissions`/`roles` claims off the session-guard User, and does **not** assume it is the only `Gate::before` callback in the app | Phase 5 (Authorization) | Not required for this phase to function — see §3.4, Phase 8 registers an independent, self-contained `Gate::before` that only fires for API-key-authenticated principals. It composes with Phase 5's regardless of what Phase 5 does internally. |

No other cross-phase coupling exists. Phase 8 adds **zero new migrations and zero new local tables** — it resolves against the User and Organization projections that Phases 2 and 3 already created.

---

## 1. Scope

Verbatim from the contract's `scope.mvp` table:

> **API Keys**: authkit-key guard driver validating via the validate endpoint and loading key permissions into Gate; issue/revoke on user and org models
> Reason: In the founder's literal MVP list; user and org API keys rolled into the same authorization system.

No Full-tier items name API Keys — the Full-tier depth-extension list (Invitations, JWT templates + CORS, Groups API, FGA resource-graph conveniences, opt-in FGA caching) does not touch this area. Nothing from Full tier is in scope for this phase.

**Scope-expansion flag**: the contract's scope row above and `execution.phases[7].notes` in `contract-data.json` ("issue/revoke APIs on user + org models") both name exactly two write verbs. §3.7 below adds a third WorkOS write operation, `expireApiKey()` (`ApiKeys::createApiKeyExpire`), whose only cited justification (§4's File Changes table) is an unquoted "phase direction" source. Checked directly: neither `contract-data.json` nor its rendered `contract.html` mentions "expire" anywhere in the API Keys scope row, the decision log, or the phase-8 execution notes. This is **not confirmed in-scope** — flagged as scope-expansion pending sign-off rather than cut outright, so the capability isn't silently dropped if a phase-direction source elsewhere does support it. Implementers: confirm against the actual phase-direction/ideation transcript before building `expireApiKey()`; if it can't be confirmed, cut the method (§3.7), its test case (§6), and its file-trace row (§4) rather than shipping unapproved scope. See §11 Open Items.

---

## 2. Decisions Considered and Rejected

### Carried from the contract's decision log

| # | Decision | Rejected alternative | Why it binds this phase |
|---|---|---|---|
| 1 | RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API | Sync WorkOS roles/permissions into local spatie-style tables | Establishes the "read live, never sync into a local authorization table" pattern that this phase's Gate integration must also follow for key permissions — a key's permissions are read fresh from the validate response every request, never persisted. |
| 2 | FGA ships without caching — direct Check API per check; opt-in caching with events-driven invalidation is Full-tier | Default per-check cache in MVP | Direct precedent for API Keys' identical no-cache mandate: `createValidation` runs on every guarded request. A stale API-key cache entry is exactly as dangerous as the stale-FGA-cache case the contract already rejected. |
| 3 | Local Eloquent rows are declared projections only (user, org, domains, memberships) + events cursor | No local state / read-through API calls per request | Confirms this phase adds no new table — the guard resolves against the *existing* User/Organization projections by `workos_id`, never stores the key or its permissions. |
| 4 | API Keys Guard and Connect & MCP phases depend on Organizations & Org Context | Original prereq graph (auth-core only) | Literal justification for this phase's Organizations prereq: `createOrganizationApiKey` and `createUserApiKey` both require a non-null `organizationId` at the SDK signature level (verified in `vendor/workos/workos-php/lib/Service/ApiKeys.php` and `UserManagement.php`). |
| 5 | Truth bar: emulate-backed Pest tests where covered, Guzzle MockHandler where not — user-scoped API keys named explicitly as a MockHandler case | SDK fakes only | Sets this phase's test-path split precisely: org-scoped guard/trait behavior is emulate-backed, user-scoped guard/trait behavior is MockHandler-backed (see §6). |
| 6 | Credentials read from config only; `env()` never read outside config files | Runtime `env()` reads like the SDK's own fallback | The guard's header-selection setting and the client it calls must both route through `config('authkit.*')`; no `env()` calls appear anywhere in `src/`. |
| 7 | Typed sidecar events bounded to projection-feeding + audit/domain-verification types; generic `WorkosEvent`/`GenericWorkosEvent` fallback for everything else | A typed Laravel event class per WorkOS event type | API keys are **not** a declared projection, so `api_key.created` / `api_key.updated` / `api_key.revoked` events get no typed event class from this phase — they ride the generic fallback. Nothing for Phase 8 to wire into the events pipeline. |

### New in this phase

| # | Decision | Rejected alternative | Reason |
|---|---|---|---|
| 8 | Package-owned DTOs (`ApiKeyCreated`, `ApiKeySummary`) wrap SDK resources at the trait boundary | Returning SDK resource classes (`UserApiKeyWithValue`, `OrganizationApiKey`, etc.) directly from trait methods | Makes the "raw value returned exactly once" contract visible in the *type system*: only `ApiKeyCreated` has a `value` property; `ApiKeySummary` (what `listApiKeys()` returns) structurally cannot expose it. Also means consumer code never needs a WorkOS FQCN for a type hint, reinforcing the SDK-invisibility doctrine beyond what the grep literally checks. |
| 9 | Two owner-specific traits — `HasApiKeys` (User) and `HasOrganizationApiKeys` (org model) — sharing a small `InteractsWithWorkosApiKeys` trait for revoke/expire | One polymorphic `HasApiKeys` trait that runtime-type-sniffs which model it's mixed into | `createUserApiKey`'s required `organizationId` parameter and `createOrganizationApiKey`'s absence of that parameter are genuinely different SDK signatures. Forcing one shared `createApiKey()` method would need either optional-parameter guessing or `instanceof` branching inside the trait — both less honest than two small traits with an honestly-shared remainder (revoke/expire are identical for both owner types because the underlying WorkOS endpoints are keyed by API key ID alone, not owner). |
| 10 | `authkit-key` guard registered via `Illuminate\Support\Facades\Auth::viaRequest()` | A full custom class implementing `Illuminate\Contracts\Auth\Guard` via `Auth::extend()` | `viaRequest` is Laravel's own paved path for exactly this shape (stateless, header-derived principal, no `UserProvider` needed). It uses `RequestGuard`, which already memoizes the resolved user for the request via `GuardHelpers` — `check()`/`guest()`/`id()`/`hasUser()`/`setUser()` come for free. A hand-rolled `Guard` class would duplicate that. |
| 11 | `AuthkitServiceProvider::register()` sets a default `auth.guards.authkit-key` config entry at runtime, merged so the consumer's own value (if any) wins | Requiring `authkit:install` to programmatically edit the consumer's `config/auth.php` | `Auth::guard('authkit-key')` throws `InvalidArgumentException("Auth guard [authkit-key] is not defined.")` unless `config('auth.guards.authkit-key')` exists (confirmed in `AuthManager::resolve()`/`getConfig()`). Editing a foreign config file by string-rewriting is fragile; setting the value at runtime is the standard way Laravel packages ship a guard that "just works" without demanding the consumer hand-wire it — keeping the ≤10-minute quickstart goal intact. Consumers can still override by publishing their own `auth.guards.authkit-key` entry. |
| 12 | Phase 8 registers its own independent `Gate::before` callback, keyed only on the presence of API-key-sourced permissions on the resolved principal | Depending on / extending Phase 5's exact `Gate::before` implementation | Phase 5's spec doesn't exist yet at the time this delta is written. A callback that only ever fires when `$user instanceof WorkosApiKeyActor` or `$user->apiKeyPermissions() !== null` never fires for JWT-claims-authenticated requests (mutually exclusive by which guard authenticated the request), so it composes safely with whatever Phase 5 ships, in either registration order. See §3.4 for the full precedence argument. |

---

## 3. Components

### 3.1 `authkit-key` Guard — `ApiKeyAuthenticator`

**Laravel mechanism**: a custom guard registered via `Auth::viaRequest('authkit-key', ...)`.
**SDK methods wrapped**: `WorkOS\Service\ApiKeys::createValidation(string $value, ?RequestOptions $options = null): ApiKeyValidationResponse` (confirmed signature, `vendor/workos/workos-php/lib/Service/ApiKeys.php:96-110`).

The guard is a direct, uncached call per request — the phase-specific mandate is explicit: the validate endpoint **is** the authn+authz check, there is no cache layer. (Per-request memoization inside `RequestGuard::user()` — "don't call the callback twice for one request" — is not the doctrine-violating cache; every *request* still triggers exactly one fresh `createValidation` call. See Decision 2.)

```php
namespace Authkit\Authkit\Auth;

use Authkit\Authkit\Exceptions\MissingModelConfigurationException;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use WorkOS\Exception\WorkOSException;
use WorkOS\Resource\ApiKey;
use WorkOS\Resource\UserApiKeyOwner;

final class ApiKeyAuthenticator
{
    public function __invoke(Request $request): ?Authenticatable
    {
        $value = $this->extractKeyValue($request);

        if ($value === null) {
            return null;
        }

        try {
            $response = Authkit::client()->apiKeys()->createValidation($value);
        } catch (WorkOSException $e) {
            Log::warning('authkit: API key validation call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->apiKey === null) {
            return null;
        }

        return $response->apiKey->owner instanceof UserApiKeyOwner
            ? $this->resolveUser($response->apiKey)
            : $this->resolveOrganizationActor($response->apiKey);
    }

    private function extractKeyValue(Request $request): ?string
    {
        $header = config('authkit.api_keys.header', 'bearer');

        return $header === 'bearer'
            ? $request->bearerToken()
            : $request->header($header);
    }

    private function resolveUser(ApiKey $apiKey): ?Authenticatable
    {
        /** @var class-string<Model>|null $userModel */
        $userModel = config('auth.providers.users.model');

        if ($userModel === null || $userModel === '') {
            throw MissingModelConfigurationException::forUserModel();
        }

        $user = $userModel::query()->where('workos_id', $apiKey->owner->id)->first();

        if ($user === null) {
            Log::warning('authkit: API key validated by WorkOS but no local User projection exists', [
                'workos_user_id' => $apiKey->owner->id,
            ]);

            return null;
        }

        if (method_exists($user, 'setApiKeyPermissions')) {
            $user->setApiKeyPermissions($apiKey->permissions);
        }

        return $user;
    }

    private function resolveOrganizationActor(ApiKey $apiKey): ?Authenticatable
    {
        /** @var class-string<Model>|null $organizationModel */
        $organizationModel = config('authkit.organization_model');

        if ($organizationModel === null || $organizationModel === '') {
            throw MissingModelConfigurationException::forOrganizationModel();
        }

        $organization = $organizationModel::query()->where('workos_id', $apiKey->owner->id)->first();

        if ($organization === null) {
            Log::warning('authkit: API key validated by WorkOS but no local Organization projection exists', [
                'workos_organization_id' => $apiKey->owner->id,
            ]);

            return null;
        }

        return new WorkosApiKeyActor(
            organization: $organization,
            permissions: $apiKey->permissions,
            apiKeyId: $apiKey->id,
            expiresAt: $apiKey->expiresAt,
        );
    }
}
```

Note: `ApiKey::$owner` is typed `ApiKeyOwner|UserApiKeyOwner` (confirmed in `vendor/workos/workos-php/lib/Resource/ApiKey.php`). `UserApiKeyOwner` is the only variant carrying `organizationId`; checking `instanceof UserApiKeyOwner` is the correct and only reliable owner-type discriminator (do not match on `$owner->type === 'user'` as a string — the typed check is equivalent and safer against future string drift).

Both `resolveUser()` and `resolveOrganizationActor()` guard against an unconfigured model FQCN before touching Eloquent. Without the guard, `$userModel::query()` (or `$organizationModel::query()`) on a null/empty class-string is a raw PHP `TypeError` deep inside the guard, not the actionable, config-key-naming exception the template's Shared Failure-Mode Prompts mandate for every delta ("fail fast with an actionable exception naming the config key, not a WorkOS 401 deep in a request") — see §7 row 10. The guard throws `MissingModelConfigurationException` instead:

```php
namespace Authkit\Authkit\Exceptions;

final class MissingModelConfigurationException extends \RuntimeException
{
    public static function forUserModel(): self
    {
        return new self(
            "Cannot resolve an API-key principal: 'auth.providers.users.model' is not configured. ".
            "Set it to your User model FQCN (Laravel's own default config key) before using the authkit-key guard."
        );
    }

    public static function forOrganizationModel(): self
    {
        return new self(
            "Cannot resolve an API-key principal: 'authkit.organization_model' is not configured. ".
            'Set it to your Organization model FQCN before using the authkit-key guard for org-scoped keys.'
        );
    }
}
```

**Implementation steps**:
1. `php artisan make:` generators do not apply here — this component is a plain invokable PHP class with no migration, model, or console-command shape (see §9 for the full "no generators apply" statement covering the whole phase).
2. Create `src/Auth/ApiKeyAuthenticator.php` as above.
3. Create `src/Exceptions/MissingModelConfigurationException.php` as above.
4. Register it in `AuthkitServiceProvider::boot()` (see §5).
5. Add the `auth.guards.authkit-key` default in `AuthkitServiceProvider::register()` (see §5).

**Feedback loop** (iterative — this is the highest-risk component in the phase):
- **Playground**: `composer serve` (Testbench workbench) with `npx @workos/emulate` running locally and seeded with an organization + an org-scoped API key in `workos-emulate.config.yaml`. Curl the workbench demo route (§3.4/§4) with `-H "Authorization: Bearer <emulate-seeded-key>"` and with a garbage value, observing 200 vs 401.
- **Parameterized experiment**: a Pest dataset in `tests/Feature/ApiKeysTest.php` iterating `header style × key validity` — `['bearer', valid-org-key] / ['bearer', invalid] / ['X-Api-Key', valid-org-key] / ['X-Api-Key', invalid]` — asserting the resolved principal type (or null) for each combination.
- **Check command**: `vendor/bin/pest --filter=ApiKeysTest` (org-scoped/emulate path) and `vendor/bin/pest --filter=ApiKeysMockedTest` (user-scoped/MockHandler path).

### 3.2 Principal Resolution

Covered inline in §3.1's `resolveUser()` / `resolveOrganizationActor()`. Summary of the two outcomes named in the phase direction:

- **User-scoped key** → guard returns the local `User` Eloquent model (resolved by `workos_id` = the WorkOS user ID from `UserApiKeyOwner->id`), with the key's `permissions` array attached via `setApiKeyPermissions()` (from `HasApiKeys`, §3.5) so Gate integration can read it without touching JWT claims.
- **Organization-scoped key** → guard returns a `WorkosApiKeyActor` (§3.3) wrapping the local Organization model + the key's permissions + the key's ID + its expiry.

No separate feedback loop — exercised entirely through §3.1's suite.

### 3.3 `WorkosApiKeyActor`

**Laravel mechanism**: a synthetic `Illuminate\Contracts\Auth\Authenticatable` implementation — not an Eloquent model — for the org-scoped-key case, since there is no natural "user" to be for an organization-owned key.

```php
namespace Authkit\Authkit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class WorkosApiKeyActor implements Authenticatable
{
    public function __construct(
        public readonly Model $organization,
        public readonly array $permissions,
        public readonly string $apiKeyId,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'api_key_id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->apiKeyId;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Stateless actor — no remember-me concept for API keys.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
```

**Feedback loop** (iterative, but folded into §3.1's suite plus a small dedicated unit test — no standalone playground makes sense for a value object that only means anything once a guard produces it):
- **Playground**: same as §3.1 (curl the demo route with an org-scoped key).
- **Parameterized experiment**: `tests/Unit/WorkosApiKeyActorTest.php` — construct the actor directly with varying `permissions` arrays and assert `Gate::forUser($actor)->allows($ability)` for `$ability` inside vs. outside the array, with and without `$expiresAt`.
- **Check command**: `vendor/bin/pest --filter=WorkosApiKeyActorTest`.

### 3.4 Gate Integration & Precedence

**Laravel mechanism**: an additional `Gate::before` callback (Laravel evaluates every registered `Gate::before` closure and short-circuits on the first non-null return — multiple callbacks are first-class, not a hack).

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

Gate::before(function (Authenticatable $user, string $ability): ?bool {
    $permissions = match (true) {
        $user instanceof WorkosApiKeyActor => $user->permissions,
        method_exists($user, 'apiKeyPermissions') => $user->apiKeyPermissions(),
        default => null,
    };

    if ($permissions === null) {
        return null; // Not an API-key-authenticated principal this request — defer.
    }

    return in_array($ability, $permissions, true) ? true : null;
});
```

**Precedence, documented explicitly:**

| Authenticated via | Permission source this callback reads | Returns `true` | Returns `null` (defer) |
|---|---|---|---|
| `workos` session guard, JWT present (Phase 5's own callback, not this one) | Phase 5's claims accessor | ability ∈ claims | out of this phase's control |
| `authkit-key` guard, user-scoped key | `HasApiKeys::apiKeyPermissions()` — the *key's* permissions, never JWT claims (a key-authenticated request has no session/JWT at all) | ability ∈ key permissions | ability ∉ key permissions → falls through to any policy/other `Gate::before`; for a plain `User` with no matching policy, Laravel's default-deny applies |
| `authkit-key` guard, org-scoped key | `WorkosApiKeyActor::$permissions` | ability ∈ key permissions | ability ∉ key permissions → same default-deny, since a synthetic actor has no other grant path unless the app defines one |

**Why there is no real precedence conflict to resolve**: exactly one of "JWT claims present" or "`apiKeyPermissions()` non-null" is ever true for a given request, because they are populated by mutually exclusive guards (`workos` vs `authkit-key`) and Laravel's stock `Authenticate` middleware calls `Auth::shouldUse($guard)` on whichever guard actually authenticated the request (confirmed in `Illuminate\Auth\Middleware\Authenticate::authenticate()`), so `Gate::before`'s `$user` argument is always the principal that specific guard resolved. Registration order between Phase 5's callback and this one is therefore inconsequential in practice; it is registered after Phase 5's in `boot()` purely for reading order, not because order matters here. This is the "document precedence" requirement from the phase direction, resolved by construction rather than by an explicit priority list.

**Feedback loop** (iterative):
- **Playground**: the workbench demo route below returns `Gate::allows($ability)` as JSON so a developer can curl with different keys/abilities and watch the decision live:
  ```php
  // workbench/routes/web.php addition
  Route::middleware('auth:authkit-key')->get('/api-keys/whoami', function (Illuminate\Http\Request $request) {
      return [
          'principal' => $request->user()::class,
          'permissions' => $request->user() instanceof \Authkit\Authkit\Auth\WorkosApiKeyActor
              ? $request->user()->permissions
              : $request->user()->apiKeyPermissions(),
          'can_ping' => Illuminate\Support\Facades\Gate::allows('ping'),
      ];
  });
  ```
  Call with `curl -H "Authorization: Bearer <key>" -H "Accept: application/json" http://localhost:8000/api-keys/whoami`.
- **Parameterized experiment**: `tests/Unit/WorkosApiKeyActorTest.php` dataset over `permissions × ability`.
- **Check command**: `vendor/bin/pest --filter=WorkosApiKeyActorTest` and `vendor/bin/pest --filter=ApiKeysTest`.

### 3.5 `HasApiKeys` trait (User)

**Laravel mechanism**: a trait applied to the consumer's User model.
**SDK methods wrapped**: `UserManagement::listUserApiKeys()` and `UserManagement::createUserApiKey()` (both confirmed at `vendor/workos/workos-php/lib/Service/UserManagement.php:1594` and `:1631`); revoke/expire delegate to the shared `InteractsWithWorkosApiKeys` trait (§3.7).

```php
namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Data\ApiKeyCreated;
use Authkit\Authkit\Data\ApiKeyMapper;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use WorkOS\Resource\PaginationOrder;
use WorkOS\RequestOptions;

trait HasApiKeys
{
    use InteractsWithWorkosApiKeys;

    protected ?array $workosApiKeyPermissions = null;

    /**
     * Attached by the authkit-key guard when this User was resolved from a
     * user-scoped API key. Null on every other request (session/JWT auth,
     * or no auth at all) — never a source of JWT claims.
     */
    public function apiKeyPermissions(): ?array
    {
        return $this->workosApiKeyPermissions;
    }

    public function setApiKeyPermissions(array $permissions): static
    {
        $this->workosApiKeyPermissions = $permissions;

        return $this;
    }

    /**
     * Create a new API key for this user, scoped to one organization
     * membership. The WorkOS API returns the raw secret value ONLY in this
     * response — it is never retrievable again, from this method or any
     * other. Persist $result->value immediately or lose it.
     */
    public function createApiKey(
        string $name,
        Model|string $organization,
        ?array $permissions = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $idempotencyKey = null,
    ): ApiKeyCreated {
        $organizationId = $organization instanceof Model ? $organization->workos_id : $organization;

        $resource = Authkit::client()->userManagement()->createUserApiKey(
            userId: $this->workos_id,
            name: $name,
            organizationId: $organizationId,
            permissions: $permissions,
            expiresAt: $expiresAt,
            options: $idempotencyKey !== null ? new RequestOptions(idempotencyKey: $idempotencyKey) : null,
        );

        return ApiKeyMapper::fromCreated($resource);
    }

    /**
     * @return Collection<int, \Authkit\Authkit\Data\ApiKeySummary>
     */
    public function listApiKeys(Model|string|null $organization = null): Collection
    {
        $organizationId = $organization instanceof Model ? $organization->workos_id : $organization;

        $page = Authkit::client()->userManagement()->listUserApiKeys(
            userId: $this->workos_id,
            order: PaginationOrder::Desc,
            organizationId: $organizationId,
        );

        return collect($page->data)->map(ApiKeyMapper::fromResource(...));
    }
}
```

**Implementation steps**:
1. No generator applies (plain trait) — create `src/Concerns/HasApiKeys.php` as above.
2. Apply `use HasApiKeys;` to `workbench/app/Models/User.php`.

**Feedback loop** (iterative):
- **Playground (org-scoped path only — see below)**: none exists for the user-scoped path against a live server, because WorkOS `emulate` v0.6 does not implement the user-scoped API key endpoints (confirmed in the context brief's emulate coverage notes: "API keys (user-scoped missing)"). This is a documented emulate gap, not a bug to fix in this phase — the MockHandler-backed Pest suite **is** the fastest feedback loop for this trait, not a fallback for one.
- **Parameterized experiment**: `tests/Feature/ApiKeysMockedTest.php` dataset over `organization param shape (Model vs raw string)` asserting the outbound MockHandler-captured request body carries the same `organization_id` either way; a second case asserts `createApiKey()`'s return type structurally lacks nothing (`->value` present) while `listApiKeys()` items structurally cannot expose `->value`.
- **Check command**: `vendor/bin/pest --filter=ApiKeysMockedTest`.

### 3.6 `HasOrganizationApiKeys` trait (org model)

**Laravel mechanism**: a trait applied to the consumer's Organization model.
**SDK methods wrapped**: `ApiKeys::createOrganizationApiKey()` and `ApiKeys::listOrganizationApiKeys()` (confirmed at `vendor/workos/workos-php/lib/Service/ApiKeys.php:33` and `:67`); revoke/expire delegate to `InteractsWithWorkosApiKeys` (§3.7).

```php
namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Data\ApiKeyCreated;
use Authkit\Authkit\Data\ApiKeyMapper;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Support\Collection;
use WorkOS\Resource\PaginationOrder;
use WorkOS\RequestOptions;

trait HasOrganizationApiKeys
{
    use InteractsWithWorkosApiKeys;

    public function createApiKey(
        string $name,
        ?array $permissions = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $idempotencyKey = null,
    ): ApiKeyCreated {
        $resource = Authkit::client()->apiKeys()->createOrganizationApiKey(
            organizationId: $this->workos_id,
            name: $name,
            permissions: $permissions,
            expiresAt: $expiresAt,
            options: $idempotencyKey !== null ? new RequestOptions(idempotencyKey: $idempotencyKey) : null,
        );

        return ApiKeyMapper::fromCreated($resource);
    }

    /**
     * @return Collection<int, \Authkit\Authkit\Data\ApiKeySummary>
     */
    public function listApiKeys(): Collection
    {
        $page = Authkit::client()->apiKeys()->listOrganizationApiKeys(
            organizationId: $this->workos_id,
            order: PaginationOrder::Desc,
        );

        return collect($page->data)->map(ApiKeyMapper::fromResource(...));
    }
}
```

**Implementation steps**:
1. No generator applies — create `src/Concerns/HasOrganizationApiKeys.php` as above.
2. Apply `use HasOrganizationApiKeys;` to `workbench/app/Models/Organization.php`.

**Feedback loop** (iterative):
- **Playground**: `php artisan tinker` against a locally running `npx @workos/emulate` seeded with one organization — `Organization::first()->createApiKey('ci-bot')` then `->listApiKeys()`.
- **Parameterized experiment**: `tests/Feature/ApiKeysTest.php` dataset over `with permissions / without permissions / with expiresAt / without`.
- **Check command**: `vendor/bin/pest --filter=ApiKeysTest`.

### 3.7 `InteractsWithWorkosApiKeys` trait (shared revoke/expire)

**Laravel mechanism**: a trait consumed by both §3.5 and §3.6 (trait-using-trait composition).
**SDK methods wrapped**: `ApiKeys::deleteApiKey()` and `ApiKeys::createApiKeyExpire()` (confirmed at `vendor/workos/workos-php/lib/Service/ApiKeys.php:120` and `:140`) — both keyed by API key ID alone, identical regardless of owner type. `createApiKeyExpire()` (the `expireApiKey()` wrapper below) carries the **scope-expansion flag** from §1 — confirm it against the actual phase-direction source before implementing; see §11.

Both methods take the key ID alone and perform no ownership check against `$this` — WorkOS has no owner-scoped delete/expire endpoint, so nothing here stops `$org->revokeApiKey($someoneElsesKeyId)` from silently succeeding. The docblocks below name this explicitly, matching §3.5's raw-value-contract comment style:

```php
namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Data\ApiKeyMapper;
use Authkit\Authkit\Data\ApiKeySummary;
use Authkit\Authkit\Facades\Authkit;

trait InteractsWithWorkosApiKeys
{
    /**
     * Revoke (permanently delete) an API key by ID. WorkOS's delete endpoint
     * is keyed by API key ID alone — it takes no owner parameter and performs
     * no ownership check against $this. The caller MUST verify $apiKeyId
     * actually belongs to this user/organization before calling; nothing here
     * stops $org->revokeApiKey($someoneElsesKeyId) from succeeding.
     */
    public function revokeApiKey(string $apiKeyId): void
    {
        Authkit::client()->apiKeys()->deleteApiKey($apiKeyId);
    }

    /**
     * Expire an API key by ID, immediately or at a scheduled time. Same
     * owner-unchecked contract as revokeApiKey() above — WorkOS's expire
     * endpoint is keyed by API key ID alone, with no owner parameter. The
     * caller MUST verify $apiKeyId belongs to this user/organization first.
     */
    public function expireApiKey(string $apiKeyId, ?\DateTimeImmutable $expiresAt = null): ApiKeySummary
    {
        $resource = Authkit::client()->apiKeys()->createApiKeyExpire($apiKeyId, $expiresAt);

        return ApiKeyMapper::fromResource($resource);
    }
}
```

**Feedback loop**: shared with §3.5/§3.6's suites — no standalone loop (this trait has no independent behavior to observe outside a create-then-revoke/expire sequence).

### 3.8 DTOs, Mapper, and Config — trivial, no feedback loop

These are plain value objects and a config key; wrapping/data-shape correctness is proven by the components above that construct them, not by an independent loop.

```php
namespace Authkit\Authkit\Data;

final readonly class ApiKeyCreated
{
    public function __construct(
        public string $id,
        public string $name,
        public string $value,
        public array $permissions,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
```

```php
namespace Authkit\Authkit\Data;

final readonly class ApiKeySummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $obfuscatedValue,
        public array $permissions,
        public ?\DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $lastUsedAt,
    ) {
    }
}
```

```php
namespace Authkit\Authkit\Data;

use WorkOS\Resource\ApiKey;
use WorkOS\Resource\OrganizationApiKey;
use WorkOS\Resource\OrganizationApiKeyWithValue;
use WorkOS\Resource\UserApiKey;
use WorkOS\Resource\UserApiKeyWithValue;

final class ApiKeyMapper
{
    public static function fromCreated(UserApiKeyWithValue|OrganizationApiKeyWithValue $resource): ApiKeyCreated
    {
        return new ApiKeyCreated(
            id: $resource->id,
            name: $resource->name,
            value: $resource->value,
            permissions: $resource->permissions,
            expiresAt: $resource->expiresAt,
        );
    }

    public static function fromResource(UserApiKey|OrganizationApiKey|ApiKey $resource): ApiKeySummary
    {
        return new ApiKeySummary(
            id: $resource->id,
            name: $resource->name,
            obfuscatedValue: $resource->obfuscatedValue,
            permissions: $resource->permissions,
            expiresAt: $resource->expiresAt,
            lastUsedAt: $resource->lastUsedAt,
        );
    }
}
```

Config addition to `config/authkit.php`:

```php
'api_keys' => [
    // 'bearer' reads `Authorization: Bearer <value>`. Any other string is
    // treated as a literal header name read via $request->header(), e.g.
    // set this to 'X-Api-Key' to accept `X-Api-Key: <value>` instead.
    'header' => 'bearer',
],
```

---

## 4. File Changes

### New files

| Path | Traces to |
|---|---|
| `src/Auth/ApiKeyAuthenticator.php` | Scope: "authkit-key guard driver validating via the validate endpoint" |
| `src/Auth/WorkosApiKeyActor.php` | Scope: org-scoped key principal ("issue/revoke on user and org models" implies a real principal for org keys); phase direction: "dedicated WorkosApiKeyActor implementing Authenticatable carrying the org model + key permissions" |
| `src/Concerns/HasApiKeys.php` | Phase direction: "HasApiKeys trait for User ... list/revoke" |
| `src/Concerns/HasOrganizationApiKeys.php` | Phase direction: "and for the org model (via ApiKeys service: create/list/delete/expire)" — the "expire" half is the §1 scope-expansion flag (unquoted source, not in `contract-data.json`); confirm before implementing |
| `src/Concerns/InteractsWithWorkosApiKeys.php` | Decision 9 (shared revoke/expire) — "expire" half carries the same §1 scope-expansion flag |
| `src/Exceptions/MissingModelConfigurationException.php` | §3.1 guard clauses (Shared Failure-Mode Prompts: "fail fast with an actionable exception naming the config key") |
| `src/Data/ApiKeyCreated.php` | Phase direction: "raw value returned ONCE, surface that contract loudly" |
| `src/Data/ApiKeySummary.php` | Same |
| `src/Data/ApiKeyMapper.php` | Same (DRY support for the two traits) |
| `tests/Feature/ApiKeysTest.php` | successCriteria: "Every scope area has a dedicated Pest feature suite — emulate-backed where covered" |
| `tests/Feature/ApiKeysMockedTest.php` | successCriteria: same row, MockHandler half; Decision 5 |
| `tests/Unit/WorkosApiKeyActorTest.php` | Phase direction: "design its Gate interplay explicitly" |

### Modified files

| Path | Change | Traces to |
|---|---|---|
| `src/AuthkitServiceProvider.php` | Add default `auth.guards.authkit-key` config in `register()`; register `Auth::viaRequest('authkit-key', ...)` and the API-key `Gate::before` in `boot()` | Scope: guard driver + "loading key permissions into Gate" |
| `config/authkit.php` | Add `api_keys.header` key (§3.8) | Phase direction: "config-driven header (Authorization Bearer or X-Api-Key)" |
| `workbench/app/Models/User.php` | Add `use HasApiKeys;` | Doctrine: workbench must exercise every area with zero SDK references |
| `workbench/app/Models/Organization.php` | Add `use HasOrganizationApiKeys;` | Same |
| `workbench/routes/web.php` | Add the `/api-keys/whoami` demo route (§3.4) | Same; also the §3.1/§3.4 playground |

### Explicitly not touched

- **Database / migrations**: none. Decision 3 — this phase adds zero new local WorkOS-shaped state.
- **`routes/authkit-laravel.php`** (package routes): none. The guard is consumed via Laravel's stock `auth:authkit-key` middleware on *consumer* routes; the package itself ships no protected routes. There is deliberately no new middleware alias either — the Shared Conventions table's middleware-alias row (`authkit.session`, `authkit.org`, `authkit.mcp`) names no `authkit.key` entry, and none is needed.
- **`config/auth.php`** (consumer's file): none, by design (Decision 11) — the guard entry is injected at runtime, not published/edited.

---

## 5. Service Provider Registration Diff

```php
// src/AuthkitServiceProvider.php

public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/authkit.php', 'authkit');

    $this->app->singleton(Authkit::class);

    // NEW: default guard config so `Auth::guard('authkit-key')` / `auth:authkit-key`
    // work out of the box. Consumer-defined values win (merged second).
    $this->app['config']->set(
        'auth.guards.authkit-key',
        array_merge(
            ['driver' => 'authkit-key'],
            $this->app['config']->get('auth.guards.authkit-key', []),
        ),
    );
}

public function boot(): void
{
    // NEW: guard + Gate wiring, registered unconditionally (HTTP and console —
    // a console command may still want Gate::allows() to see key-derived permissions).
    Auth::viaRequest('authkit-key', $this->app->make(ApiKeyAuthenticator::class));

    Gate::before(function (Authenticatable $user, string $ability): ?bool {
        $permissions = match (true) {
            $user instanceof WorkosApiKeyActor => $user->permissions,
            method_exists($user, 'apiKeyPermissions') => $user->apiKeyPermissions(),
            default => null,
        };

        if ($permissions === null) {
            return null;
        }

        return in_array($ability, $permissions, true) ? true : null;
    });

    $this->loadRoutesFrom(__DIR__.'/../routes/authkit-laravel.php');
    // ...existing loadViewsFrom / loadTranslationsFrom unchanged...

    if (! $this->app->runningInConsole()) {
        return;
    }

    // ...existing publishes()/publishesMigrations()/commands() unchanged...
}
```

(Shown against the current skeleton `AuthkitServiceProvider.php`; if Phases 1–5 have already restructured `boot()`/`register()` by the time this lands, add the two blocks above in the same relative position — config default in `register()`, guard/Gate wiring near the top of `boot()`, before the `runningInConsole()` early return.)

---

## 6. Testing Requirements

| Suite file | Test path | Seed data | Key cases |
|---|---|---|---|
| `tests/Feature/ApiKeysTest.php` | **emulate** — org-scoped keys are the covered path | One organization + one org-scoped API key seeded via `workos-emulate.config.yaml` (or created in-test against the running emulate instance) | Guard authenticates via `Authorization: Bearer`; guard authenticates via `X-Api-Key` when config is switched; invalid key → guard returns null (401 through the demo route); revoked key (delete then re-validate) → null; `Gate::allows()` true for a permission the key carries; false for one it doesn't and no policy grants; `createApiKey()` returns `ApiKeyCreated` with a non-empty `value`; `listApiKeys()` items are `ApiKeySummary` (no `value` property at all — assert via `property_exists`); `revokeApiKey()` then re-validate returns null; **data shadow**: a real emulate-issued org key whose `owner->id` doesn't match any local `Organization.workos_id` row → guard returns null, warning logged. `expireApiKey()` is deliberately **not** exercised here — confirmed by running `npx @workos/emulate@0.6.0` locally and inspecting its route source (`@workos/emulate`'s `dist/workos/routes/api-keys.js`): `POST /api_keys/{id}/expire` has no registered handler and 404s, while `DELETE /api_keys/{id}` (the revoke case above) is a real handler that returns 204 and drops the value from the validation allow-list. See the `ApiKeysMockedTest.php` row below for the expire case, and §7's Emulate-drift note. |
| `tests/Feature/ApiKeysMockedTest.php` | **MockHandler** — user-scoped keys (emulate has no user-scoped API key endpoints, per Decision 5), plus `expireApiKey()` for either owner type (emulate has no `/api_keys/{id}/expire` route at all — confirmed above, not a user-scoped-only gap) | Guzzle `MockHandler` responses shaped exactly like `POST /api_keys/validations` (see fixture below), plus one shaped like `POST /api_keys/{id}/expire`'s `ApiKey` response (see fixture below) | Guard resolves the correct local `User` for a valid user-scoped key; `apiKeyPermissions()` returns the key's permissions after guard resolution and `null` on an unauthenticated/non-key request; `createApiKey()` requires an `organization` argument and sends the right `organization_id` whether passed a model or a raw string (assert via Guzzle history middleware); `createApiKey()` return value has `value`, `listApiKeys()` items do not; **WorkOS-down**: MockHandler throws/returns 500 → guard returns null, warning logged, distinct from the "invalid key" case; **revoked mid-session / membership deleted**: MockHandler returns `{"api_key": null}` → guard returns null (this is the same response shape WorkOS uses for membership-deletion auto-revocation — nothing app-side to special-case, see §7 row 5); `expireApiKey()` (shared `InteractsWithWorkosApiKeys` trait — exercised once here regardless of owner type, since the trait's behavior doesn't branch on it) sends the given `expiresAt` and maps the response into an `ApiKeySummary` whose `expiresAt` reflects it. |
| `tests/Unit/WorkosApiKeyActorTest.php` | neither (pure unit — no WorkOS call) | none | Implements `Authenticatable` correctly (`getAuthIdentifier()` returns the key ID, etc.); `Gate::forUser($actor)->allows($ability)` is `true` for `$ability` inside `$actor->permissions` and `false` (default-deny, no policy exists for a synthetic actor) for one outside it; constructs fine with `expiresAt: null`. |

Example MockHandler fixture for `POST /api_keys/validations` (user-scoped, valid):

```php
new Response(200, [], json_encode([
    'api_key' => [
        'object' => 'api_key',
        'id' => 'api_key_01H...',
        'owner' => [
            'type' => 'user',
            'id' => 'user_01H...',
            'organization_id' => 'org_01H...',
        ],
        'name' => 'ci-bot',
        'obfuscated_value' => 'sk_live_...abcd',
        'last_used_at' => null,
        'expires_at' => null,
        'permissions' => ['posts:read'],
        'created_at' => '2026-08-01T00:00:00.000Z',
        'updated_at' => '2026-08-01T00:00:00.000Z',
    ],
])),
```

Revoked/invalid response: `new Response(200, [], json_encode(['api_key' => null]))` — WorkOS returns 200 with a null `api_key`, not a 4xx, for an unrecognized or revoked key value (confirmed by the `ApiKeyValidationResponse` resource shape allowing `?ApiKey $apiKey`).

Example MockHandler fixture for `POST /api_keys/{id}/expire` (the case moved here from `ApiKeysTest.php` — see the table above and §7's Emulate-drift note):

```php
new Response(200, [], json_encode([
    'object' => 'api_key',
    'id' => 'api_key_01H...',
    'owner' => [
        'type' => 'organization',
        'id' => 'org_01H...',
    ],
    'name' => 'ci-bot',
    'obfuscated_value' => 'sk_live_...abcd',
    'last_used_at' => null,
    'expires_at' => '2026-08-01T00:00:00.000Z',
    'permissions' => ['posts:read'],
    'created_at' => '2026-07-01T00:00:00.000Z',
    'updated_at' => '2026-08-06T00:00:00.000Z',
])),
```

---

## 7. Failure Modes

| # | Named failure | Trigger | Behavior | Why / mitigation |
|---|---|---|---|---|
| 1 | Validate-endpoint outage | WorkOS 5xx/timeout, SDK retries exhausted | `WorkOSException` caught, guard returns null → every `authkit-key`-guarded route 401s for the outage's duration | Fail-closed by design (Decision 2). Cost named explicitly: this is a **hard down**, not degraded — session-guard (JWT) users are unaffected since JWT verification is local/JWKS-cached. Opt-in caching is out of scope for this phase (Full-tier precedent only exists for FGA, and even there it's deferred). |
| 2 | Key revoked mid-flight (TOCTOU) | A key is revoked in the dashboard while a request is already past its guard check | The in-flight request completes with the *pre-revocation* decision; the next request gets the fresh answer | Not a bug — authorization is a point-in-time decision, and re-validating every request (no cache) means the window is as small as it can be. Nothing to fix. |
| 3 | User projection data shadow | A valid user-scoped key validates, but no local `User` row has `workos_id` matching the owner ID (events pipeline lag, or the user was provisioned via API before any AuthKit login) | Guard logs a warning distinct from "invalid key" and returns null (401) | Guard does not auto-create a User — that would duplicate Phase 2's login-flow provisioning responsibility and violate the projection-boundary doctrine. Named so it isn't misdiagnosed as "the guard is broken." |
| 4 | Organization projection data shadow | A valid org-scoped key validates, but no local `Organization` row has `workos_id` matching the owner ID (org created via dashboard/API outside the app's own `HasWorkosOrganization` flow) | Same as #3, organization variant | Same rationale — most likely for orgs created by an ops team directly in WorkOS for a partner integration, not through the app's own org-creation path. |
| 5 | Membership-deletion auto-revocation | An admin removes a user from an organization; WorkOS auto-revokes that user's API keys scoped to that membership (documented WorkOS product behavior, not app code) | Next request with that key gets `{"api_key": null}` → guard returns null, indistinguishable from any other revoked key | Documented here specifically so it is never filed as an app bug — nothing to build, the guard's existing null-handling already covers it. |
| 6 | Header-scheme misconfiguration | `config('authkit.api_keys.header')` is `'bearer'` but the client sends `X-Api-Key` (or vice versa) | Guard extracts nothing usable → returns null → 401 that is indistinguishable from "invalid key" | No automatic detection (config is single-source by doctrine — Decision 6). Docs/workbench example must show the exact expected header for the configured mode. |
| 7 | Missing `auth.guards.authkit-key` config entry | A consumer explicitly overrides `config/auth.php` in a way that drops the package's runtime-injected default (Decision 11) | `AuthManager::resolve()` throws `InvalidArgumentException("Auth guard [authkit-key] is not defined.")` before any guard code runs | Residual risk after the Decision-11 mitigation; the error is at least an actionable framework message naming the missing guard, not a silent 401. |
| 8 | `createApiKey()` double-dispatch | A retried HTTP request or a double-clicked "create key" admin action calls `createApiKey()` twice | WorkOS creates two distinct, independently-valid API keys — the endpoint is not idempotent by default | Mitigated, not just noted: both `HasApiKeys::createApiKey()` and `HasOrganizationApiKeys::createApiKey()` accept an optional `$idempotencyKey`, passed through as `RequestOptions(idempotencyKey: ...)`. Callers in retry-prone contexts (queued jobs, at-least-once admin actions) should supply one; callers who don't are exposed to this failure mode exactly as much as an uninstrumented direct SDK call would be. |
| 9 | Default unauthenticated redirect on non-JSON requests | An `authkit-key`-guarded route lives in the `web` middleware group and a client omits `Accept: application/json` | Laravel's stock `Authenticate::unauthenticated()` tries `redirect()->guest(route('login'))`, which throws `RouteNotFoundException` if no `login` route exists | Not specific to this guard — it's Laravel's standard behavior for *every* guard. Mitigation is caller-side (send `Accept: application/json`, as the workbench demo route's curl example does) or route-placement-side (an `api`-shaped route group); no package code change addresses this. |
| 10 | Missing model configuration | `config('authkit.organization_model')` (org-scoped path) or `config('auth.providers.users.model')` (user-scoped path) is null or empty — e.g. Phase 3's install step was skipped, or a consumer cleared the value | Guard throws `MissingModelConfigurationException::forOrganizationModel()` / `::forUserModel()` (§3.1), naming the exact config key, before any query runs | Prevents a raw PHP `TypeError` from calling a method on a null class-string (`$organizationModel::query()` / `$userModel::query()`) deep inside the guard — the template's Shared Failure-Mode Prompts mandate "fail fast with an actionable exception naming the config key, not a WorkOS 401 deep in a request." This is a config error, not a per-request auth decision: every `authkit-key`-guarded request fails loudly until the config is fixed, which is correct — a missing model config is not a "no user matched" case. |

Stale-claims note (required by the template's shared prompts, addressed rather than engineered around): this guard has **no** staleness window by construction — every request calls `createValidation` fresh, so a permission change on a key takes effect on the very next request. This is a deliberate contrast with the JWT-claims path (Phases 5/7), which has a bounded staleness window between token refreshes; nothing in this phase needs to compensate for staleness because there isn't any.

Emulate-drift note: emulate v0.6 implements org-scoped API key create/list/validate, but its fidelity against production behavior for edge cases (permission-slug validation, expiry-boundary handling) is unverified beyond "the happy path works." Treat emulate-passing org-scoped tests as necessary, not sufficient, evidence for production correctness. Verified directly against a running `npx @workos/emulate@0.6.0` instance and its route source (`@workos/emulate`'s `dist/workos/routes/api-keys.js`) rather than assumed: `DELETE /api_keys/{id}` (revoke) is a real, working handler — it returns `204` for a known key ID and removes the value from the validation allow-list, so §6's "revoked key (delete then re-validate) → null" case is legitimate emulate coverage and stays in `ApiKeysTest.php`. `POST /api_keys/{id}/expire`, by contrast, has **no handler registered at all** — the call 404s — so the `expireApiKey()` case cannot pass against emulate as originally assigned; it has been moved to the MockHandler-backed `ApiKeysMockedTest.php` (§6) rather than left as an emulate test that can never pass.

---

## 8. Feedback Strategy

**Inner-loop command** (seconds): `vendor/bin/pest --filter=ApiKeysTest` (org-scoped/emulate) or `vendor/bin/pest --filter=ApiKeysMockedTest` (user-scoped/MockHandler) or `vendor/bin/pest --filter=WorkosApiKeyActorTest` (pure Gate/Authenticatable unit checks) — pick whichever matches the component being iterated on; none require booting emulate for the Mocked/Unit suites, only `ApiKeysTest` does.

**Playgrounds**: see each component's subsection in §3 — `composer serve` + curl against a locally running `npx @workos/emulate` for the guard/Gate/org-trait paths; `php artisan tinker` against emulate for the org-trait path specifically; no live playground exists for the user-scoped trait path (emulate gap, named in §3.5) — the MockHandler suite is the fastest feedback available for that path, not a fallback for a better one.

---

## 9. Deviations from the Template

1. **Two owner-specific traits instead of one `HasApiKeys`.** The Shared Conventions table lists a single `HasApiKeys` entry with no model annotation (unlike `HasWorkosUser (User)`, `HasWorkosOrganization (org model)`). This delta splits it into `HasApiKeys` (User) + `HasOrganizationApiKeys` (org model) sharing `InteractsWithWorkosApiKeys` — see Decision 9. The conceptual capability named "HasApiKeys" in the template is delivered by this pair, not a single trait.
2. **No `php artisan make:*` generator use.** The template's Delta-Must-Fill and the outer task both ask for generator usage "where applicable." Nothing in this phase has a migration, model, or console-command shape — every new file is a plain class/trait/DTO, none of which any stock Testbench/Laravel generator produces. Stated explicitly rather than silently omitted.
3. Everything else follows the template as written (namespace, config location, facade/guard/trait naming conventions, test-path selection, standard validation commands).

---

## 10. Validation Commands

```bash
composer analyse                          # PHPStan (larastan)
composer lint:check                       # Pint check-only
composer test:types                       # Pest type coverage --min=100
vendor/bin/pest --filter=ApiKeysTest              # org-scoped / emulate
vendor/bin/pest --filter=ApiKeysMockedTest        # user-scoped / MockHandler
vendor/bin/pest --filter=WorkosApiKeyActorTest    # Gate/Authenticatable unit checks
composer test                             # full chain — must be green before commit
```

---

## 11. Open Items

- Exact accessor name for the bound WorkOS client (`Authkit::client()` assumed — see §0) is not confirmed until Phase 1's spec exists.
- Exact config key naming for the org model (`authkit.organization_model` assumed — see §0) is not confirmed until Phase 3's spec exists.
- Whether Phase 5 registers its `Gate::before` on the User model via a claims accessor with the same name pattern (`somethingPermissions()`) as this phase's `apiKeyPermissions()` is unconfirmed — not a blocker per Decision 12's independence argument, but worth a naming-consistency pass once Phase 5's spec exists.
- No `authkit:install`-side wiring is proposed for the `api_keys.header` config default beyond it shipping in the published `config/authkit.php` stub — confirm during Phase 1/13 integration that publishing doesn't clobber a consumer's prior override (standard `publishes()` behavior already handles this; named for completeness, not because a new mechanism is needed).
- **Scope-expansion pending sign-off**: `expireApiKey()` / `ApiKeys::createApiKeyExpire()` (§3.7) is a third WorkOS write verb beyond the contract's literal scope row ("issue/revoke on user and org models") and `execution.phases[7].notes` ("issue/revoke APIs on user + org models") — checked directly against both `contract-data.json` and its rendered `contract.html`; neither mentions "expire" anywhere in the API Keys scope row, decision log, or phase-8 notes. Its only cited justification (§4's File Changes table) is an unquoted "phase direction" source not present in either canonical artifact. Confirm against the actual phase-direction/ideation transcript before implementing; if it can't be confirmed, cut `expireApiKey()` — the trait method (§3.7), its test case (§6), and its file-trace mentions (§4) — rather than building unapproved scope. See §1's Scope-expansion flag.
