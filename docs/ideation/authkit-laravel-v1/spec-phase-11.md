# Phase 11 — Pipes

Follow `spec-template-feature-area.md`; inputs below. This is a delta spec — read the template fully first. Only phase-specific inputs, designs, and deviations are repeated here.

## 1. Phase Header

- **Phase**: 11 — Pipes
- **Estimated effort**: **M**
- **Prereqs**: Organizations & Org Context (Phase 3) — Pipes calls accept an optional `organizationId`; org-scoped connections and the `PipesProvider` passthrough need a resolved org projection (`$organization->workos_id`) to be useful, the same hidden-dependency class the contract already named for API Keys/Connect & MCP (decision log, "API Keys Guard and Connect & MCP phases depend on Organizations & Org Context") even though Pipes wasn't named in that entry explicitly.
- **Risk**: low (per contract `execution.phases`)
- **Blocking**: false

## 2. Scope Rows Implemented

Verbatim from `contract-data.json` `scope.mvp`:

> **Pipes**: `$user->connectedAccounts()`, access-token fetch with auto-refresh, org provider-config passthrough
> Reason: *In the founder's literal MVP list; provider config stays in the WorkOS dashboard*

Binding phase-specific direction (from the task, elaborating the same row):

- `Authkit::pipes()` manager — `connectedAccounts(user)` returns a `Collection` of DTOs straight from the API (no local table).
- `accessToken(user, providerSlug)` via SDK `getAccessToken` with WorkOS-managed refresh.
- Missing-scope surfacing: a dedicated exception carrying the reauthorization URL from `authorizeDataIntegration`.
- Connect/disconnect helpers.
- External-token import via `createUserConnectedAccount` (422 on invalid token-field combos — named failure mode).
- Org-level provider config passthrough via `PipesProvider`.
- `HasWorkosUser` gains `connectedAccounts()`/`pipe(slug)` conveniences.
- Provider configuration itself is Dashboard-only — documented, not built.

**Scope-traceability flag (approver attention required):** `connect()`, `disconnect()`, and `import()` above — together with `import()`'s dedicated 422-handling exception, Failure Mode #3, and Open Item #3's idempotency question — are not named in either canonical scope statement for this phase: `contract-data.json`'s `scope.mvp` Pipes row (`$user->connectedAccounts(), access-token fetch with auto-refresh, org provider-config passthrough`) or the matching `execution.phases` note (`connectedAccounts relations, access-token fetch with auto-refresh, org provider-config passthrough`) — both list exactly three capabilities. These three methods are included here on the strength of phase-specific task direction that is not itself present as a citable artifact in the contract or brief available to this spec's reviewers. This is a genuine scope expansion beyond the contract, not a restatement of it — the same exclusion rigor this section applies to the four SDK methods in the "Explicitly out of scope" table below is not symmetrically applied to justify these three inclusions. Recorded here so an approver consciously ratifies the expansion — or, absent that ratification, trims Component 1 back to the four contract-traceable methods (`connectedAccounts`, `accessToken`, `providerConfig`, `configureProvider`) and defers `connect()`/`disconnect()`/`import()` to a Full-tier or future addendum pending explicit contract amendment — rather than the expansion reading as already-approved.

**No Full-tier depth-extension items apply to Pipes.** Contract's `scope.full` array (invitations, JWT templates + CORS, groups API, FGA resource-graph conveniences, opt-in FGA cache) does not name Pipes, and Phase 12 (Depth Extensions) omits it entirely. This phase ships usable-core only.

**Explicitly out of scope for this phase** (scope discipline, not oversight):

| SDK method | Why excluded |
|---|---|
| `Pipes::listDataIntegrations` / `createDataIntegration` / `updateDataIntegration` / `deleteDataIntegration` | Environment-level Data Integration *definition* (which providers exist, their OAuth app credentials, custom-provider registration). This is exactly the "provider configuration is Dashboard-only" boundary — wrapping it would build the thing the phase direction says not to build. |
| `Pipes::updateDataIntegrationApiKey` / `createDataIntegrationCredential` | API-key-authenticated Pipes integrations and the legacy combined credential-vend endpoint. The phase's binding direction speaks entirely in OAuth terms (`getAccessToken`, `authorizeDataIntegration`, `createUserConnectedAccount`); API-key-flavored connections are a secondary `auth_method` not named by the contract row. Deferred to Full-tier/future if demand appears. |
| `Pipes::getUserConnectedAccount` | A single-provider getter. `connectedAccounts()` already returns the full `Collection`; `->firstWhere('providerSlug', $slug)` covers single-provider lookups without a redundant method surface. |
| `Pipes::updateUserConnectedAccount` | Token *rotation* for an already-imported connection. The phase direction names `createUserConnectedAccount` (import) only. See Open Items — this is a real gap if `createUserConnectedAccount` turns out not to be upsert-safe on retry. |

## 3. Decisions Considered and Rejected

Contract decisions relevant to this phase, carried verbatim with their rejected alternative and reason, plus how each binds Phase 11:

