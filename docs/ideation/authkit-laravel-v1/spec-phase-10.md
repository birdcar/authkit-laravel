# Phase 10: Connect & MCP Auth

**Follow `docs/ideation/authkit-laravel-v1/spec-template-feature-area.md`; inputs below.** This is a delta spec — it does not repeat the template's Shared Technical Approach, Shared Conventions, Test-Path Selection, Shared Feedback Strategy, Standard Validation Commands, or Shared Failure-Mode Prompts. Read the template fully first.

## 1. Phase Header

- **Phase**: 10 of 13 — Connect & MCP Auth
- **Estimated effort**: **M** (Medium) — one scope row, no new local projections/migrations, but four real components spanning a new inbound security boundary (MCP bearer middleware), a new external dev dependency (`laravel/mcp`), and crypto-fixture-backed tests.
- **Prereq phases**: Auth Core & Sealed Sessions (Phase 2), Organizations & Org Context (Phase 3).
- **Risk**: medium (per contract). **Blocking**: no.

## 2. Scope Rows Implemented

Verbatim from the approved contract (`scope.mvp`), single row:

> **Connect + MCP**: facade for OAuth/M2M application registry; MCP bearer-token middleware (aud = resource indicator, JWKS-verified) + `/.well-known/oauth-protected-resource` route + laravel/mcp integration recipe (laravel/mcp pinned as workbench dev dependency at implementation time).
> **Reason**: In the founder's literal MVP list; MCP auth composes from Connect + JWKS since no SDK helper exists.

No Full-tier or Stretch items touch this phase. Every file in §5 traces back to this single scope row.

## 3. Decisions Considered and Rejected

Carried from the contract's decision log (`decisions[]`), filtered to entries that bind this phase's design, plus one new decision this phase adds.

| # | Decision (adopted) | Rejected alternative | Reason | Relevance to Phase 10 |
|---|---|---|---|---|
| D1 | RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API | Sync WorkOS roles/permissions into local spatie-style tables | Claims already ride the access token; local tables duplicate canonical state and drift | The MCP middleware follows the same pattern: it attaches decoded token claims to the request and does **zero** additional HTTP calls per request for the happy path. Local-user resolution is a local DB lookup by `sub`, never a WorkOS API call. |
| D2 | Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state | Exchange code then hydrate Laravel's standard session guard | WorkOS must remain the session source of truth | **Contrast, not reuse**: MCP bearer tokens are a *different* auth surface — separately-issued OAuth/M2M access tokens (RFC 8707 resource-indicator-scoped), not the sealed session cookie. `authkit.mcp` does not touch the `workos` guard, the session cookie, or `SessionManager::authenticate()`. Do not conflate the two. |
| D3 | Truth bar: emulate-backed Pest tests where covered, Guzzle MockHandler where not — explicitly listing Connect/MCP as MockHandler-only | SDK fakes only | emulate has zero Connect coverage | Directly sets this phase's test path (§7). |
| D4 | Local Eloquent rows are declared projections only (user, org, domains, memberships) + events cursor | Read-through API calls per request / arbitrary new tables | WorkOS best practice + Laravel ecosystem expectations | Connect & MCP introduce **no new migrations and no new projections**. Application registry and client secrets are never persisted locally. |
| D5 | Full org context in v1: claims-resolved current org, org-switch route, tenant middleware | Read-only org context | Multi-org ergonomics are table stakes | This is *why* Phase 10 depends on Phase 3: `createM2MApplication` needs a resolved `organizationId` before it can be called. |
| D6 | **API Keys Guard and Connect & MCP phases depend on Organizations & Org Context** | Original prereq graph (auth-core only) | Hidden-dependency blocker: `ApiKeys::createOrganizationApiKey` and `Connect::createM2MApplication` both take a required `organizationId` at the SDK-signature level | This is the literal prereq justification for this phase. `Service\Connect::createM2MApplication(string $name, string $organizationId, ...)` has `organizationId` as a required (non-nullable) positional parameter — confirmed by reading the vendored SDK source. |
| D7 | Phase 1 empirical AuthKit token audit confirms canonical `iss`/`aud` values before Phase 2 starts | Assume the SDK's TODO values | `SessionManager::decodeAccessToken()` explicitly defers `iss`/`aud` verification (see source comment at the end of that method) | MCP tokens need their **own** `iss`/`aud` policy — `aud` is the configured resource indicator (or the environment's `client_id` when no Resource Indicator is configured — see Finding F2), not the same `aud` a regular AuthKit session token carries. Do not assume Phase 1's audited session-token `aud` value applies here. |
| D8 | Credentials read from config only; `env()` never appears in `src/` | Runtime `env()` reads | `config:cache` empties env at runtime | The new `authkit_domain` and `mcp.*` config keys follow the same rule — `env()` calls live only in `config/authkit.php`. |
| **D9 (new, this phase)** | MCP bearer verification stays local (JWKS signature check), never calls WorkOS's introspection endpoint on the request hot path | Verify every MCP request via `POST /oauth2/introspection` instead of/alongside local JWKS verification | Same zero-HTTP-per-check doctrine as D1; introspection adds a network round-trip (and a WorkOS outage dependency) to every tool call. An opt-in introspection helper for revocation-sensitive flows the *consuming app* might want to build (e.g., "was this specific token revoked before I let it delete something") is a real future capability, but it is **not in scope for Phase 10** — it is absent from `scope.mvp`/`scope.full`/`scope.stretch` and from `execution.phases` notes for Connect & MCP Auth in the contract, and would need an explicit contract amendment before any phase implements it (see §6.2). | New design decision this phase must make explicit: the middleware must never call introspection on the hot path, independent of whether/when a future phase ever adds the opt-in helper. |

## 4. Assumed Interfaces From Earlier Phases

This spec is standalone-implementable, but one component genuinely needs to reuse machinery that Phases 1–2 own. Because those phases' specs are written in parallel with this one, the exact class/method name is **assumed** here and must be reconciled at integration (Phase 13) if it differs. It is also listed in §11 Open Items.

1. **Phase 2 JWKS verifier** (do not duplicate its caching — this is the explicit instruction from the phase brief):
   ```php
   namespace Authkit\Authkit\Support\Jwt;

   final class JwksVerifier
   {
       /**
        * Fetch (Laravel-cache-backed, TTL default 300s, force-refresh once on
        * an unrecognized `kid`) the JWKS at $jwksUrl, verify $jwt's RS256
        * signature against it, and check `exp`. Does NOT check `iss`/`aud` —
        * every caller has a different audience/issuer policy, so that check
        * stays with the caller.
        *
        * @return array<string, mixed> decoded claims
        * @throws \Authkit\Authkit\Support\Jwt\Exceptions\JwtVerificationException
        */
       public function verify(string $jwt, string $jwksUrl, string $cacheKey, int $ttlSeconds = 300): array;
   }
   ```
   **Why this must be generalized by URL, not hardcoded to the SSO path**: the session JWKS lives at `{base_url}/sso/jwks/{client_id}` (what `SessionManager::getJwksUrl()` builds), but the MCP resource-server JWKS lives at a **different** URL entirely: `https://{authkit_domain}/oauth2/jwks` (see Finding F1). A verifier hardcoded to the SSO path cannot be reused here — genuine reuse requires the JWKS URL to be a parameter. If Phase 2 ships something narrower (e.g. hardcoded to the SSO JWKS), Phase 10 cannot reuse it as-is and this Open Item escalates to a real blocker, not just a naming mismatch.
   The debounced-force-refresh-on-unknown-`kid` behavior (protecting against a `kid`-miss cache-stampede, see §8 F9) is expected to live inside this shared component; Phase 10 inherits that protection for free by reusing it rather than reimplementing JWKS fetching.

If this assumed signature differs once Phase 2 lands, the fix is localized: adjust the call site in `src/Http/Middleware/AuthenticateMcpToken.php` (§6.3) — nothing else in this phase depends on the exact shape.

## 5. Findings Recorded at Spec-Writing Time

The phase brief asked several things to be "verified at implementation" or "recorded." They were verified now (via live WorkOS docs and Packagist) so implementation doesn't have to re-derive them:

- **F1 — Two different JWKS endpoints exist.** Session tokens (Phase 2) verify against `{base_url}/sso/jwks/{client_id}` (`SessionManager::getJwksUrl()`). MCP resource-server tokens verify against `https://{authkit_domain}/oauth2/jwks` — a different host/path, unrelated to the API base URL or client ID. Any "reuse the JWKS component" design must parameterize the URL (see §4.1).
- **F2 — `/.well-known/oauth-protected-resource` required fields, confirmed against WorkOS's AuthKit-for-MCP guide.** Exactly three fields in WorkOS's own example:
  ```json
  {
    "resource": "https://mcp.example.com",
    "authorization_servers": ["https://authkit_domain"],
    "bearer_methods_supported": ["header"]
  }
  ```
  `scopes_supported` is **not** in WorkOS's documented example (RFC 9728 lists it as optional). This phase includes it only when `authkit.mcp.scopes` is configured (see §11 Open Item 4 — the phase brief's wording listed "scopes" among the fields to verify, so it is supported but optional, matching what WorkOS actually documents).
- **F3 — `aud` claim semantics, confirmed.** "Access tokens will be issued with an `aud` claim that matches the requested `resource`. If no Resource Indicators are configured, it defaults to your WorkOS Environment's client ID." The Resource Indicator itself is configured in the WorkOS Dashboard under *Connect → Configuration*, not via this package — the package only needs to know the value (`authkit.mcp.resource_indicator`) to check `aud` against.
- **F4 — Token verification, confirmed.** WorkOS's guide states verification requires checking issuer (`https://authkit_domain`), audience (the resource indicator), and signature against the JWKS at `https://authkit_domain/oauth2/jwks`. It also explicitly calls out: "Include a `WWW-Authenticate` header with `resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"` in 401 responses to enable client discovery." This is now a hard requirement in §6.3, not a nice-to-have.
- **F5, F6 — removed.** Both were introspection-endpoint findings (`POST /oauth2/introspection` wire shape; `WorkOS\WorkOS` exposing no `HttpClient` accessor) supporting a design that is out of scope for this phase — see §6.2.
- **F7 — `laravel/mcp` current published constraint** (Packagist, checked 2026-08-06): latest stable `v0.9.1`, requires `php: ^8.2` and `illuminate/*: ^11.45.3|^12.41.1|^13.0` — compatible with this package's `php ^8.3` / Laravel `^12.0||^13.0` floor. Pin as `"laravel/mcp": "^0.9.1"`.
- **F8 — `laravel/mcp` registration API, confirmed against Laravel's docs.** Servers are generated with `php artisan make:mcp-server {Name}` (extends `Laravel\Mcp\Server`), registered in `routes/ai.php` via `Mcp::web($path, ServerClass::class)`, and secured with `->middleware([...])` exactly like a normal route — confirming `->middleware(['authkit.mcp'])` is the correct integration point and needs no special-casing on this package's side.
- **F9 — `Agents::createValidate()` is not a substitute for this middleware.** Its docblock ("Validate an agent credential ... against the environment of the API key used to authenticate the request") scopes it to WorkOS's *Agents* product (agent identity credentials), a different feature surface from generic MCP resource-server bearer tokens, and it costs a network round-trip per call. Rejected as an alternative — see D9.

## 6. Components

### 6.1 Connect Application & Client Secret Manager (iterative — feedback loop required)

**Laravel mechanism**: method on the `Authkit` manager (`Authkit::connect()`), per Shared Conventions ("other areas hang off `Authkit` accessors"). No dedicated facade.