| # | Decision | Rejected | Reason | Relevance to Pipes |
|---|---|---|---|---|
| 1 | Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id` ↔ `external_id` linking, refreshed by the events pipeline | No local state / read-through API calls per request | Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events | Directly forbids a `connected_accounts` table. `connectedAccounts()` is a live, uncached read-through by design — this is the one area where "no local state" is the *default* posture, not an exception to it. |
| 2 | Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage (Vault 0%, audit export, user-scoped API keys, flags verb-mismatch, **Connect/MCP, Pipes**) | SDK fakes only | Wire fidelity where possible; emulate v0.6.0 covers ~62% of endpoints | Pipes is explicitly named as a MockHandler-only area. `tests/Feature/PipesTest.php` never boots `workos/emulate`. |
| 3 | Typed sidecar events are bounded to types feeding the declared projections + audit/domain-verification; everything else dispatches a generic `WorkosEvent` | A typed Laravel event class per WorkOS event type | Unbounded typed mapping would cover out-of-scope products | `pipes.connected_account.{connected,connection_failed,disconnected,reauthorization_needed}` webhook-subscription event types exist in the SDK (`CreateWebhookEndpointEvents` enum) but are **not** in the declared-projection bounded set. This phase does not add a typed `PipesConnectedAccountXxx` Laravel event — those types, if subscribed at all, ride Phase 4's generic `WorkosEvent` fallback. Reinforces why there's no local cache to invalidate: there's no dedicated sync path feeding one. |
| 4 | Events API sidecar is the primary sync transport; webhooks are optional low-latency triggers sharing the same Laravel event objects | Webhooks-primary sync | WorkOS docs recommend the Events API as primary; shared event objects mean one listener story across transports | Consequence of #3: an app that wants to react to a Pipes reauthorization event listens for the generic `WorkosEvent` (or its webhook twin) — Phase 11 supplies no dedicated listener recipe, that's a documentation cross-reference, not new code here. |
| 5 | Credentials read from config only; `env()` is never read outside config files | Runtime `env()` reads like the SDK's own fallback does | `php artisan config:cache` empties env at runtime | Blanket doctrine on all new `src/` code. `PipesManager` reads no config directly at all — it only consumes the already-constructed SDK client via `WorkosClientManager` (Phase 1), which already enforces this. |
| 6 | Full org context in v1: claims-resolved current org, org-switch route via AuthKit re-auth, tenant middleware | Read-only org context, apps build their own switcher | Multi-org ergonomics are table stakes for B2B apps | Every Pipes SDK call takes an optional `organizationId`. This phase does not resolve "current org" itself — callers pass `$organization->workos_id` explicitly (or `null` for user-level connections) using whatever Phase 3 exposes for current-org resolution. Pipes stays a thin string-ID API, not an org-context-aware one. |
| 7 | FGA ships without caching — direct Check API per check; opt-in caching with events-driven invalidation is Full tier | Default per-check cache in MVP | No stated latency requirement, and a stale cache entry is a stale permission decision | Same reasoning applies transitively to Pipes even though FGA isn't the subject: `connectedAccounts()`/`accessToken()` are also live, uncached, per-call — the contract's general skepticism of unearned caching governs this phase's design by analogy. See Failure Mode #5. |
| 8 | Breadth-complete v1: all 16 scope areas ship in the first version at usable-core depth; phases are build order, not releases | Release-tiered rollout | Nick: "literally all of the features I listed are our MVP" | Explains why Pipes ships now, at usable-core depth, rather than being deferred — it is not a "nice to have" tacked onto a later release. |

## 4. Components

### Component 1 — `PipesManager` (bound at `Authkit::pipes()`)

**Laravel mechanism**: a plain PHP class resolved through the container and exposed as a fluent accessor off the `Authkit` facade/manager — the same "areas hang off `Authkit` accessors" pattern the template's Shared Conventions table already names (`Authkit::connect()`, `Authkit::pipes()`, `Authkit::portalLink()`).

**SDK methods wrapped** (exact names, from `WorkOS\Service\Pipes` and `WorkOS\Service\PipesProvider`, v9.1.0 vendored source):

| PipesManager method | SDK method | SDK class |
|---|---|---|
| `connectedAccounts()` | `listUserDataProviders(string $userId, ?string $organizationId)` | `Pipes` |
| `accessToken()` | `getAccessToken(string $provider, string $userId, ?string $organizationId)` | `Pipes` |
| `connect()` | `authorizeDataIntegration(string $slug, string $userId, ?string $organizationId, ?string $returnTo, ?array $config)` | `Pipes` |
| `disconnect()` | `deleteUserConnectedAccount(string $userId, string $slug, ?string $organizationId)` | `Pipes` |
| `import()` | `createUserConnectedAccount(string $userId, string $slug, ?string $accessToken, ?string $refreshToken, ?\DateTimeImmutable $expiresAt, ?array $scopes, ?PipeConnectedAccountState $state, ?string $organizationId)` | `Pipes` |
| `providerConfig()` | `listOrganizationDataIntegrationConfigurations(string $organizationId)` | `PipesProvider` |
| `configureProvider()` | `updateOrganizationDataIntegrationConfiguration(string $organizationId, string $slug, ?bool $enabled, ?array $scopes, ?string $clientId, ?string $clientSecret, ?array $config)` | `PipesProvider` |

**Design guarantee — no SDK types cross the public boundary.** Two WorkOS SDK enums would otherwise leak into consumer-visible type hints: `WorkOS\Resource\ConnectedAccountState` (response state, 3 cases) and `WorkOS\Resource\PipeConnectedAccountState` (import-request state, 2 cases — no `Disconnected`). Every `PipesManager` public method signature and every DTO public property uses package-owned types only (Component 2). SDK types are used freely *inside* `src/` factory methods (`fromArray`-style mappers) — the grep boundary in the success criteria is scoped to `workbench/`, not `src/` — but nothing SDK-typed is reachable from a `Collection<ConnectedAccountData>` or an exception property.

**Key design (interfaces/signatures):**

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes;

use Authkit\Authkit\Pipes\Data\ConnectedAccountData;
use Authkit\Authkit\Pipes\Data\PipeAccessTokenData;
use Authkit\Authkit\Pipes\Data\ProviderConfigurationData;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesInvalidImportException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Authkit\Authkit\WorkosClientManager;
use Illuminate\Support\Collection;
use WorkOS\Exception\UnprocessableEntityException;
use WorkOS\Resource\DataIntegrationAccessTokenResponseError;
use WorkOS\Resource\PipeConnectedAccountState as SdkImportState;

final class PipesManager
{
    public function __construct(
        private readonly WorkosClientManager $clients,
    ) {}

    /** @return Collection<int, ConnectedAccountData> */
    public function connectedAccounts(string $userId, ?string $organizationId = null): Collection
    {
        $response = $this->clients->client()->pipes()->listUserDataProviders(
            userId: $userId,
            organizationId: $organizationId,
        );

        return Collection::make($response->data)
            ->filter(fn ($provider) => $provider->connectedAccount !== null)
            ->map(fn ($provider) => ConnectedAccountData::fromProvider($provider))
            ->values();
    }

    public function accessToken(string $userId, string $providerSlug, ?string $organizationId = null): PipeAccessTokenData
    {
        $response = $this->clients->client()->pipes()->getAccessToken(
            provider: $providerSlug,
            userId: $userId,
            organizationId: $organizationId,
        );

        if ($response->error === DataIntegrationAccessTokenResponseError::NotInstalled) {
            throw PipesAccountNotConnectedException::forProvider($providerSlug, $userId);
        }

        $missingScopes = $response->accessToken?->missingScopes ?? [];

        if ($response->error === DataIntegrationAccessTokenResponseError::NeedsReauthorization || $missingScopes !== []) {
            throw PipesReauthorizationRequiredException::forProvider(
                providerSlug: $providerSlug,
                userId: $userId,
                organizationId: $organizationId,
                missingScopes: $missingScopes,
                reauthorizationUrl: $this->connect($userId, $providerSlug, organizationId: $organizationId),
            );
        }

        if ($response->accessToken === null) {
            throw new \RuntimeException(sprintf(
                'WorkOS returned an active access-token response with no token payload for provider "%s".',
                $providerSlug,
            ));
        }

        return PipeAccessTokenData::fromResponse($response->accessToken);
    }

    public function connect(
        string $userId,
        string $providerSlug,
        ?string $returnTo = null,
        ?string $organizationId = null,
        ?array $config = null,
    ): string {
        $response = $this->clients->client()->pipes()->authorizeDataIntegration(
            slug: $providerSlug,
            userId: $userId,
            organizationId: $organizationId,
            returnTo: $returnTo,
            config: $config,
        );

        return $response->url;
    }

    public function disconnect(string $userId, string $providerSlug, ?string $organizationId = null): void
    {
        $this->clients->client()->pipes()->deleteUserConnectedAccount(
            userId: $userId,
            slug: $providerSlug,
            organizationId: $organizationId,
        );
    }

    public function import(
        string $userId,
        string $providerSlug,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?array $scopes = null,
        ?ConnectedAccountState $state = null,
        ?string $organizationId = null,
    ): ConnectedAccountData {
        try {
            $account = $this->clients->client()->pipes()->createUserConnectedAccount(
                userId: $userId,
                slug: $providerSlug,
                accessToken: $accessToken,
                refreshToken: $refreshToken,
                expiresAt: $expiresAt,
                scopes: $scopes,
                state: $this->toSdkImportState($state),
                organizationId: $organizationId,
            );
        } catch (UnprocessableEntityException $exception) {
            throw PipesInvalidImportException::fromSdkException($providerSlug, $exception);
        }

        return ConnectedAccountData::fromConnectedAccount($account, $providerSlug);
    }

    /** @return Collection<int, ProviderConfigurationData> */
    public function providerConfig(string $organizationId): Collection
    {
        $response = $this->clients->client()->pipesProvider()->listOrganizationDataIntegrationConfigurations(
            organizationId: $organizationId,
        );

        return Collection::make($response->data)
            ->map(fn ($config) => ProviderConfigurationData::fromResponse($config))
            ->values();
    }

    public function configureProvider(
        string $organizationId,
        string $providerSlug,
        ?bool $enabled = null,
        ?array $scopes = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?array $config = null,
    ): ProviderConfigurationData {
        $response = $this->clients->client()->pipesProvider()->updateOrganizationDataIntegrationConfiguration(
            organizationId: $organizationId,
            slug: $providerSlug,
            enabled: $enabled,
            scopes: $scopes,
            clientId: $clientId,
            clientSecret: $clientSecret,
            config: $config,
        );

        return ProviderConfigurationData::fromResponse($response);
    }

    private function toSdkImportState(?ConnectedAccountState $state): ?SdkImportState
    {
        if ($state === null) {
            return null;
        }

        if ($state === ConnectedAccountState::Disconnected) {
            throw new \InvalidArgumentException(
                'Cannot import a connected account with state "disconnected"; call disconnect() instead.',
            );
        }

        return SdkImportState::from($state->value);
    }
}
```

**Implementation steps:**

1. `php artisan make:class` has no direct package equivalent inside `src/` (that generator targets `app/`); create the directory/class files by hand under `src/Pipes/` following the PSR-4 root already declared in `composer.json` (`Authkit\Authkit\` → `src/`).
2. Confirm the real Phase 1 `WorkosClientManager` SDK-instance accessor method name against `src/WorkosClientManager.php` before wiring `$this->clients->client()` — this spec assumes `client(): \WorkOS\WorkOS` (see Open Items).
3. Implement `PipesManager` exactly as above.
4. Bind it as a container singleton in `AuthkitServiceProvider::register()` (Section 6).
5. Add `Authkit::pipes(): PipesManager` accessor to `src/Authkit.php`.

**Feedback loop:**

- **Playground**: `tests/Feature/PipesTest.php`, MockHandler-backed (per Decision #2 — no emulate).
- **Parameterized experiment**: Pest datasets per method, each varying the mocked HTTP response body:
  - `connectedAccounts`: environment with 0 providers / providers all disconnected / mixed connected+unconnected.
  - `accessToken`: `active=true, missingScopes=[]` (happy path) / `active=true, missingScopes=['repo']` (soft reauth) / `error=needs_reauthorization` (hard reauth) / `error=not_installed`.
  - `import`: valid access-token-only payload / valid access+refresh+expiry / 422 response body (invalid combo).
  - `configureProvider`: enable+scopes / BYOO client_id+client_secret.
- **Check**: `vendor/bin/pest --filter=Pipes` (seconds; no HTTP, no emulate boot).

### Component 2 — Package-owned enums: `ConnectedAccountState`, `AuthMethod`

**Laravel mechanism**: pure PHP backed enums, the idiomatic 8.3+ replacement for string constants, used as the sealing layer described in Component 1.

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes;

enum ConnectedAccountState: string
{
    case Connected = 'connected';
    case NeedsReauthorization = 'needs_reauthorization';
    case Disconnected = 'disconnected';

    public static function fromSdk(\WorkOS\Resource\ConnectedAccountState $state): self
    {
        return self::from($state->value);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes;

enum AuthMethod: string
{
    case OAuth = 'oauth';
    case ApiKey = 'api_key';
    case ClientCredentials = 'client_credentials';

    public static function fromSdk(\WorkOS\Resource\DataIntegrationAuthMethods $method): self
    {
        return self::from($method->value);
    }
}
```

Both enums are constructed from the string value of the corresponding SDK enum (`ConnectedAccountState::fromSdk()` also accepts the narrower `PipeConnectedAccountState` — same string values, so `self::from($sdk->value)` works for either since PHP backed-enum `from()` only inspects the scalar).

**Trivial — skip feedback loop.** Pure value mapping with no branching beyond the enum cases themselves; exercised incidentally by Component 1's DTO-construction assertions.

### Component 3 — DTOs: `ConnectedAccountData`, `PipeAccessTokenData`, `ProviderConfigurationData`

**Laravel mechanism**: plain `readonly` value objects (no new dependency — `spatie/laravel-data` is not in `composer.json` and this phase doesn't justify adding it for three small classes).

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Data;

use Authkit\Authkit\Pipes\AuthMethod;
use Authkit\Authkit\Pipes\ConnectedAccountState;
use WorkOS\Resource\ConnectedAccount;
use WorkOS\Resource\DataIntegrationsListResponseData;

final readonly class ConnectedAccountData
{
    public function __construct(
        public string $id,
        public string $providerSlug,
        public ?string $providerName,
        public ?string $userId,
        public ?string $organizationId,
        public ConnectedAccountState $state,
        /** @var array<string> */
        public array $scopes,
        public ?AuthMethod $authMethod,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromProvider(DataIntegrationsListResponseData $provider): self
    {
        $account = $provider->connectedAccount; // filtered non-null by PipesManager::connectedAccounts()

        return new self(
            id: $account->id,
            providerSlug: $provider->slug,
            providerName: $provider->name,
            userId: $account->userId,
            organizationId: $account->organizationId,
            state: ConnectedAccountState::fromSdk($account->state),
            scopes: $account->scopes,
            authMethod: $account->authMethod !== null ? AuthMethod::fromSdk($account->authMethod) : null,
            createdAt: $account->createdAt,
            updatedAt: $account->updatedAt,
        );
    }

    public static function fromConnectedAccount(ConnectedAccount $account, string $providerSlug): self
    {
        return new self(
            id: $account->id,
            providerSlug: $providerSlug,
            providerName: null, // not present on this response shape
            userId: $account->userId,
            organizationId: $account->organizationId,
            state: ConnectedAccountState::fromSdk($account->state),
            scopes: $account->scopes,
            authMethod: $account->authMethod !== null ? AuthMethod::fromSdk($account->authMethod) : null,
            createdAt: $account->createdAt,
            updatedAt: $account->updatedAt,
        );
    }

    public function isConnected(): bool
    {
        return $this->state === ConnectedAccountState::Connected;
    }

    public function needsReauthorization(): bool
    {
        return $this->state === ConnectedAccountState::NeedsReauthorization;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Data;

use WorkOS\Resource\DataIntegrationAccessTokenResponseAccessToken;

final readonly class PipeAccessTokenData
{
    public function __construct(
        public string $accessToken,
        public ?\DateTimeImmutable $expiresAt,
        /** @var array<string> */
        public array $scopes,
        /** @var array<string> */
        public array $missingScopes,
    ) {}

    public static function fromResponse(DataIntegrationAccessTokenResponseAccessToken $token): self
    {
        return new self(
            accessToken: $token->accessToken,
            expiresAt: $token->expiresAt,
            scopes: $token->scopes,
            missingScopes: $token->missingScopes,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Data;

use WorkOS\Resource\DataIntegrationConfigurationResponse;

final readonly class ProviderConfigurationData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $providerSlug,
        public string $name,
        public bool $enabled,
        /** @var array<string>|null */
        public ?array $scopes,
        /** @var array<string, string> */
        public array $config,
        public bool $hasOrganizationCredentials,
        public ?string $clientId,
        public ?string $clientSecretLastFour,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromResponse(DataIntegrationConfigurationResponse $response): self
    {
        return new self(
            id: $response->id,
            organizationId: $response->organizationId,
            providerSlug: $response->slug,
            name: $response->name,
            enabled: $response->enabled,
            scopes: $response->scopes,
            config: $response->config,
            hasOrganizationCredentials: $response->credentials?->hasCredentials ?? false,
            clientId: $response->credentials?->clientId,
            clientSecretLastFour: $response->credentials?->clientSecretLastFour,
            createdAt: $response->createdAt,
            updatedAt: $response->updatedAt,
        );
    }
}
```

Note on `ProviderConfigurationData`: `clientSecretLastFour` only ever carries the WorkOS-redacted last-four characters (`DataIntegrationCredentials::$clientSecretLastFour` — the SDK never returns the full secret), so exposing it on the DTO introduces no new secret-handling surface.

**Trivial — skip feedback loop.** Pure mapping from SDK response shape to package DTO; exercised incidentally by Component 1's tests.

### Component 4 — Exceptions: `PipesReauthorizationRequiredException`, `PipesAccountNotConnectedException`, `PipesInvalidImportException`

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Exceptions;

final class PipesReauthorizationRequiredException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $providerSlug,
        public readonly string $userId,
        public readonly ?string $organizationId,
        /** @var array<string> */
        public readonly array $missingScopes,
        public readonly string $reauthorizationUrl,
    ) {
        parent::__construct($message);
    }

    public static function forProvider(
        string $providerSlug,
        string $userId,
        ?string $organizationId,
        array $missingScopes,
        string $reauthorizationUrl,
    ): self {
        return new self(
            message: sprintf('Connected account for provider "%s" needs reauthorization.', $providerSlug),
            providerSlug: $providerSlug,
            userId: $userId,
            organizationId: $organizationId,
            missingScopes: $missingScopes,
            reauthorizationUrl: $reauthorizationUrl,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Exceptions;

final class PipesAccountNotConnectedException extends \RuntimeException
{
    private function __construct(string $message, public readonly string $providerSlug, public readonly string $userId)
    {
        parent::__construct($message);
    }

    public static function forProvider(string $providerSlug, string $userId): self
    {
        return new self(
            sprintf('User "%s" has not connected provider "%s".', $userId, $providerSlug),
            $providerSlug,
            $userId,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Exceptions;

use WorkOS\Exception\UnprocessableEntityException;

final class PipesInvalidImportException extends \RuntimeException
{
    private function __construct(string $message, public readonly string $providerSlug, \Throwable $previous)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function fromSdkException(string $providerSlug, UnprocessableEntityException $exception): self
    {
        return new self(
            sprintf('WorkOS rejected the connected-account import for provider "%s": %s', $providerSlug, $exception->getMessage()),
            $providerSlug,
            $exception,
        );
    }
}
```

Note the reauthorization URL is fetched **eagerly** inside `PipesManager::accessToken()` (an extra `authorizeDataIntegration` call on the exceptional branch only, never on the happy path) rather than lazily inside the exception — this keeps the exception a plain data carrier and keeps the test surface simple (mock two sequential responses, assert the exception's public property).

**No separate feedback loop** — exercised entirely within Component 1's suite (the exception-throwing branches are Component 1's dataset rows). Listed as its own component here only because it's independently reviewable code, not because it needs its own test file.

### Component 5 — `HasWorkosUser` conveniences: `connectedAccounts()`, `pipe()`

**Laravel mechanism**: two thin methods added to the existing `HasWorkosUser` trait (created by Phase 2 on the `workos_id` projection column). This is the only trait touched — `connect()`/`disconnect()`/`import()`/`providerConfig()`/`configureProvider()` are **not** added to the trait; they stay direct `Authkit::pipes()->...()` calls taking an explicit `workos_id` string, per the phase's literal binding direction ("HasWorkosUser gains connectedAccounts()/pipe(slug) conveniences" — only those two).

```php
// Added to Authkit\Authkit\Concerns\HasWorkosUser (Phase 2)

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Pipes\Data\ConnectedAccountData;
use Authkit\Authkit\Pipes\Data\PipeAccessTokenData;
use Illuminate\Support\Collection;

/** @return Collection<int, ConnectedAccountData> */
public function connectedAccounts(?string $organizationId = null): Collection
{
    return Authkit::pipes()->connectedAccounts($this->workos_id, $organizationId);
}

public function pipe(string $providerSlug, ?string $organizationId = null): PipeAccessTokenData
{
    return Authkit::pipes()->accessToken($this->workos_id, $providerSlug, $organizationId);
}
```

If Phase 2 lands a guard method (e.g. `requireWorkosId(): string` that throws when a user hasn't completed first-login linking), prefer it over the raw `$this->workos_id` property access shown above — this spec does not mandate adding that guard itself, since it is Phase 2's concern, not Phase 11's.

**Implementation steps:**

1. Locate the trait Phase 2 created (assumed `src/Concerns/HasWorkosUser.php` — see Open Items).
2. Add the two methods above.
3. Add a workbench-model-level test asserting delegation (Component 1's mocked HTTP layer, called through the trait instead of the manager directly).

**Feedback loop:**

- **Playground**: same `tests/Feature/PipesTest.php`, a dedicated `describe('HasWorkosUser conveniences', ...)` block using the workbench `User` model with the trait applied.
- **Parameterized experiment**: assert `$user->connectedAccounts()` and `$user->pipe($slug)` produce identical results to calling `Authkit::pipes()->connectedAccounts($user->workos_id)` / `->accessToken($user->workos_id, $slug)` directly against the same mocked response — i.e. the trait is a pure delegation, not a second implementation.
- **Check**: `vendor/bin/pest --filter=Pipes` (same command as Component 1 — one suite, one filter).

### Component 6 — Workbench demonstration

**Laravel mechanism**: a workbench controller + routes exercising every `PipesManager` entry point and the two trait conveniences, with zero `WorkOS\` references — the area's contribution to the eventual Phase 13 G2 grep enforcement.

```php
<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PipesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->connectedAccounts()->map(fn ($account) => [
                'provider' => $account->providerSlug,
                'state' => $account->state->value,
            ])->all()
        );
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        $url = Authkit::pipes()->connect(
            userId: $request->user()->workos_id,
            providerSlug: $provider,
            returnTo: route('pipes.index'),
        );

        return redirect($url);
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        Authkit::pipes()->disconnect($request->user()->workos_id, $provider);

        return redirect()->route('pipes.index');
    }
}
```

**Implementation steps:**

1. `php artisan make:controller PipesController --no-interaction` inside the workbench skeleton (Testbench's `workbench:build` picks up `workbench/app/Http/Controllers/` once created — matches the binding instruction to use generators where applicable, even for a small file).
2. Fill in the body as above.
3. Register the three routes in `workbench/routes/web.php` (Section 5).

**Feedback loop**: not iterative business logic — deterministic route wiring. Skip a dedicated automated loop; verify with `composer serve` (Testbench workbench server) and a manual `curl` against `/pipes` while authenticated, per the template's guidance that `composer serve` is the playground "for route/middleware areas."

## 5. File Changes

### New files

| Path | Traces to |
|---|---|
| `src/Pipes/PipesManager.php` | Scope row: `Authkit::pipes()` manager (Component 1) |
| `src/Pipes/ConnectedAccountState.php` | SDK-leak sealing enum (Component 2) |
| `src/Pipes/AuthMethod.php` | SDK-leak sealing enum (Component 2) |
| `src/Pipes/Data/ConnectedAccountData.php` | `connectedAccounts()` DTO (Component 3) |
| `src/Pipes/Data/PipeAccessTokenData.php` | `accessToken()` DTO (Component 3) |
| `src/Pipes/Data/ProviderConfigurationData.php` | Org provider-config passthrough DTO (Component 3) |
| `src/Pipes/Exceptions/PipesReauthorizationRequiredException.php` | Missing-scope surfacing (Component 4) |
| `src/Pipes/Exceptions/PipesAccountNotConnectedException.php` | `not_installed` failure surfacing (Component 4) |
| `src/Pipes/Exceptions/PipesInvalidImportException.php` | 422 on invalid import (Component 4) |
| `tests/Feature/PipesTest.php` | MockHandler-backed area suite (Decision #2; success criterion 5) |
| `workbench/app/Http/Controllers/PipesController.php` | Workbench demonstration (Component 6) |

### Modified files

| Path | Change | Traces to |
|---|---|---|
| `src/Authkit.php` | Add `pipes(): PipesManager` accessor method | `Authkit::pipes()` scope row |
| `src/AuthkitServiceProvider.php` | Bind `PipesManager` as a container singleton in `register()` | Registration (Section 6) |
| `src/Concerns/HasWorkosUser.php` | Add `connectedAccounts()` and `pipe()` methods | `HasWorkosUser` conveniences (Component 5) |
| `workbench/routes/web.php` | Register `pipes.index` / `pipes.connect` / `pipes.disconnect` routes | Workbench demonstration (Component 6) |

No changes to `config/authkit.php`, `database/migrations/`, or `routes/authkit-laravel.php` — Pipes introduces no new config keys, no projection table (by design — see Decision #1), and no package-owned HTTP routes (connect/disconnect are helpers returning a URL/performing a delete, not routes the package registers for the consuming app).

## 6. Service Provider Registration Diff

`register()` — add:

```php
$this->app->singleton(
    \Authkit\Authkit\Pipes\PipesManager::class,
    fn ($app) => new \Authkit\Authkit\Pipes\PipesManager($app->make(\Authkit\Authkit\WorkosClientManager::class)),
);
```

`boot()` — no changes. Pipes has no routes, migrations, views, or middleware to load/publish.

`Authkit::pipes()` resolves the singleton via the container rather than constructing `PipesManager` inline, so it works regardless of what constructor shape the `Authkit` class itself ends up with by Phase 11 (unconfirmed — see Open Items):

```php
// src/Authkit.php
public function pipes(): \Authkit\Authkit\Pipes\PipesManager
{
    return app(\Authkit\Authkit\Pipes\PipesManager::class);
}
```

## 7. Testing Requirements

**Suite file**: `tests/Feature/PipesTest.php` — MockHandler-backed only (Decision #2, and the task's binding direction: "Test path: MockHandler"). Top-of-file comment states the test path explicitly, per the shared template's Test-Path Selection rule ("Never mix paths within one test; name the path in the test file's top comment").

**Test setup** (MockHandler injection — see Open Items for the unconfirmed exact hook name):

```php
<?php

declare(strict_types=1);

// Test path: MockHandler (Pipes has zero emulate coverage — see contract Decision #2)

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Pipes\ConnectedAccountState;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesInvalidImportException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Authkit\Authkit\WorkosClientManager;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->mock = new MockHandler();

    // TODO(Open Item): confirm the real Phase 1 injection call once WorkosClientManager exists.
    app(WorkosClientManager::class)->fake($this->mock);
});
```

**Key cases (happy path):**

- `connectedAccounts()` maps a `listUserDataProviders` response, filters out providers with `connected_account: null`, returns `Collection<ConnectedAccountData>` with correct `providerSlug`/`state`/`scopes`.
- `connectedAccounts()` returns an empty `Collection` when the environment has integrations but the user has connected none.
- `accessToken()` happy path (`active: true, missing_scopes: []`) returns `PipeAccessTokenData` with the right `accessToken`/`expiresAt`/`scopes`.
- `connect()` returns the raw authorize URL string from `authorizeDataIntegration`.
- `disconnect()` calls the delete endpoint and returns `void` with no exception on a 200.
- `import()` happy path (access token only) returns `ConnectedAccountData` reflecting the created state.
- `providerConfig()` lists org-level configurations mapped to `Collection<ProviderConfigurationData>`.
- `configureProvider()` updates and returns a `ProviderConfigurationData` reflecting new `enabled`/`scopes`.
- `HasWorkosUser::connectedAccounts()` / `::pipe()` delegate to the exact same manager calls (assert identical output to calling the manager directly with the same mocked response).

**Key cases (edge/error — named failure modes, see Section 8):**

- `accessToken()` throws `PipesAccountNotConnectedException` when the response has `error: not_installed`.
- `accessToken()` throws `PipesReauthorizationRequiredException` when `error: needs_reauthorization` — queue a second mocked response for the internal `authorizeDataIntegration` call and assert `$exception->reauthorizationUrl` equals that response's `url`.
- `accessToken()` throws `PipesReauthorizationRequiredException` when `active: true` but `missing_scopes` is non-empty (the *soft* reauth case, distinct from the `error` field) — assert `$exception->missingScopes` matches.
- `import()` throws `PipesInvalidImportException` when MockHandler returns a 422 (e.g. `refresh_token` supplied without `access_token`) — assert the WorkOS validation message passes through in `$exception->getMessage()`.
- `import()` with `state: ConnectedAccountState::Disconnected` throws `\InvalidArgumentException` before any HTTP call is made (no queued response consumed).

**Seed data** (MockHandler fixture bodies — field names taken directly from the vendored SDK's `fromArray()` methods, not guessed):

```json
// listUserDataProviders — one connected, one not
{
  "object": "list",
  "data": [
    {
      "object": "data_provider", "id": "di_1", "name": "GitHub", "description": null,
      "slug": "github", "integration_type": "github", "credentials_type": "oauth2",
      "scopes": ["repo"], "ownership": "userland_user",
      "created_at": "2026-01-01T00:00:00.000Z", "updated_at": "2026-01-01T00:00:00.000Z",
      "connected_account": {
        "object": "connected_account", "id": "ca_1", "user_id": "user_1", "organization_id": null,
        "scopes": ["repo"], "state": "connected",
        "created_at": "2026-01-01T00:00:00.000Z", "updated_at": "2026-01-01T00:00:00.000Z",
        "auth_method": "oauth"
      }
    },
    {
      "object": "data_provider", "id": "di_2", "name": "Slack", "description": null,
      "slug": "slack", "integration_type": "slack", "credentials_type": "oauth2",
      "scopes": null, "ownership": "userland_user",
      "created_at": "2026-01-01T00:00:00.000Z", "updated_at": "2026-01-01T00:00:00.000Z",
      "connected_account": null
    }
  ]
}
```

```json
// getAccessToken — needs_reauthorization
{ "active": false, "error": "needs_reauthorization" }
```

```json
// authorizeDataIntegration — reauth URL (queued right after the response above)
{ "url": "https://api.workos.com/data-integrations/github/authorize?..." }
```

```json
// 422 on invalid import
{ "message": "refresh_token requires access_token to also be provided", "code": "validation_error" }
```

## 8. Failure Modes

| # | Named failure | What happens | Mitigation / documented behavior |
|---|---|---|---|
| 1 | **Not-installed misread as WorkOS-down** (data shadow) | A caller wraps a Pipes call in a broad `catch (\Throwable)` and treats *any* exception as "WorkOS is down" — silently swallowing the distinct, expected business state "user hasn't connected this provider yet." | `PipesAccountNotConnectedException` is its own named type, never a generic exception. Callers must branch on exception type, not presence/absence of an exception. |
| 2 | **Reauthorization drift (missing-scope surfacing)** | The provider's granted scopes no longer cover what the integration now requests (scope creep on WorkOS's or the provider's side), surfaced either as `error: needs_reauthorization` (hard) or `missing_scopes: [...]` on an otherwise-active token (soft). | `PipesReauthorizationRequiredException` unifies both branches, eagerly carrying `reauthorizationUrl` (fetched via `authorizeDataIntegration` on the exceptional path only) so the caller can redirect immediately without a second round-trip of their own. |
| 3 | **422 on invalid import token-field combination** | `createUserConnectedAccount` rejects combinations like `refresh_token` without `access_token`, `expires_at` without `access_token`, or an entirely empty payload. | Caught as `UnprocessableEntityException`, rethrown as `PipesInvalidImportException` with the WorkOS validation message passed through verbatim — never a generic 500-shaped error. |
| 4 | **WorkOS unreachable / 5xx after SDK retry exhaustion** | `getAccessToken`, `connectedAccounts`, `connect`, `disconnect`, `import`, and the `PipesProvider` passthrough all propagate `WorkOS\Exception\ServerException` / `ConnectionException` unmodified once the SDK's built-in 429/5xx retry-with-backoff exhausts. | `PipesManager` does not catch or suppress these. They must never be conflated with `PipesAccountNotConnectedException` (failure #1) — an outage is not the same fact as "not connected." |
| 5 | **Chatty `connectedAccounts()` — no batch endpoint, no cache** | Pipes has no "list connected accounts" endpoint; `connectedAccounts()` rides `listUserDataProviders`, one live HTTP call per invocation. Calling it per-request for N users in a loop is N calls. | Documented as-designed, not "fixed": Decision #1 forbids a local cache/table for this data, and Decision #7's skepticism of unearned caching applies by analogy. Callers needing to render this for many users should batch their own logic (e.g. a queued job, not a request-time N+1). |
| 6 | **Concurrent disconnect + accessToken — race-immune by construction** | Request A calls `accessToken()` while request B concurrently calls `disconnect()` for the same user+provider. | Because there is no local cache to go stale or split-brain, each request gets a live, internally-consistent answer from WorkOS at the instant it asked — the class of race that would corrupt a locally-cached copy simply doesn't exist here. This is the direct payoff of Decision #1, not an accident. |
| 7 | **Import double-dispatch idempotency — unconfirmed (Open Item)** | `createUserConnectedAccount` has no documented `Idempotency-Key` support (unlike `AuditLogs::createEvent`). A retried queued import job could POST twice for the same user+slug+organization. | Unconfirmed whether the endpoint upserts (safe) or conflicts (unsafe) on a second call for the same key. Flagged as an Open Item; until confirmed, callers importing from a queued context should apply Laravel's own dedup (e.g. `WithoutOverlapping` / unique-job middleware) rather than assume server-side idempotency. |
| 8 | **Imported token already stale at creation** | `import()` succeeding (no exception) does not guarantee a usable token — WorkOS derives `state` from the supplied token/expiry combination, and an already-expired `expiresAt` with no `refreshToken` can yield `state: needs_reauthorization` immediately. | Callers must inspect the returned `ConnectedAccountData->state` (via `isConnected()`/`needsReauthorization()`), not just the absence of a thrown exception. |
| 9 | **emulate drift — not applicable, by design** | The shared template's failure-mode prompt asks every delta to address emulate-vs-production drift. | N/A for this phase: emulate has no Pipes coverage at all (Decision #2), so there is no drift class to reconcile — MockHandler is the only test path, full stop. |
| 10 | **Org-level provider disable doesn't revoke in-flight tokens instantly** | `configureProvider(enabled: false)` by an org admin doesn't retroactively invalidate already-issued access tokens; a concurrent `accessToken()` call may still succeed until WorkOS's own enforcement catches up. | Documented as expected — WorkOS owns the enforcement boundary for this, not the package. No client-side "is this provider still enabled" pre-check is added (would just be another cache to go stale). |

Config-missing failure mode (shared template prompt): not newly relevant here. `PipesManager` reads no config of its own; it only calls `$this->clients->client()`, and Phase 1's `WorkosClientManager` already fails fast with a named-config-key exception (via the SDK's own `ConfigurationException` from `HttpClient::requireApiKey()`/`requireClientId()`) before any Pipes code runs.

## 9. Deviations from Template

1. **Feature-area subdirectory structure** (`src/Pipes/`, `src/Pipes/Data/`, `src/Pipes/Exceptions/`) rather than flat `src/`. The template fixes namespaces and cross-cutting conventions but not internal file organization; this keeps a ~10-file area from cluttering `src/`'s top level. Not a contradiction of any Shared Convention.
2. **Two package-owned enums added beyond a raw pass-through** (`ConnectedAccountState`, `AuthMethod`) purely to prevent two WorkOS SDK enum types from leaking into public method signatures / DTO properties. Justified against Goal 2 (zero direct SDK references in consumer code) and success criterion 4 (grep enforcement) — without them, any workbench code branching on `$account->state` would need `use WorkOS\Resource\ConnectedAccountState;`, an outright violation.
3. **Two Phase 1 details are assumed, not confirmed** (both called out again in Open Items): the `WorkosClientManager` SDK-instance accessor method name (assumed `client(): \WorkOS\WorkOS`), and the test-only MockHandler injection mechanism (assumed a `fake(MockHandler $handler)` method, mirroring Laravel's own `Http::fake()`/`Queue::fake()` naming). Confirm both against the real Phase 1 source before implementing — they affect every code snippet in this spec mechanically, not its design.
4. **`HasWorkosUser`'s exact file path is assumed** (`src/Concerns/HasWorkosUser.php`, namespace `Authkit\Authkit\Concerns\HasWorkosUser`) since Phase 2 has not yet landed a spec or implementation. The `workos_id` column/property name is treated as contract-fixed (named explicitly in both the contract's scope table and the context brief); the file's location is not, and is a one-line fix if Phase 2 places it elsewhere.
5. **No `connectedAccount()` singular getter and no `updateUserConnectedAccount()` wrapper** were added even though both exist on the SDK. See Section 2's "explicitly out of scope" table for the reasoning — this is scope discipline against the over-engineering critic, not an oversight.

## 10. Validation Commands

```bash
composer analyse          # PHPStan (larastan)
composer lint:check       # Pint check-only
composer test:types       # Pest type coverage --min=100
vendor/bin/pest --filter=Pipes   # area suite (seconds; MockHandler only, no emulate boot)
composer test             # full chain — must be green before commit
```

## Open Items

1. Confirm `WorkosClientManager`'s SDK-instance accessor method name once Phase 1 lands (assumed `client(): \WorkOS\WorkOS` throughout this spec).
2. Confirm the exact MockHandler test-injection mechanism Phase 1 exposes (assumed `fake(MockHandler $handler)`); update `tests/Feature/PipesTest.php`'s `beforeEach` accordingly.
3. Confirm whether `Pipes::createUserConnectedAccount` is upsert-safe on a repeated call for the same `user_id` + `slug` + `organization_id` (no documented `Idempotency-Key` support was found in the vendored SDK, unlike `AuditLogs::createEvent`). If it is *not* upsert-safe, rotating an already-imported token has no path in this phase's scope and `Pipes::updateUserConnectedAccount` should be reconsidered for a follow-up.
4. Confirm `HasWorkosUser`'s real namespace/file path once Phase 2 lands (assumed `Authkit\Authkit\Concerns\HasWorkosUser` at `src/Concerns/HasWorkosUser.php`).