**SDK methods wrapped** (exact names, from `vendor/workos/workos-php/lib/Service/Connect.php`): `listApplications`, `createOAuthApplication`, `createM2MApplication`, `getApplication`, `updateApplication`, `deleteApplication`, `listApplicationClientSecrets`, `createApplicationClientSecret`, `deleteClientSecret`. `completeOAuth2` (Standalone Connect) is **not** wrapped — it belongs to a different flow (bridging an app's own auth system into AuthKit) that no MVP scope row requests; out of scope for this phase.

**Key design** — two boundary problems the SDK signatures create that a naive 1:1 wrapper would get wrong:

1. **Input-side SDK leakage.** `createOAuthApplication`/`updateApplication` take `?array $redirectUris` typed as `array<\WorkOS\Resource\RedirectUriInput>`, and `listApplications` takes a `\WorkOS\Resource\PaginationOrder` enum and `array<\WorkOS\Resource\ApplicationsRegistrationTypes>`. If `ConnectManager`'s own signatures echoed these types, any workbench code passing a redirect URI or a list order would need `use WorkOS\Resource\...` — breaking the "consumer never touches the SDK" doctrine at the *input* boundary, not just the output. `ConnectManager` accepts plain scalars/strings and translates internally.
2. **Output-side SDK leakage.** `createOAuthApplication`/`createM2MApplication`/`getApplication`/`updateApplication` all return the generic `\WorkOS\Resource\ConnectApplication` (confirmed — not the narrower `ConnectApplicationOAuth`/`ConnectApplicationM2M` subtypes, which are declared in the SDK's `Resource/` directory but never actually returned by `Service\Connect`). `ConnectManager` maps this to a package-owned DTO (`Connect\Data\ConnectApplication`, §6.6).

```php
namespace Authkit\Authkit\Connect;

final readonly class ConnectManager
{
    public function __construct(
        private \WorkOS\WorkOS $client,
    ) {}

    /** @param array<int, string>|null $scopes */
    public function createOAuthApplication(
        string $name,
        bool $isFirstParty,
        ?string $description = null,
        ?array $scopes = null,
        ?array $redirectUris = null,   // list of plain URI strings, not RedirectUriInput
        ?bool $usesPkce = null,
        ?string $organizationId = null,
        ?string $idempotencyKey = null,
    ): Data\ConnectApplication;

    /** @param array<int, string>|null $scopes */
    public function createM2MApplication(
        string $name,
        string $organizationId,   // required — SDK-level dependency; blank string rejected before the wire, see §8 F11
        ?string $description = null,
        ?array $scopes = null,
        ?string $idempotencyKey = null,
    ): Data\ConnectApplication;

    /** @return \Illuminate\Support\Collection<int, Data\ConnectApplication> */
    public function listApplications(
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        string $order = 'desc',                    // 'asc'|'desc'|'normal' — translated to PaginationOrder internally
        ?array $registrationTypes = null,           // array<'dynamic'|'authenticated'> — translated internally
        ?string $organizationId = null,
    ): \Illuminate\Support\Collection;

    public function getApplication(string $id): Data\ConnectApplication;

    /** @param array<int, string>|null $scopes */
    public function updateApplication(
        string $id,
        ?string $name = null,
        ?string $description = null,
        ?array $scopes = null,
        ?array $redirectUris = null,
    ): Data\ConnectApplication;

    public function deleteApplication(string $id): void;

    /** @return \Illuminate\Support\Collection<int, Data\ConnectApplicationSecret> */
    public function listClientSecrets(string $applicationId): \Illuminate\Support\Collection;

    public function createClientSecret(string $applicationId): Data\NewConnectApplicationSecret;

    public function deleteClientSecret(string $secretId): void;

    /**
     * Creates a new secret FIRST, then deletes $secretIdToRevoke — in that
     * order, never reversed. See §8 F12 for why the ordering is load-bearing.
     */
    public function rotateClientSecret(string $applicationId, string $secretIdToRevoke): Data\NewConnectApplicationSecret;
}
```

**Implementation steps**:
1. `php artisan make:class Connect/ConnectManager --no-interaction` is not a real Laravel generator (no `make:class` exists) — there is no applicable `make:` generator for a plain PHP class; write `src/Connect/ConnectManager.php` directly. (No migrations — this component introduces no local projection, per D4.)
2. Write `Data\ConnectApplication`, `Data\ConnectApplicationSecret`, `Data\NewConnectApplicationSecret` DTOs (§6.6, trivial, no feedback loop).
3. Write `Connect\Exceptions\ConnectException` (§6.6) with named static constructors: `::organizationIdRequired()`, `::operationFailed(\Throwable $previous)`.
4. Implement `ConnectManager`, translating every enum/value-object boundary per the Key Design notes above.
5. Bind in the service provider (§6b) and add `Authkit::connect(): ConnectManager` accessor to `src/Authkit.php`.
6. Write `tests/Feature/ConnectTest.php` against MockHandler (§7).

**Feedback loop**:
- **Playground**: Pest feature suite against `GuzzleHttp\Handler\MockHandler` (per template Test-Path Selection).
- **Parameterized experiment**: `vendor/bin/pest --filter=ConnectTest` with a dataset varying `{createOAuthApplication, createM2MApplication} × {organizationId present, organizationId blank}` to pin down the fail-fast boundary from F11, and a second dataset varying `{rotate succeeds, rotate's delete-step fails}` to pin down the ordering guarantee from F12.
- **Check command**: `vendor/bin/pest --filter=ConnectTest` (seconds).

### 6.2 Connect Token Introspection — removed from this phase

An earlier draft of this spec included `IntrospectionClient`, `ConnectManager::introspect()`, the `Connect\Data\ConnectTokenIntrospection` DTO, a Guzzle-backed transport wrapping WorkOS's `POST /oauth2/introspection` endpoint, and a direct `composer.json` `require` addition of `guzzlehttp/guzzle` to support it. None of that is part of Phase 10.

Introspection is not in the contract's `scope.mvp`/`scope.full`/`scope.stretch` rows for Connect & MCP Auth, nor in `execution.phases`' notes for this phase (see §2) — this delta's scope row is "facade for OAuth/M2M application registry; MCP bearer-token middleware ...; `/.well-known/oauth-protected-resource` route; laravel/mcp integration recipe," with no mention of introspection. It is also not the mechanism WorkOS's own AuthKit-for-MCP guide documents or recommends for verifying MCP bearer tokens — that guide's example verifies locally against JWKS (`jwtVerify`), matching D9 and this phase's `authkit.mcp` middleware (§6.3), not introspection.

**This subsystem is deferred to a future phase, pending an explicit `contract-data.json` scope amendment adding it to Connect & MCP Auth (or a later phase).** Do not implement `IntrospectionClient`, `ConnectManager::introspect()`, `Connect\Data\ConnectTokenIntrospection`, or their tests as part of this delta, and do not add `guzzlehttp/guzzle` to `composer.json` `require` for this reason.

### 6.3 MCP Bearer Authentication Middleware — `authkit.mcp` (iterative — feedback loop required; highest-risk component in this phase)

**Laravel mechanism**: route middleware, registered under the alias `authkit.mcp` (name reserved for this phase by the template's Shared Conventions table).

**SDK methods wrapped**: none directly — composes the Phase 2 `JwksVerifier` (§4.1) with this phase's own `iss`/`aud` policy. No WorkOS API call on the request path (D9).

**Key design**:
```php
namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Http\Middleware\Exceptions\InvalidMcpTokenException;
use Authkit\Authkit\Support\Jwt\JwksVerifier; // Phase 2 — assumed interface, §4.1
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateMcpToken
{
    public function __construct(private JwksVerifier $jwksVerifier) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $domain = config('authkit.authkit_domain');
        $resourceIndicator = config('authkit.mcp.resource_indicator');
        if (blank($domain) || blank($resourceIndicator)) {
            // Fail fast and loud — a misconfigured MCP guard must not silently
            // reject every request forever. See §8 F10.
            throw new \Authkit\Authkit\Exceptions\ConfigurationException(
                'authkit.authkit_domain and authkit.mcp.resource_indicator must both be configured to use the authkit.mcp middleware.'
            );
        }

        $token = $this->extractBearerToken($request->header('Authorization'));
        if ($token === null) {
            return $this->unauthorized(withErrorParam: false); // RFC 6750: no `error=` when no token was attempted
        }

        try {
            $claims = $this->jwksVerifier->verify(
                jwt: $token,
                jwksUrl: "https://{$domain}/oauth2/jwks", // NOT the session JWKS path — see F1
                cacheKey: "authkit:jwks:mcp:{$domain}",
            );

            $expectedIss = "https://{$domain}";
            if (($claims['iss'] ?? null) !== $expectedIss) {
                throw InvalidMcpTokenException::wrongIssuer();
            }
            if (($claims['aud'] ?? null) !== $resourceIndicator) {
                throw InvalidMcpTokenException::wrongAudience();
            }
        } catch (InvalidMcpTokenException) {
            return $this->unauthorized(withErrorParam: true);
        } catch (\Authkit\Authkit\Support\Jwt\Exceptions\JwtVerificationException) {
            return $this->unauthorized(withErrorParam: true);
        }

        $request->attributes->set('authkit.mcp.claims', $claims);

        if ((bool) config('authkit.mcp.resolve_user', false)) {
            $this->resolveLocalUser($request, $claims);
        }

        return $next($request);
    }

    private function extractBearerToken(?string $header): ?string { /* "Bearer <jwt>", case-insensitive scheme, else null */ }

    private function resolveLocalUser(Request $request, array $claims): void
    {
        $sub = $claims['sub'] ?? null;
        if (! is_string($sub)) {
            return; // M2M client-credentials tokens carry no `sub` — expected, not a failure (§8 F15)
        }
        $userModel = config('authkit.mcp.user_model') ?? config('auth.providers.users.model'); // authkit.mcp.user_model declared in §8
        $user = $userModel::query()->where('workos_id', $sub)->first(); // Phase 2 projection column
        if ($user !== null) {
            $request->setUserResolver(static fn () => $user);
        }
        // No match: not a failure — request proceeds with claims attached, no local user. §8 F15.
    }

    private function unauthorized(bool $withErrorParam): Response
    {
        $metadataUrl = url('/.well-known/oauth-protected-resource');
        $challenge = $withErrorParam
            ? sprintf('Bearer error="invalid_token", error_description="The access token is invalid, expired, or was issued for a different resource.", resource_metadata="%s"', $metadataUrl)
            : sprintf('Bearer resource_metadata="%s"', $metadataUrl);

        return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', $challenge);
    }
}
```

Note the explicit **allow-list on `alg`** happens inside the shared `JwksVerifier` (Phase 2), not duplicated here — another payoff of D9/§4.1's reuse mandate: a forged token with `alg: none` or `alg: HS256` never reaches this middleware's `iss`/`aud` checks at all, because `JwksVerifier::verify()` throws before returning any claims.

**Implementation steps**:
1. `cd workbench && php artisan make:middleware AuthenticateMcpToken` to scaffold (generators default to `app/Http/Middleware`), then move the generated file to `src/Http/Middleware/AuthenticateMcpToken.php` and change the namespace to `Authkit\Authkit\Http\Middleware`.
2. Write `Http\Middleware\Exceptions\InvalidMcpTokenException` (trivial, internal-only — never crosses into consumer code, so it does not need to avoid referencing SDK types).
3. Implement the `handle()` method per the design above.
4. Register the alias in `AuthkitServiceProvider::boot()` (§6b).
5. Write `tests/Fixtures/Jwks/SelfSignedJwksFixture.php` (§7) and `tests/Feature/McpAuthenticationTest.php`.

**Feedback loop**:
- **Playground**: `composer serve` (Testbench workbench) with a throwaway guarded route, `curl`'d with locally-signed tokens from the fixture; plus the Pest suite.
- **Parameterized experiment**: `vendor/bin/pest --filter=McpAuthenticationTest` with a dataset varying `{valid, expired, wrong-iss, wrong-aud, unknown-kid, forged-alg-none, forged-alg-HS256, malformed-not-3-segments, missing-header}`.
- **Check command**: `vendor/bin/pest --filter=McpAuthenticationTest` (seconds — no network I/O; the JWKS fetch is MockHandler-served).

### 6.4 OAuth Protected Resource Metadata Route (iterative — feedback loop required)

**Laravel mechanism**: a registered route, `GET /.well-known/oauth-protected-resource`, backed by an invokable controller (RFC 9728's well-known path is fixed by spec — not configurable).

**SDK methods wrapped**: none — pure config-to-JSON rendering.

**Key design**:
```php
namespace Authkit\Authkit\Http\Controllers;

final class OAuthProtectedResourceMetadataController
{
    public function __invoke(): \Illuminate\Http\JsonResponse
    {
        $domain = config('authkit.authkit_domain');
        $resource = config('authkit.mcp.resource_indicator');

        // Unconfigured: 404, not 500 — a default install that never touches
        // MCP must not expose a broken well-known endpoint. See §8 F10 for
        // why this is the opposite fail-fast choice from the middleware.
        if (blank($domain) || blank($resource)) {
            abort(404);
        }

        $scopes = config('authkit.mcp.scopes');

        return response()->json(array_filter([
            'resource' => $resource,
            'authorization_servers' => ["https://{$domain}"],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => is_array($scopes) && $scopes !== [] ? $scopes : null,
        ], static fn ($v) => $v !== null));
    }
}
```
Field shape matches F2 exactly (`resource`, `authorization_servers`, `bearer_methods_supported` always present when configured; `scopes_supported` only when `authkit.mcp.scopes` is set).

**Implementation steps**:
1. `cd workbench && php artisan make:controller OAuthProtectedResourceMetadataController --invokable`, then move to `src/Http/Controllers/` and fix the namespace.
2. Add the route registration to `routes/authkit-laravel.php` (§6b).
3. Write `tests/Feature/OAuthProtectedResourceMetadataTest.php`.

**Feedback loop**:
- **Playground**: `composer serve` + `curl http://localhost:8000/.well-known/oauth-protected-resource`.
- **Parameterized experiment**: dataset varying `{configured-with-scopes, configured-without-scopes, unconfigured}`.
- **Check command**: `vendor/bin/pest --filter=OAuthProtectedResourceMetadataTest`.

### 6.5 `laravel/mcp` Workbench Integration Recipe (iterative — feedback loop required, but no new Pest suite — see below)

**Laravel mechanism**: a dev-only workbench example wiring `authkit.mcp` onto a real `laravel/mcp` server, proving the two packages compose. Not shipped code — a documented recipe plus a working workbench fixture.

**SDK methods wrapped**: none.

**Key design** (per F7/F8):
```php
// workbench/routes/ai.php
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Mcp\Servers\DemoServer;

Mcp::web('/mcp/demo', DemoServer::class)->middleware(['authkit.mcp']);
```
```php
// workbench/app/Mcp/Servers/DemoServer.php (generated by `php artisan make:mcp-server DemoServer`)
namespace Workbench\App\Mcp\Servers;

use Laravel\Mcp\Server;

class DemoServer extends Server
{
    protected array $tools = [];
    protected array $resources = [];
    protected array $prompts = [];
}
```

**Implementation steps**:
1. `composer require --dev laravel/mcp:^0.9.1` (root `composer.json`; workbench has no separate `composer.json` — Testbench's `workbench:build` assembles the skeleton from this package's own `require`/`require-dev`, confirmed by reading `testbench.yaml`).
2. `cd workbench && php artisan vendor:publish --tag=ai-routes` to create `workbench/routes/ai.php` (per F8).
3. `cd workbench && php artisan make:mcp-server DemoServer`.
4. Register `Mcp::web('/mcp/demo', DemoServer::class)->middleware(['authkit.mcp'])` in `workbench/routes/ai.php`.
5. **Verify route wiring**: confirmed by reading `vendor/orchestra/testbench-core`'s `Workbench.php::discoverRoutes()` — its `discoversConfig` only recognizes `'web'` and `'api'` keys, with no built-in `'ai'` key, so `workbench/routes/ai.php` is **not** auto-discovered and the fallback below is always needed, not conditional. Add the following to `Workbench\App\Providers\WorkbenchServiceProvider::boot()` and uncomment that provider's line in `testbench.yaml` (currently commented out — confirmed by reading the file):
   ```php
   use function Orchestra\Testbench\workbench_path;

   // ...inside boot()...
   $this->loadRoutesFrom(workbench_path('routes/ai.php'));
   ```
   Use `workbench_path()`, not `base_path()` — confirmed by reading `Workbench.php::discoverRoutes()` and `LoadConfigurationWithWorkbench.php`: the workbench app boots from a skeleton path (`default_skeleton_path()`), where `base_path()` does not resolve to this package's own `workbench/` directory. This mirrors exactly the pattern Testbench's own `discoverRoutes()` uses for `web.php`/`api.php` — both call `workbench_path('routes', ...)`, never `base_path()`.
6. Add `WORKOS_AUTHKIT_DOMAIN`, `WORKOS_MCP_RESOURCE_INDICATOR` placeholders to `workbench/.env.example`.

**Feedback loop**:
- **Playground**: `composer serve` + `curl -X POST http://localhost:8000/mcp/demo` (with and without a valid `Authorization: Bearer` header from the test fixture).
- **Parameterized experiment**: vary presence/absence/validity of the bearer token against the live workbench route.
- **Check command**: manual — the two curl invocations above (401 without token, a JSON-RPC response with a valid one). **No new automated Pest suite for this component**: `McpAuthenticationTest` (§6.3) already proves `authkit.mcp` in isolation against a throwaway test route; this recipe's only remaining job is proving real composition with `laravel/mcp`'s own routing, which is `laravel/mcp`'s wire format, not this package's contract to test in CI. Recorded explicitly rather than silently skipped, per this delta's obligation not to invent a redundant suite that would really just be re-testing an upstream package.

### 6.6 Trivial Components — DTOs and Exceptions (feedback loop explicitly skipped)

Per the template: "trivial components (config keys, enums, DTOs) explicitly skip." These are typed data carriers with no branching logic worth a dedicated experiment; they are exercised incidentally by every test in §7 that touches `ConnectManager`.

- `Connect\Data\ConnectApplication` — mirrors `\WorkOS\Resource\ConnectApplication` 1:1 (`fromSdk()` factory), so `ConnectManager` never returns an SDK type.
- `Connect\Data\ConnectApplicationSecret` — mirrors `\WorkOS\Resource\ApplicationCredentialsListItem`.
- `Connect\Data\NewConnectApplicationSecret` — mirrors `\WorkOS\Resource\NewConnectApplicationSecret` (includes the plaintext `secret`, returned once at creation — never re-fetchable, matching the SDK's own resource docblock).
- `Connect\Exceptions\ConnectException` — one class, named static constructors (`::organizationIdRequired()`, `::operationFailed(\Throwable $previous)`), wraps SDK/Guzzle exceptions so neither ever needs to be caught by name in workbench code.
- `Http\Middleware\Exceptions\InvalidMcpTokenException` — internal-only (caught inside `AuthenticateMcpToken::handle()`, never escapes the middleware).

## 7. File Changes

Every path below traces to the single scope row in §2.

### New files

| Path | Purpose |
|---|---|
| `src/Connect/ConnectManager.php` | §6.1 |
| `src/Connect/Data/ConnectApplication.php` | §6.6 |
| `src/Connect/Data/ConnectApplicationSecret.php` | §6.6 |
| `src/Connect/Data/NewConnectApplicationSecret.php` | §6.6 |
| `src/Connect/Exceptions/ConnectException.php` | §6.6 |
| `src/Http/Middleware/AuthenticateMcpToken.php` | §6.3 |
| `src/Http/Middleware/Exceptions/InvalidMcpTokenException.php` | §6.6 |
| `src/Http/Controllers/OAuthProtectedResourceMetadataController.php` | §6.4 |
| `tests/Feature/ConnectTest.php` | §6.1 tests |
| `tests/Feature/McpAuthenticationTest.php` | §6.3 tests |
| `tests/Feature/OAuthProtectedResourceMetadataTest.php` | §6.4 tests |
| `tests/Fixtures/Jwks/SelfSignedJwksFixture.php` | shared crypto fixture for §6.3 tests — see note below |
| `workbench/app/Mcp/Servers/DemoServer.php` | §6.5 |
| `workbench/routes/ai.php` | §6.5 |

> **Note on `SelfSignedJwksFixture.php`**: Phase 2's `SessionSecurity` suite needs the identical capability (self-signed RSA keypair → JWK → signed test tokens) per the phase brief's explicit instruction to use "the same fixture approach." Both phases genuinely need this helper; whichever phase lands first should create it at this exact path, and the other should reuse it rather than duplicating. If Phase 2 lands first with a different path/shape, adjust this phase's test `use` statements at integration (Phase 13) — flagged as Open Item 2.

### Modified files

| Path | Change |
|---|---|
| `src/Authkit.php` | Add `connect(): \Authkit\Authkit\Connect\ConnectManager` accessor method. |
| `src/AuthkitServiceProvider.php` | `register()`: bind the `ConnectManager` singleton (§6b). `boot()`: alias the `authkit.mcp` middleware (§6b). |
| `config/authkit.php` | Add `authkit_domain` key and an `mcp` block (`resource_indicator`, `resolve_user`, `scopes`) — see §6b for exact keys and env var names. |
| `routes/authkit-laravel.php` | Add the `/.well-known/oauth-protected-resource` route registration (§6.4). |
| `composer.json` | `require-dev`: add `"laravel/mcp": "^0.9.1"` (F7). No `require` addition this phase — `guzzlehttp/guzzle` stays a transitive dependency via `workos/workos-php`; nothing in this phase's shipped code instantiates `\GuzzleHttp\Client` directly (see §6.2). |
| `workbench/.env.example` | Add `WORKOS_AUTHKIT_DOMAIN=` and `WORKOS_MCP_RESOURCE_INDICATOR=` placeholder lines. |
| `testbench.yaml` | Uncomment the `Workbench\App\Providers\WorkbenchServiceProvider` line under `providers:` (currently commented out — confirmed by reading the file) so its `boot()` can `loadRoutesFrom` the `ai.php` file — confirmed needed unconditionally, not gated behind an Open Item (§6.5 step 5). |

No `database/migrations/*` files (D4 — no local projection). No new facade class (Shared Conventions restricts standalone facades to `Authkit`, `Vault`, `AuditLog`).

## 8. Service Provider Registration Diff

```php
// src/AuthkitServiceProvider.php

public function register(): void
{
    // ...existing bindings from earlier phases...

    $this->app->singleton(\Authkit\Authkit\Connect\ConnectManager::class, function ($app) {
        return new \Authkit\Authkit\Connect\ConnectManager(
            $app->make(\Authkit\Authkit\WorkosClientManager::class)->client(), // Phase 1 accessor to the SDK's \WorkOS\WorkOS instance
        );
    });
}

public function boot(): void
{
    // ...existing boot from earlier phases...

    $this->app->make(\Illuminate\Routing\Router::class)
        ->aliasMiddleware('authkit.mcp', \Authkit\Authkit\Http\Middleware\AuthenticateMcpToken::class);
}
```

**`config/authkit.php` additions**:
```php
'authkit_domain' => env('WORKOS_AUTHKIT_DOMAIN'), // bare host, e.g. "myapp.authkit.app" or a custom domain — no scheme

'mcp' => [
    'resource_indicator' => env('WORKOS_MCP_RESOURCE_INDICATOR'), // must match the Resource Indicator configured in Connect → Configuration in the WorkOS Dashboard
    'resolve_user' => env('WORKOS_MCP_RESOLVE_USER', false),
    'user_model' => null, // class-string<\Illuminate\Database\Eloquent\Model>|null — overrides auth.providers.users.model for §6.3's resolveLocalUser() only; set directly in the published config file if the MCP-resolved user model differs from the app's default auth provider model. Checked first via `config('authkit.mcp.user_model') ?? config('auth.providers.users.model')` — never a bare $default arg, since auth.providers.users.model ships non-null in every Laravel app.
    'scopes' => null, // array<string>|null — set directly in the published config file; rarely a single-value env var
],
```
New env vars (config-file-only per D8): `WORKOS_AUTHKIT_DOMAIN`, `WORKOS_MCP_RESOURCE_INDICATOR`, `WORKOS_MCP_RESOLVE_USER`. `authkit.mcp.user_model` has no env var (class-strings aren't single-value env-friendly, same rationale as `authkit.mcp.scopes`).

## 9. Failure Modes

Named failures, not "handle errors." Each row states the concrete trigger and the required behavior.

| # | Failure | Trigger | Required behavior |
|---|---|---|---|
| F-no-token | No `Authorization` header | Any request to an `authkit.mcp`-guarded route with no bearer token | 401, `WWW-Authenticate: Bearer resource_metadata="..."` **without** `error=` (RFC 6750 — no token was attempted, so `invalid_token` doesn't apply). |
| F-malformed | Malformed bearer token | Header present but not `Bearer <3-segment-JWT>` | 401 with `error="invalid_token"` variant of the challenge. |
| F-alg-confusion | Algorithm-confusion / `none`-alg attack | Forged token with `alg: none` or `alg: HS256` in the header | Rejected by the shared `JwksVerifier`'s allow-list (RS256 only) before any signature math runs — mirrors `SessionManager`'s own `ALLOWED_JWS_ALGORITHMS` pattern. 401. |
| F9-kid-stampede | `kid`-miss cache-stampede (DoS amplification) | Attacker/buggy client sends many distinct bogus `kid` values, each one bypassing the JWKS cache | Debounced force-refresh lives in the shared Phase 2 `JwksVerifier` (§4.1) — Phase 10 needs no separate mitigation, the direct payoff of reusing rather than reimplementing (D9's underlying rationale extended to this case). |
| F-expired | Expired token | Valid signature, `exp` in the past | 401, `error="invalid_token"`. |
| F-wrong-iss | Cross-environment token replay | Token signed by a different AuthKit environment/domain (e.g. staging token presented to a production-configured app) | `iss` check rejects it — 401, `error="invalid_token"`. Concrete misconfiguration this catches: `WORKOS_AUTHKIT_DOMAIN` pointed at the wrong environment. |
| F-wrong-aud | **Audience confusion / cross-resource token replay** (the central security property Resource Indicators exist to provide) | A legitimate AuthKit session token, or a token minted for a *different* MCP resource, is replayed against this endpoint | `aud` check rejects it — 401, `error="invalid_token"`. This is the failure mode the entire RFC 8707 resource-indicator design exists to prevent; get this check wrong and any AuthKit-authenticated bearer token becomes valid against every MCP server sharing the environment. |
| F-jwks-down | WorkOS JWKS endpoint unreachable/5xx, cold cache | `https://{authkit_domain}/oauth2/jwks` times out or 5xxs and no cached copy exists within TTL | **503, not 401** — this is our infrastructure failing to verify, not the caller presenting bad credentials; conflating the two hides a WorkOS outage behind what looks like every client having a bad token. Warm cache within TTL continues serving through a brief WorkOS outage (bounded staleness, acceptable per the project's stated doctrine). |
| F10-config-missing | Missing config | `authkit.authkit_domain` or `authkit.mcp.resource_indicator` blank | **Middleware**: fail fast with an actionable exception naming the missing key, thrown on first use — never a silent infinite-401 loop or a raw WorkOS error surfacing three calls deep. **Well-known route**: the opposite choice, a soft 404 — a default install that never touches MCP must not expose a route that 500s. Both documented explicitly so this asymmetry reads as a decision, not an inconsistency. |
| F11-org-required | `createM2MApplication` called with a blank `organizationId` | Org context (Phase 3) hasn't resolved yet — e.g. a queued job or console command with no active org, coerced to `''` | `ConnectManager` validates non-blank *before* the wire and throws `ConnectException::organizationIdRequired()` with an actionable message, rather than letting WorkOS's own 400 surface without that context. |
| F12-rotation-race | Secret-rotation ordering race | `rotateClientSecret()` | **Create-then-delete, never delete-then-create** — the new secret must exist before the old one is revoked, or in-flight OAuth token exchanges using the old secret break mid-rotation. If the delete step then fails (WorkOS 5xx), the operation does **not** roll back the just-created secret — retrying the *whole* `rotateClientSecret()` call would mint a third, unnecessary secret. Callers should retry only `deleteClientSecret($secretIdToRevoke)` on that failure path. |
| F13-create-retry-dup | Duplicate application creation on retry | A naive automatic retry of `createOAuthApplication`/`createM2MApplication` after a client-side timeout (before a response was received) | WorkOS's `Idempotency-Key` support for these specific endpoints is **unconfirmed** (context brief only confirms it for `AuditLogs::createEvent`, 24h window) — `ConnectManager` exposes an `?string $idempotencyKey` passthrough regardless, but callers should not assume it deduplicates until verified against WorkOS support/docs (Open Item 3). The SDK's own transport-level retry (429/5xx) is unaffected either way — this failure is about *caller*-initiated retries after ambiguous outcomes, not the SDK's built-in retry. |
| F15-no-local-user | `sub` present but no local user row matches it | A user-delegated MCP token for a WorkOS user who hasn't completed Phase 2's first-login link yet (`workos_id` never populated), or belongs to a different environment | **Not a failure.** The request proceeds with `authkit.mcp.claims` attached; `$request->user()` simply stays unresolved. Documented explicitly so this isn't mistaken for a bug during implementation. |
| F-m2m-no-sub | `sub` absent entirely | M2M (client-credentials) tokens carry no `sub` by design | **Not a failure.** `resolveLocalUser()` returns immediately; the request proceeds. |

## 10. Deviations From the Template

1. **`ConnectManager`'s public method signatures deliberately narrow several SDK enum/value-object parameters to plain strings** (`$order`, `$registrationTypes`, `$redirectUris`) that the underlying `Service\Connect` methods type as `\WorkOS\Resource\PaginationOrder`, `array<\WorkOS\Resource\ApplicationsRegistrationTypes>`, and `array<\WorkOS\Resource\RedirectUriInput>` respectively. This is required by the "consumer never touches the SDK" doctrine at the *input* boundary (§6.1 Key Design point 1) — not a deviation from doctrine, but flagged here because it is not the "wrap the return type" pattern most other components use, and a reviewer skimming only the DTOs in §6.6 could miss that the input side needed the same treatment.
2. **`/.well-known/oauth-protected-resource` and `authkit.mcp` disagree on fail-fast policy for the same missing config** (F10) — the middleware throws, the route soft-404s. This is a deliberate, documented split (see F10's rationale), not an oversight.

Everything else in this phase follows the template's Shared Technical Approach, Conventions, and Test-Path Selection without modification.

## 11. Testing Requirements

Test path per template + D3: **MockHandler-backed** for `ConnectManager` (emulate has zero Connect coverage); **MockHandler + local self-signed JWKS fixtures** for the MCP middleware (same combination Phase 2's `SessionSecurity` suite uses — MockHandler serves the fixture's JWKS document over the fake wire, the fixture's RSA keypair signs test tokens locally, no wire call needed to *produce* a token). Tag all suites in this phase `->group('connect-mcp')` for the scoped inner loop.

- **`tests/Feature/ConnectTest.php`** (MockHandler): create OAuth app (assert request body shape); create M2M app (assert `organization_id` present in body); `createM2MApplication('', ...)` throws `ConnectException` with **zero** requests reaching the mock handler (assert via the handler's request history being empty); list/get/update/delete application; list/create/delete client secret; `rotateClientSecret` issues the create request before the delete request (assert via mock handler's recorded request order); `rotateClientSecret` where the delete step 5xxs leaves the new secret in place (assert the exception surfaces and no compensating delete of the *new* secret was attempted); `$idempotencyKey` passthrough forwards an `Idempotency-Key` header.
- **`tests/Feature/McpAuthenticationTest.php`** (MockHandler + `SelfSignedJwksFixture`): valid token → 200, next middleware/controller reached, `authkit.mcp.claims` attribute populated; missing header → 401 + `WWW-Authenticate: Bearer resource_metadata="..."` **without** `error=`; malformed bearer (not 3 segments) → 401 + `error="invalid_token"`; `alg: none` forged token → 401, JWKS endpoint never even hit (assert zero mock-handler requests for this case since the allow-list check happens before signature verification); `alg: HS256` forged token → 401; unknown `kid` → JWKS endpoint hit twice (cached-miss, then forced refresh) before 401; expired token (valid signature) → 401; wrong `iss` → 401; wrong `aud` (both "session-token-shaped aud" and "different-resource aud" cases) → 401; `sub` present + matching local user + `resolve_user=true` → `$request->user()` resolves to that user; `sub` present + no matching row + `resolve_user=true` → 200, `$request->user()` null; `sub` absent (M2M) + `resolve_user=true` → 200, no crash; `resolve_user=false` (default) → no DB query issued at all (assert via query count or a query-log assertion) even when `sub` is present; blank `authkit.mcp.resource_indicator` → the configuration exception is thrown.
- **`tests/Feature/OAuthProtectedResourceMetadataTest.php`**: configured, no scopes → exactly the three F2 fields, no `scopes_supported` key at all (not `null` — absent); configured with `authkit.mcp.scopes` set → `scopes_supported` present and matches; unconfigured (blank resource indicator) → 404.
- **Seed data**: none from emulate (this phase doesn't touch it). `SelfSignedJwksFixture` generates its own RSA keypair per test run (2048-bit, `openssl_pkey_new`) — no fixture files committed to the repo.

## 12. Open Items

1. Confirm Phase 2 exposes a JWKS verifier generalized by URL (§4.1) — if Phase 2's actual component is hardcoded to the session JWKS path, this phase cannot reuse it as specified and the gap needs to be resolved at integration (Phase 13), not silently duplicated.
2. `workbench/routes/ai.php` is confirmed **not** auto-loaded by the built Testbench skeleton (`Workbench.php::discoverRoutes()` only recognizes `'web'`/`'api'` discovery keys) — the `WorkbenchServiceProvider::boot()` fallback (§6.5 step 5 / §7's `testbench.yaml` row) is required, not conditional; no longer open. Still open: if `SelfSignedJwksFixture` needs deduplicating against a differently-shaped Phase 2 fixture, resolve at integration (Phase 13).
3. WorkOS's `Idempotency-Key` support for `createOAuthApplication`/`createM2MApplication` is unconfirmed (F13) — verify against WorkOS docs/support before any caller relies on the passthrough for dedup guarantees; the parameter is exposed regardless since it costs nothing to thread through.
4. `scopes_supported` in the well-known document: WorkOS's own documented example omits it entirely (F2); this phase includes it only when configured. Reconcile with real MCP client behavior if any client turns out to choke on its presence/absence.
5. `laravel/mcp` pinned at `^0.9.1` per Packagist as of 2026-08-06 (F7) — re-check this is still the current minor line at actual implementation time; it's an actively-developed 0.x package.
6. Introspection (`IntrospectionClient`, `ConnectManager::introspect()`) was descoped from this phase during review (§6.2) — if a future contract amendment adds it back to Connect & MCP Auth or a later phase, re-derive F5/F6 (introspection wire shape; `WorkOS\WorkOS` has no `HttpClient` accessor) before implementing.

## 13. Validation Commands

```bash
composer analyse                          # PHPStan (larastan)
composer lint:check                       # Pint check-only
composer test:types                       # Pest type coverage --min=100
vendor/bin/pest --group=connect-mcp       # every suite this phase added (seconds)
vendor/bin/pest --filter=ConnectTest
vendor/bin/pest --filter=McpAuthenticationTest
vendor/bin/pest --filter=OAuthProtectedResourceMetadataTest
composer test                             # full chain — must be green before commit
```

Manual recipe check (§6.5 — not part of `composer test`):
```bash
composer serve
curl -s http://localhost:8000/.well-known/oauth-protected-resource | jq .
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/mcp/demo          # expect 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/mcp/demo \
  -H "Authorization: Bearer <token signed by the throwaway fixture script>"                # expect non-401
```

## 14. Rollout

No feature flags — per the template, this phase lands green on `composer test` and is releasable as-is. Rollback = `git revert` of this phase's commit, or reset to the recorded contract anchor (`git reset --hard e845a2f`) if reverting individually is impractical.
