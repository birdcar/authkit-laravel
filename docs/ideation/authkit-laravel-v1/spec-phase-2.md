# Implementation Spec: AuthKit Laravel v1 - Phase 2 — Auth Core & Sealed Sessions

**Contract**: `./contract-data.json`
**Shared conventions**: `./spec-template-feature-area.md` (this phase establishes several of the conventions that document declares canonical — where this spec and that file disagree, this spec wins for Phase 2's own deliverables since it defines them first)
**Estimated Effort**: L

**Risk**: High (per contract `execution.phases`). This is the deepest failure-mode analysis in the project: sealed-session cryptography, JWKS availability, concurrent refresh, and cross-application token replay all live here.

## Assumed Phase 1 Interface

This spec is standalone-implementable, but it builds on top of Phase 1 ("Foundation & Client Binding"), which runs first per the contract's prereq graph. Phase 1 is assumed to have already delivered, by the time this phase starts:

- `config/authkit.php` (renamed from the skeleton's `config/authkit-laravel.php`) containing at minimum: `api_key`, `client_id`, `base_url`, `redirect_uri`, `cookie_password` — all read via `config('authkit.*')`, sourced from `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_BASE_URL`, `WORKOS_REDIRECT_URI`, `WORKOS_COOKIE_PASSWORD` env vars in the config file (never `env()` in `src/`).
- A container-bound `\WorkOS\WorkOS` singleton (config-driven construction, base-URL override for `emulate`, injectable Guzzle handler for MockHandler-backed tests), resolvable as `app(\WorkOS\WorkOS::class)`.
- Test harness plumbing: an emulate-boot helper (`npx @workos/emulate` with `/health` readiness) and MockHandler test helpers, plus the empirical AuthKit token-audit findings recorded in the decision log.
- `authkit:install` registers `config/auth.php` guard/provider entries for a real consumer app — **this phase's workbench wiring is separate and described below**, since workbench is a Testbench skeleton, not a normal consumer app that `authkit:install` can target.

If any of these differ in shape from what Phase 1 actually shipped (e.g. a `WorkosClientManager` wrapper class instead of a raw `\WorkOS\WorkOS` singleton), adapt the container-resolution one-liners in this spec accordingly — the class/method names this spec calls out (`userManagement()`, `sessionManager()`, `pkce()`) are the vendored SDK's own accessor methods on `\WorkOS\WorkOS`, confirmed by reading `vendor/workos/workos-php/lib/WorkOS.php`, so they are stable regardless of how Phase 1 named its wrapper.

## Technical Approach

Phase 2 replaces laravel/workos's session-in-Laravel-session model with the contract's sealed-cookie doctrine: the AuthKit sealed session cookie is the single source of truth for both authentication and authorization state, and Laravel's own session stays free for app state (plus the transient OAuth handshake artifacts — PKCE verifier and `state` — which are not WorkOS state and are fine to keep in Laravel's session).

Three things make this phase high-risk and shape the architecture:

1. **The vendored SDK's `SessionManager::authenticate()` is the only sanctioned way to verify a sealed cookie's signature and expiry**, and it deliberately does not check `iss`/`aud` (a TODO at `SessionManager.php:444`, pointing at "the other SDKs skip it too"). Its public return array is also a fixed, narrow shape (`sid`, `organization_id`, `role`, `roles`, `permissions`, `entitlements`, `feature_flags`, `user`, `impersonator`) that omits `iss`, `aud`-equivalent (`client_id`), `sub`, `act`, `jti`. To add the iss/aud check the contract requires, this phase re-unseals the same cookie ourselves (`SessionManager::unsealData()` is a public static method) and decodes the already-signature-verified access token's JWT payload a second time, purely for claim access — never re-implementing signature verification, which stays the SDK's job. This is the architectural seam the whole "iss/aud validation layer on top of the SDK" requirement rests on, and it is documented as a security invariant below.
2. **The SDK's JWKS cache is process-local and 5 minutes deep** (`SessionManager`'s private `$jwksCache` array). In a classic PHP-FPM deployment (no shared memory across requests), that cache buys nothing across requests — every request that needs to verify a token re-fetches JWKS unless this phase adds its own persistent, Laravel-cache-backed layer. Worse: if that fetch fails during a WorkOS outage, the SDK's `authenticate()` swallows the exception and returns a generic `invalid_jwt` reason — indistinguishable from "this token is garbage." Without an app-level JWKS grace cache, a transient WorkOS blip becomes a total sign-out event for every active session. This phase adds a Guzzle-middleware-level JWKS grace cache to intercept the failure *before* the SDK's own catch-all collapses it.
3. **Refresh is dangerous under concurrency.** Multiple in-flight requests from the same browser (parallel asset/XHR requests, tab duplication) can all observe a near-expiry token and all try to refresh at once. Refresh tokens rotate on every use (confirmed by emulate; the contract flags this as *emulate being stricter than confirmed prod behavior*, which means the implementation must never assume it is safe to reuse an old refresh token even once). This phase adds a single-flight lock keyed on session ID, with a shared result cache so followers pick up the fresh cookie without a second network round trip.

The auth flow itself follows the "routes as thin wrappers over public form requests" doctrine verbatim: `AuthKitLoginRequest`, `AuthKitAuthenticationRequest`, and `AuthKitLogoutRequest` carry all the logic and are directly usable by an app with its own controllers; the package's own `AuthKitController` + three routes are a thin, optional convenience built on the exact same classes.

## Decisions Considered and Rejected

_Carried from the contract; all entries plausibly touching auth, sessions, users, or the SDK relationship are included._

- **Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state; app's Laravel session stays free for app state** — rejected: exchange code then hydrate Laravel's standard session guard (laravel/workos's approach). Reason: WorkOS must remain the session source of truth for both authn and authz; the SDK's `SessionManager` already does unseal/refresh/JWKS heavy lifting.
- **Auth flows exposed both as registered routes and as form-request helpers, with routes as thin wrappers delegating to the form requests** — rejected: routes-only surface. Reason: apps with custom controllers keep every nicety — parity with the one thing laravel/workos got right; one implementation, two entry points.
- **Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id` ↔ `external_id` linking, refreshed by the events pipeline** — rejected: no local state / read-through API calls per request. Reason: Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events.
- **Phase 1 ends with an empirical AuthKit token audit: decode a real AuthKit-issued token to confirm canonical `iss`/`aud` values and default presence of `role`/`permissions`/`feature_flags` claims, recorded in the decision log before Phase 2 starts** — rejected: assume the SDK's TODO values and default-populated claims. Reason: hidden-dependency blocker — `SessionManager`'s own source defers `iss`/`aud` as unconfirmed, and the zero-HTTP RBAC + claim-first flags + quickstart goals all silently depend on claims being present without dashboard setup. **This phase treats the audit's exact confirmed values as an Open Item** (see below) since the audit's numeric findings are not present in this phase's inputs; the validation layer is built to be config-driven so the confirmed values slot in without a code change.
- **Credentials read from config only; env is never read outside config files** — rejected: runtime `env()` reads like the SDK's own fallback does. Reason: `php artisan config:cache` empties env at runtime; config-only is the Laravel paved path.
- **Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage** — rejected: SDK fakes only. Reason: wire fidelity where possible. For this phase specifically: the happy-path auth flow is emulate-backed; the `SessionSecurity` suite (forged/expired/wrong-claim tokens) is necessarily MockHandler + a locally-generated JWKS fixture, since emulate cannot be told to sign a token with an attacker-controlled key or an arbitrary `iss`.
- **Stay on Pest 4 with PHP ^8.3 floor** — rejected: Pest 5. Reason: PHP 8.3 supported until Dec 2027; Laravel 13 supports it.
- **Wire the Events worker and emulate into `php artisan dev`** — not directly this phase's concern (Phase 4), carried for awareness only since this phase's local dev loop uses `composer serve` / emulate directly.
- **Widgets are excluded from v1 entirely** — rejected: widget token minting in MVP. Reason: not relevant to this phase, carried for completeness — this phase issues no widget tokens.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest --filter=SessionSecurity` (seconds — pure MockHandler + local JWT fixtures, no network, no emulate boot; this is the suite iterated against while building the guard and claims-validation layer).

**Secondary loop**: `vendor/bin/pest --filter=AuthenticationFlow` (tens of seconds — boots `emulate`, exercises the full login → callback → logout redirect chain; run before considering the phase done, not on every edit).

**Playground**: Pest feature suites are primary. `composer serve` (Testbench workbench app, pointed at a locally running `npx @workos/emulate`) is the secondary playground for eyeballing the actual `Set-Cookie` header in browser devtools — confirming `httpOnly`/`secure`/`SameSite` flags took effect and that the sealed cookie's byte size looks sane, which a Pest assertion can check numerically but a human should still glance at once.

**Why this approach**: the highest-risk logic (claim decoding, iss/aud validation, single-flight refresh) is pure PHP with no I/O once a sealed cookie string exists, so a MockHandler-backed Pest suite with committed JWT fixtures gives sub-second feedback on the exact cases that matter (forged signature, wrong issuer, tampered bytes) without ever touching the network.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `src/Auth/AccessTokenClaims.php` | Readonly DTO decoded from the access token's JWT payload — `sub`, `iss`, `clientId`, `org_id`, `role`, `roles`, `permissions`, `feature_flags`, `sid`, `jti`, `iat`, `exp`, `act.sub` (actor/impersonator). |
| `src/Auth/JwtPayloadDecoder.php` | Base64url-decodes a JWT's payload segment without re-verifying the signature. Used only on tokens that `SessionManager::authenticate()` already verified in the same request. |
| `src/Auth/JwtClaimsValidator.php` | Checks decoded claims' `iss` and `client_id` (the aud-equivalent) against `config('authkit.jwt.*')` — the layer the SDK's TODO leaves undone. |
| `src/Auth/WorkosGuard.php` | The `workos` guard: implements `Illuminate\Contracts\Auth\Guard`, resolves `Auth::user()` from the sealed session cookie. |
| `src/Auth/SessionRefresher.php` | Single-flight-locked refresh: wraps `SessionManager::refresh()` with a cache lock + shared result cache keyed by session ID. |
| `src/Http/JwksGraceCache.php` | Guzzle middleware: caches successful JWKS responses in Laravel's cache and serves the last-known-good response on transport failure, so a WorkOS/JWKS outage degrades to "stale keys" instead of "everyone is logged out." |
| `src/Http/Requests/AuthKitLoginRequest.php` | Public form request: generates PKCE pair + state, builds the WorkOS authorization URL, stashes handshake artifacts in the Laravel session. |
| `src/Http/Requests/AuthKitAuthenticationRequest.php` | Public form request: validates `state`, exchanges `code` + verifier, seals the session, finds-or-creates the local user, sets `external_id` in WorkOS, dispatches `Login`. |
| `src/Http/Requests/AuthKitLogoutRequest.php` | Public form request: builds the WorkOS logout URL from the current sealed cookie, returns a cookie-clearing redirect. |
| `src/Http/Controllers/AuthKitController.php` | Thin controller (`login`, `callback`, `logout`) delegating entirely to the three form requests above. |
| `src/Http/Middleware/RefreshWorkosSession.php` | The `authkit.session` middleware: detects near/at-expiry claims, delegates to `SessionRefresher`, re-seals the cookie or redirects to `authkit.login`. |
| `src/Concerns/HasWorkosUser.php` | Trait for the consumer's User model: `workos_id` awareness, `claims()`/`setWorkosClaims()` accessor, `findOrCreateForWorkosUser()`, `impersonator()`. |
| `src/Events/Login.php` | Dispatched after a successful callback exchange. Carries the authenticated user and the `AuthenticateResponse`. |
| `src/Events/Impersonating.php` | Dispatched whenever the guard resolves a user whose claims carry a non-null `act.sub`. Carries the user, the impersonator's WorkOS user ID, and (when present) the sealed session's impersonator email/reason. |
| `src/Events/SessionCookieOversized.php` | Dispatched when a freshly sealed cookie exceeds `authkit.session.max_cookie_bytes`. Acknowledgment event, not a blocker — see Failure Modes. |
| `src/Events/JwksServedStale.php` | Dispatched whenever `JwksGraceCache` serves a cached JWKS response because the live fetch failed. |
| `src/Exceptions/AuthKitStateMismatchException.php` | Thrown by `AuthKitAuthenticationRequest` when the callback's `state` doesn't match the stashed value (CSRF/replay guard). |
| `database/migrations/2026_01_02_000000_add_workos_id_to_users_table.php` | Publishable migration: adds nullable, unique `workos_id` string column to the consumer app's `users` table. Generated via the Testbench-proxied generator (see Implementation Steps). |
| `tests/Feature/SessionSecurityTest.php` | Forged signature, expired, wrong `iss`, wrong `aud`/`client_id`, tampered cookie, missing cookie — MockHandler + local JWKS fixture. |
| `tests/Feature/AuthenticationFlowTest.php` | Emulate-backed happy path: login redirect → callback → guard resolves user → logout. |
| `tests/Feature/SessionRefreshTest.php` | Near-expiry refresh, hard-expiry redirect, single-flight lock contention, oversized-cookie event. |
| `tests/Unit/AccessTokenClaimsTest.php` | Pure decode/impersonation-accessor unit tests, no HTTP. |
| `tests/Fixtures/JwtFixture.php` | Test-only helper: generates a local RSA keypair once, builds a matching JWKS response array, and seals/forges JWTs with arbitrary claim overrides and an optional wrong signing key. |
| `tests/Fixtures/jwks/test-signing-key.pem` (+ `.pub.pem`) | Committed static RSA test keypair backing `JwtFixture`, so `SessionSecurity` runs are deterministic across machines and CI. |

**No companion `users` table migration is added — but the users table arrives by two different mechanisms depending on runtime, and implementers must wire the second one explicitly.**

- **Workbench runtime** (`composer serve`, and any suite that opts into `Orchestra\Testbench\Concerns\WithWorkbench`): `testbench.yaml` leaves `workbench.install` at its default `true`, so `Orchestra\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray::includesDefaultMigrations()` pushes `default_migration_path()` — the vendored skeleton's `vendor/orchestra/testbench-core/laravel/migrations`, which ships `0001_01_01_000000_testbench_create_users_table.php` (`id`/`name`/`email` unique/`email_verified_at`/`password`/`remember_token`/`timestamps`, the exact shape `workbench/app/Models/User.php` and its `UserFactory` assume) — onto the migration paths.
- **Package Pest suites**: this loading is NOT automatic. `tests/TestCase.php` extends `Orchestra\Testbench\TestCase`, whose `Concerns\Testing` trait list (`ApplicationTestingHooks`, `CreatesApplication`, `HandlesAssertions`, `HandlesAttributes`, `HandlesDatabases`, `HandlesRoutes`, `InteractsWithMigrations`, `WithFactories` — verified in `vendor/orchestra/testbench-core/src/Concerns/Testing.php`) does **not** include `WithWorkbench`, so `setUpWithWorkbench()`/`LoadMigrationsFromArray` never fires for this package's own test runs. Suites that need a `users` table (`HasWorkosUserTraitTest`, guard `retrieveByCredentials` coverage) must call `$this->loadLaravelMigrations()` in `defineDatabaseMigrations()` **before** this package's `add_workos_id` ALTER migration runs. `loadLaravelMigrations()` is already available on every test case (provided by `Concerns\InteractsWithMigrations`, verified at `vendor/orchestra/testbench-core/src/Concerns/InteractsWithMigrations.php:124`) and migrates exactly `default_migration_path()` with `--realpath` — the same skeleton users table the workbench runtime gets.
- **Why no companion migration**: shipping our own `create_users_table` under `workbench/database/migrations/` would `Schema::create('users', ...)` a second time whenever the skeleton path is also active (always true in the workbench runtime) and fail with "table already exists". Only the `workos_id` ALTER migration above is new; it targets whichever mechanism provided the table.

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/AuthkitServiceProvider.php` | `register()`: bind `JwtClaimsValidator` (singleton, config-sourced), bind `\WorkOS\Service\UserManagement`, `\WorkOS\SessionManager`, `\WorkOS\PKCEHelper` as derived from the existing `\WorkOS\WorkOS` singleton's accessor methods (`->userManagement()`, `->sessionManager()`, `->pkce()`) rather than constructing a second client. `boot()`: `Auth::extend('workos', ...)` registering `WorkosGuard`; `$router->aliasMiddleware('authkit.session', RefreshWorkosSession::class)`; verify/add `loadMigrationsFrom(__DIR__.'/../database/migrations')` unconditionally (idempotent no-op if Phase 1 already added it — required so this phase's `workos_id` migration applies automatically under Testbench). |
| `config/authkit.php` | Add `jwt` (`issuer`, `audience`), `session` (`cookie`, `same_site`, `refresh_before_seconds`, `lock_wait_seconds`, `lock_ttl_seconds`, `max_cookie_bytes`, `jwks_grace_ttl_seconds`), and `routes` (`enabled`, `prefix`) sections. Exact snippet under Implementation Details. |
| `routes/authkit-laravel.php` | Replace the commented-out placeholder with the three `authkit.*`-named routes, gated by `config('authkit.routes.enabled')` and wrapped in the `web` middleware group (required for the Laravel session-backed PKCE handshake). |
| `workbench/app/Models/User.php` | Add `use HasWorkosUser;`, add `workos_id` to `$fillable`/casts as needed. |
| `workbench/app/Providers/WorkbenchServiceProvider.php` | Register the `workos` guard + `workos` provider in `auth.guards`/`auth.providers` via `config()->set(...)` in `register()`, and a minimal `/login`, `/callback`, `/logout` wiring in `boot()` calling the package's routes (or confirming the package routes are sufficient) — the **minimum** needed to exercise this phase's flow interactively. Full grep-enforced workbench polish is Phase 13's job; this phase only needs a working example, not a complete one. |
| `testbench.yaml` | Uncomment `- Workbench\App\Providers\WorkbenchServiceProvider` so the guard/provider config above actually loads for `composer serve`. |
| `tests/TestCase.php` | Add a `getEnvironmentSetUp($app)` override: sets `auth.guards.workos`, `auth.providers.workos`, and the `authkit.*` config keys this phase needs (client_id, cookie_password, base_url, jwt issuer/audience) for the package's own test environment — independent of whatever the workbench app does, since package tests run against Testbench's synthetic app, not workbench. |
| `tests/Pest.php` | No change expected; confirm `uses(TestCase::class)->in(__DIR__)` still covers the new `tests/Fixtures` directory or scope fixtures out of Pest's test-discovery path (fixtures are helpers, not test files — verify they don't get picked up as a test suite by naming them without a `Test` suffix, which is already the plan). |

### Deleted Files

None. The Phase 1 placeholder migration/route/config content is superseded in place by later phases' edits, not deleted here.

## Implementation Details

### 1. Access Token Claims & the iss/aud Validation Layer

**Pattern to follow**: none in-repo yet — this is new; the shape mirrors the vendored `SessionManager::authenticate()`'s own return array (`vendor/workos/workos-php/lib/SessionManager.php:137-187`), extended with the fields it omits.

**Overview**: `SessionManager::authenticate()` is the only sanctioned signature/expiry check. It hands back a narrow array missing `iss`, `client_id`, `sub`, `jti`, `act`. This component re-unseals the same cookie (public static `SessionManager::unsealData()`) and decodes the same, already-verified access token's JWT payload a second time to recover those fields, then checks `iss`/`client_id` against config.

**Security invariant** (must hold for this component to be safe): claim decoding here must only ever run on a token for which `SessionManager::authenticate()` has *already* returned `authenticated: true` in the same request, using the identical sealed-cookie string and `cookie_password`. `JwtPayloadDecoder` performs no signature check of its own — it is a pure base64url+JSON decode. Never call it on an unverified token.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Auth;

final readonly class AccessTokenClaims
{
    public function __construct(
        public string $sub,
        public string $iss,
        public string $clientId,
        public ?string $organizationId,
        public ?string $role,
        /** @var list<string> */
        public array $roles,
        /** @var list<string> */
        public array $permissions,
        /** @var list<string> */
        public array $featureFlags,
        public string $sessionId,
        public string $jwtId,
        public int $issuedAt,
        public int $expiresAt,
        public ?string $actorId,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            sub: (string) $payload['sub'],
            iss: (string) $payload['iss'],
            clientId: (string) $payload['client_id'],
            organizationId: $payload['org_id'] ?? null,
            role: $payload['role'] ?? null,
            roles: $payload['roles'] ?? [],
            permissions: $payload['permissions'] ?? [],
            featureFlags: $payload['feature_flags'] ?? [],
            sessionId: (string) $payload['sid'],
            jwtId: (string) $payload['jti'],
            issuedAt: (int) $payload['iat'],
            expiresAt: (int) $payload['exp'],
            actorId: $payload['act']['sub'] ?? null,
        );
    }

    public function isImpersonated(): bool
    {
        return $this->actorId !== null;
    }

    public function secondsUntilExpiry(): int
    {
        return $this->expiresAt - time();
    }
}

final class JwtPayloadDecoder
{
    /** @return array<string, mixed> */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Malformed access token: expected 3 JWT segments.');
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Malformed access token: payload segment is not valid JSON.');
        }

        return $payload;
    }
}

final readonly class JwtClaimsValidator
{
    public function __construct(
        private string $expectedIssuer,
        private string $expectedAudience,
    ) {}

    public function validate(AccessTokenClaims $claims): bool
    {
        return hash_equals($this->expectedIssuer, $claims->iss)
            && hash_equals($this->expectedAudience, $claims->clientId);
    }
}
```

**`config/authkit.php` additions** — the exact snippet File Changes promises for the `jwt`, `session`, and `routes` sections. All three land here since Component 1 is this phase's first config-touching component; Components 3–5 below consume the `session`/`routes` keys defined in it:

```php
'jwt' => [
    'issuer' => env('WORKOS_JWT_ISSUER', 'https://api.workos.com'),
    // Left null by default — the "falls back to authkit.client_id" behavior described
    // in Key Decisions below is applied where JwtClaimsValidator is bound in
    // AuthkitServiceProvider::register(), not hardcoded as a second default here.
    'audience' => env('WORKOS_JWT_AUDIENCE'),
],

'session' => [
    'cookie' => env('WORKOS_SESSION_COOKIE', 'authkit_session'),
    'same_site' => env('WORKOS_SESSION_SAME_SITE', 'lax'),
    'refresh_before_seconds' => (int) env('WORKOS_SESSION_REFRESH_BEFORE_SECONDS', 60),
    'lock_wait_seconds' => (int) env('WORKOS_SESSION_LOCK_WAIT_SECONDS', 5),
    'lock_ttl_seconds' => (int) env('WORKOS_SESSION_LOCK_TTL_SECONDS', 10),
    'max_cookie_bytes' => (int) env('WORKOS_SESSION_MAX_COOKIE_BYTES', 3800),
    'jwks_grace_ttl_seconds' => (int) env('WORKOS_SESSION_JWKS_GRACE_TTL_SECONDS', 86400),
],

'routes' => [
    'enabled' => (bool) env('WORKOS_ROUTES_ENABLED', true),
    'prefix' => env('WORKOS_ROUTES_PREFIX', 'authkit'),
],
```

Every default above matches a value already assumed elsewhere in this spec, so this section is the single source of truth for them rather than a new invention: `jwt.issuer` matches the SDK's own `baseUrl` default (see Key Decisions below); `session.cookie` matches the `authkit_session` name used throughout the API Design examples and the `WorkosGuard`/`AuthKitController` code; `session.same_site` / `refresh_before_seconds` / `max_cookie_bytes` / `jwks_grace_ttl_seconds` match the literal fallback values already hardcoded into `RefreshWorkosSession`'s and `JwksGraceCache`'s `config('authkit...', <default>)` calls (Components 2 and 5) — with this section in place, those inline fallbacks only matter if the config file itself is missing the key, e.g. an outdated publish. `session.lock_wait_seconds` (5) / `lock_ttl_seconds` (10) are new defaults introduced here for the single-flight refresh lock (Component 5): sized so a losing concurrent request's bounded wait resolves well inside a typical request timeout, while the lock's own TTL comfortably outlasts one `SessionManager::refresh()` HTTP round trip. `routes.prefix` matches the `authkit` prefix used in the API Design route table.

**Key decisions**:

- Validate the `client_id` claim as the audience-equivalent, not a literal `aud` claim. The context brief's live-docs claim inventory lists `iss sub client_id act org_id role roles permissions entitlements feature_flags sid jti exp iat` — **no `aud` claim**. The SDK's own TODO calls it "iss/aud" generically, but WorkOS's actual token shape carries `client_id` for the purpose an `aud` claim would normally serve (rejecting tokens issued for a different application). This defends the exact scenario the SDK's TODO exists for: a WorkOS *environment* signs tokens with one JWKS key shared across every client_id/application in that environment. Without this check, a valid, signed, non-expired token minted for Application B is accepted by Application A's guard if the two share an environment — a cross-application session-replay bypass. With it, mismatched `client_id` is rejected even though the signature verifies cleanly.
- `authkit.jwt.audience` defaults to `authkit.client_id` when unset — the common case needs zero extra configuration; only multi-client-per-environment setups need to override it.
- `authkit.jwt.issuer` ships a default of `https://api.workos.com` (matching the SDK's own `baseUrl` default) but this is **not guaranteed correct for every environment** — AuthKit supports custom auth domains, which may change the actual `iss` value. This is exactly what Phase 1's empirical token audit exists to confirm; see Open Items.
- No independent `exp`/clock-skew re-check here. The SDK's `decodeAccessToken()` already rejects expired tokens with no leeway. Duplicating that check here would either agree with the SDK (no-op) or disagree and create two different definitions of "expired" — reject the temptation.

**Implementation steps**:

1. `vendor/bin/testbench make:migration` is not applicable here (no schema); create `src/Auth/JwtPayloadDecoder.php`, `src/Auth/AccessTokenClaims.php`, `src/Auth/JwtClaimsValidator.php` directly.
2. Add the `jwt` config section to `config/authkit.php` (see File Changes).
3. Bind `JwtClaimsValidator` as a singleton in `AuthkitServiceProvider::register()`, sourcing both values from config with the audience fallback above.

**Feedback loop**:

- **Playground**: `tests/Unit/AccessTokenClaimsTest.php` + a small in-line dataset of raw payload arrays.
- **Experiment**: decode a payload with every claim present; decode one missing optional claims (`role`, `act`, `org_id`) and confirm sane defaults (`null`, `[]`); decode one with `act.sub` present and confirm `isImpersonated()` flips true; feed `JwtClaimsValidator::validate()` a claims object with correct `iss` but wrong `client_id` and confirm `false`.
- **Check command**: `vendor/bin/pest --filter=AccessTokenClaims`

### 2. JWKS Outage Grace Cache

**Pattern to follow**: none in-repo; this is a Guzzle HTTP middleware, a pattern documented at `vendor/workos/workos-php/lib/HttpClient.php:44` (`new Client(['handler' => $handler])` — the exact seam this middleware attaches to).

**Overview**: Without this component, a WorkOS JWKS outage is indistinguishable — from inside `SessionManager::authenticate()` — from "every currently-active session's token is garbage," because the SDK's `fetchJwks()` failure is caught by the same generic `try/catch` that catches actual signature failures, both collapsing to `reason: invalid_jwt`. This middleware intercepts at the transport layer, *before* that collapse happens: on a successful JWKS fetch it caches the raw response body; on a failed fetch it serves the last cached body instead of propagating the exception, so `SessionManager::authenticate()` never even observes the outage — it just verifies against slightly-stale (but still valid, assuming no key rotation happened mid-outage) keys.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http;

use Authkit\Authkit\Events\JwksServedStale;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class JwksGraceCache
{
    public function __construct(
        private Repository $cache,
        private int $graceTtlSeconds,
    ) {}

    public function middleware(): callable
    {
        return fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            if (! str_contains($request->getUri()->getPath(), '/sso/jwks/')) {
                return $handler($request, $options);
            }

            $cacheKey = 'authkit:jwks-grace:'.sha1((string) $request->getUri());

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($cacheKey) {
                    if ($response->getStatusCode() === 200) {
                        $body = (string) $response->getBody();
                        $response->getBody()->rewind(); // reading the body above moved the cursor to EOF — rewind so the SDK can still read it.
                        $this->cache->put($cacheKey, $body, $this->graceTtlSeconds);
                    }

                    return $response;
                },
                function (\Throwable $reason) use ($cacheKey) {
                    $stale = $this->cache->get($cacheKey);
                    if ($stale === null) {
                        return Create::rejectionFor($reason);
                    }

                    event(new JwksServedStale($reason->getMessage()));

                    return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $stale);
                },
            );
        };
    }
}
```

**Key decisions**:

- Cache TTL (`authkit.session.jwks_grace_ttl_seconds`, default 86400 = 24h) is deliberately much longer than the SDK's own 300-second freshness cache. The SDK's cache answers "how often do we re-check," this cache answers "how long are we willing to serve stale keys during an outage before giving up." 24 hours is generous enough to ride out any realistic incident window while bounding how long a rotated-away key could theoretically still verify a forged token if WorkOS's private key were ever compromised mid-outage — an accepted, named tradeoff, not an oversight.
- Scoped to `/sso/jwks/` paths only via a path check, not applied to every request through the shared client — a bug in this middleware should not be able to affect non-JWKS traffic (org lookups, user CRUD, etc.).
- `$response->getBody()->rewind()` after reading the body for caching. `(string) $response->getBody()` drains the stream to EOF; without the rewind, the SDK's own `HttpClient::decodeResponse()` (`getBody()->getContents()`) would read an empty string on the very next line, because it starts from the cursor's now-EOF position, not from byte 0. This is verified against the vendored `Psr7\Stream` seekable-stream behavior — Guzzle's default response bodies are seekable.
- Wiring this into the shared `\WorkOS\WorkOS` singleton's Guzzle `HandlerStack` is a genuine cross-phase integration point: Phase 1 owns that singleton's construction. **Flagged as an Open Item** — see below.

**Feedback loop**:

- **Playground**: a small Pest test using `GuzzleHttp\Handler\MockHandler` stacked with `JwksGraceCache::middleware()` directly (no Laravel container needed for this unit-level check).
- **Experiment**: (1) first request 200s with a JWKS body → cached; (2) second request throws a connection exception → middleware serves the cached body, `JwksServedStale` event fires; (3) third request (no prior success cached) throws → the exception propagates unchanged.
- **Check command**: `vendor/bin/pest --filter=JwksGraceCache`

### 3. WorkosGuard (`workos` guard)

**Pattern to follow**: `Illuminate\Auth\RequestGuard` / `Illuminate\Contracts\Auth\Guard` contract shape (check, guest, user, id, validate, hasUser, setUser) — no in-repo precedent since this package has no guard yet; the interface itself is the pattern.

**Overview**: Resolves `Auth::user()` for the `workos` guard by reading the sealed session cookie from the current request, verifying it via `SessionManager::authenticate()`, layering the iss/client_id check, then looking up the local user by `workos_id` through the standard Laravel `UserProvider` contract (no custom lookup method needed — `EloquentUserProvider::retrieveByCredentials(['workos_id' => $sub])` already does a plain `WHERE workos_id = ?`).

```php
declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use Authkit\Authkit\Events\Impersonating;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use WorkOS\SessionManager;

final class WorkosGuard implements Guard
{
    private bool $resolved = false;

    private ?Authenticatable $user = null;

    public function __construct(
        private readonly UserProvider $provider,
        private readonly SessionManager $sessionManager,
        private readonly JwtClaimsValidator $validator,
        private readonly Request $request,
        private readonly string $cookieName,
        private readonly string $cookiePassword,
        private readonly string $clientId,
        private readonly string $baseUrl,
    ) {}

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $sealed = $this->request->cookie($this->cookieName);
        if (! is_string($sealed) || $sealed === '') {
            return $this->user = null;
        }

        $result = $this->sessionManager->authenticate($sealed, $this->cookiePassword, $this->clientId, $this->baseUrl);
        if (! $result['authenticated']) {
            return $this->user = null;
        }

        try {
            $raw = SessionManager::unsealData($sealed, $this->cookiePassword);
            $claims = AccessTokenClaims::fromPayload(JwtPayloadDecoder::decode($raw['access_token']));
        } catch (\Throwable) {
            return $this->user = null;
        }

        if (! $this->validator->validate($claims)) {
            return $this->user = null;
        }

        $user = $this->provider->retrieveByCredentials(['workos_id' => $claims->sub]);
        if (! $user instanceof Authenticatable) {
            return $this->user = null;
        }

        if (method_exists($user, 'setWorkosClaims')) {
            $user->setWorkosClaims($claims);
        }

        if ($claims->isImpersonated()) {
            event(new Impersonating($user, (string) $claims->actorId, $result['impersonator'] ?? null));
        }

        return $this->user = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false; // this guard never accepts credentials directly — only a sealed cookie.
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->resolved = true;
        $this->user = $user;
    }
}
```

**Key decisions**:

- Memoized per guard instance (`$resolved`/`$user`), same pattern Laravel's own guards use, so repeated `Auth::user()` calls within one request don't re-unseal/re-verify the cookie.
- **Orphaned session → treated as unauthenticated, not an error.** If `retrieveByCredentials` returns null (local user row deleted, DB reset, replica lag), the guard returns `null` from `user()` exactly as it would for "no cookie at all." This is a deliberate, if surprising, choice — see Failure Modes.
- The guard never mutates the response (sets/clears cookies). Single responsibility: this class answers "who is this," `RefreshWorkosSession` and `AuthKitLogoutRequest` own cookie lifecycle.
- Registered via `Auth::extend('workos', ...)` in `AuthkitServiceProvider::boot()`, resolving its `UserProvider` via `Auth::createUserProvider($config['provider'])` where `$config` is the guard's own entry in the consumer's `config/auth.php` (`guards.workos`) — standard Laravel custom-guard wiring, no special-casing needed.

**Feedback loop**:

- **Playground**: `tests/Feature/SessionSecurityTest.php`, using `JwtFixture` (component 8) to seal cookies with controlled claims, and a fake `UserProvider`/in-memory user row.
- **Experiment**: valid cookie + matching local user → `user()` returns the model with claims attached; valid cookie + no matching local user → `null`; valid cookie, correct signature, wrong `iss` → `null`; valid cookie, correct signature, wrong `client_id` → `null`; missing cookie → `null`; tampered cookie bytes → `null`.
- **Check command**: `vendor/bin/pest --filter=SessionSecurity`

### 4. Auth Flow: Login → Callback → Logout (form requests + thin controller + routes)

**Pattern to follow**: laravel/workos's `AuthKitLoginRequest`/`AuthKitAuthenticationRequest`/`AuthKitLogoutRequest` naming (parity, per contract decision), reimplemented against the sealed-cookie model instead of Laravel session storage.

**Overview**: Three public `FormRequest` classes carry all logic; `AuthKitController` is a three-method pass-through.

**PKCE decision (taken, not left open)**: generate the PKCE pair with `\WorkOS\PKCEHelper::generate()` (a pure static crypto helper — no client/secret coupling) and pass `codeChallenge`/`codeChallengeMethod` into `\WorkOS\Service\UserManagement::getAuthorizationUrl()`; exchange via `UserManagement::authenticateWithCode(code:, codeVerifier:)`. **Rejected**: `PKCEHelper::getAuthKitAuthorizationUrl()` + `authKitCodeExchange()` — despite the "AuthKit"-branded name, these two methods are the SDK's *public-client* convenience wrappers (their own factory, `PKCEHelper::createPublicClient()`, explicitly constructs a client with `apiKey: ''`, and `authKitCodeExchange()`'s request body never includes `client_secret`). Our Laravel app is a confidential backend that holds `WORKOS_API_KEY` — using the public-client helper would silently drop the client_secret from the token exchange, weakening the credential model for zero benefit. `UserManagement::authenticateWithCode()` sends both the client secret *and* the PKCE verifier, and returns a typed `\WorkOS\Resource\AuthenticateResponse` instead of a raw array — matching the "no direct SDK references in consumer code" goal more cleanly, since our own code never has to hand-roll `User::fromArray()`.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use WorkOS\PKCEHelper;
use WorkOS\Service\UserManagement;

final class AuthKitLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function redirect(?string $intendedUrl = null): RedirectResponse
    {
        $pkce = PKCEHelper::generate();
        $state = bin2hex(random_bytes(16));

        $url = app(UserManagement::class)->getAuthorizationUrl(
            redirectUri: (string) config('authkit.redirect_uri'),
            codeChallengeMethod: $pkce['code_challenge_method'],
            codeChallenge: $pkce['code_challenge'],
            state: $state,
        );

        $this->session()->put('authkit.pkce.code_verifier', $pkce['code_verifier']);
        $this->session()->put('authkit.pkce.state', $state);
        if ($intendedUrl !== null) {
            $this->session()->put('url.intended', $intendedUrl);
        }

        return redirect()->away($url);
    }
}
```

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Exceptions\AuthKitStateMismatchException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use WorkOS\Resource\AuthenticateResponse;
use WorkOS\Service\UserManagement;

final class AuthKitAuthenticationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ];
    }

    public function authenticate(string $userModelClass): Authenticatable
    {
        $expectedState = $this->session()->pull('authkit.pkce.state');
        $codeVerifier = $this->session()->pull('authkit.pkce.code_verifier');

        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $this->validated('state'))) {
            throw new AuthKitStateMismatchException();
        }

        /** @var AuthenticateResponse $response */
        $response = app(UserManagement::class)->authenticateWithCode(
            code: (string) $this->validated('code'),
            codeVerifier: is_string($codeVerifier) ? $codeVerifier : null,
        );

        /** @var \Authkit\Authkit\Concerns\HasWorkosUser&Authenticatable $user */
        $user = $userModelClass::findOrCreateForWorkosUser($response->user);

        $sealed = \WorkOS\SessionManager::sealSessionFromAuthResponse(
            accessToken: $response->accessToken,
            refreshToken: $response->refreshToken,
            cookiePassword: (string) config('authkit.cookie_password'),
            user: $response->user->toArray(),
            impersonator: $response->impersonator?->toArray(),
        );

        event(new Login($user, $response));

        return tap($user, fn () => $this->session()->put('authkit._sealed_session', $sealed));
    }
}
```

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Cookie;
use WorkOS\Service\UserManagement;

final class AuthKitLogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function redirect(?string $returnTo = null): RedirectResponse
    {
        $sealed = $this->cookie((string) config('authkit.session.cookie'));
        $cookieName = (string) config('authkit.session.cookie');

        if (! is_string($sealed) || $sealed === '') {
            return redirect()->to($returnTo ?? '/')->withCookie(Cookie::forget($cookieName));
        }

        $sessionManager = app(\WorkOS\SessionManager::class);
        $result = $sessionManager->authenticate($sealed, (string) config('authkit.cookie_password'), (string) config('authkit.client_id'), (string) config('authkit.base_url'));

        // Already-invalid session: nothing meaningful to log out of at WorkOS, just clear our cookie.
        if (! $result['authenticated']) {
            return redirect()->to($returnTo ?? '/')->withCookie(Cookie::forget($cookieName));
        }

        $logoutUrl = app(UserManagement::class)->getLogoutUrl(sessionId: $result['session_id'], returnTo: $returnTo);

        return redirect()->away($logoutUrl)->withCookie(Cookie::forget($cookieName));
    }
}
```

The controller is a direct pass-through — note the callback route needs the freshly returned sealed cookie value attached to the response, which the form request stashes in the *Laravel* session (`authkit._sealed_session`) for the controller to read and attach as a cookie, keeping "seal the cookie" (form request's job) separate from "attach it to an HTTP response" (controller's job, since form requests don't own the response):

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Authkit\Authkit\Http\Requests\AuthKitAuthenticationRequest;
use Authkit\Authkit\Http\Requests\AuthKitLoginRequest;
use Authkit\Authkit\Http\Requests\AuthKitLogoutRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Cookie;

final class AuthKitController extends Controller
{
    public function login(AuthKitLoginRequest $request): RedirectResponse
    {
        return $request->redirect(intended: $request->session()->get('url.intended'));
    }

    public function callback(AuthKitAuthenticationRequest $request): RedirectResponse
    {
        /** @var class-string<Authenticatable> $userModel */
        $userModel = config('auth.providers.workos.model', config('auth.providers.users.model'));
        $request->authenticate($userModel);

        $sealed = (string) $request->session()->pull('authkit._sealed_session');
        $intended = (string) $request->session()->pull('url.intended', '/');

        return redirect()->to($intended)->withCookie($this->cookie($sealed));
    }

    public function logout(AuthKitLogoutRequest $request): RedirectResponse
    {
        return $request->redirect(returnTo: url('/'));
    }

    private function cookie(string $sealed): Cookie
    {
        return new Cookie(
            name: (string) config('authkit.session.cookie'),
            value: $sealed,
            expire: 0, // session cookie; WorkOS-side refresh_token lifetime governs actual validity.
            secure: (bool) config('session.secure', true),
            httpOnly: true,
            sameSite: (string) config('authkit.session.same_site', 'lax'),
        );
    }
}
```

**Key decisions**:

- `redirect_uri` matches the WorkOS Dashboard configuration at the **authorize** step only — the vendored `authenticateWithCode()`/`authKitCodeExchange()` bodies never resend `redirect_uri` at the token-exchange step (confirmed by reading `Service/UserManagement.php`), so there is no double-matching requirement to document as a gotcha, only a single one at authorize time.
- `state` is checked with `hash_equals()` (timing-safe), pulled (not just read) from the session so a replayed callback with the same `code`/`state` a second time fails immediately (state already consumed) rather than re-running the exchange.
- Routes require the `web` middleware group (session support for the PKCE handshake) — documented as a Failure Mode below for stateless-only apps.
- `AuthKitAuthenticationRequest::authenticate()` takes the user model class as a parameter rather than hardcoding it, so both the package's own controller and a custom-controller consumer app can point it at their actual `App\Models\User`.

**Implementation steps**:

1. `vendor/bin/testbench make:controller AuthKitController` then move the generated stub into `src/Http/Controllers/` and fill in per above (Testbench's generator scaffolds into the workbench app path by default in package-dev context; relocate the file, this is expected).
2. Write the three form requests directly (no generator applies — `make:request` scaffolds an app-namespaced class, package classes are hand-placed under `src/Http/Requests/`).
3. Add the `routes`/`jwt`/`session` config sections to `config/authkit.php` (exact array given in Component 1).
4. Replace `routes/authkit-laravel.php`'s commented placeholder with the three named routes, `Route::middleware('web')->group(...)`, gated by `config('authkit.routes.enabled')`.
5. Wire `AuthKitStateMismatchException` to render as a redirect back to `authkit.login` with a flashed error (via a `render()` method on the exception, or an app-level exception-mapping — keep it simple: `render()` returning `redirect()->route('authkit.login')->withErrors(['authkit' => 'Login expired or was tampered with, please try again.'])`).

**Feedback loop**:

- **Playground**: `tests/Feature/AuthenticationFlowTest.php` against a running `emulate` instance (Phase 1's helper boots it).
- **Experiment**: full login → callback → assert `Auth::guard('workos')->check()` is true and `Auth::guard('workos')->user()->workos_id` matches the emulate-seeded user; replay the same `code`/`state` a second time → assert failure (state already consumed); logout → assert the cookie is cleared and the response redirects to WorkOS's logout URL host.
- **Check command**: `vendor/bin/pest --filter=AuthenticationFlow`

### 5. `authkit.session` Refresh Middleware — Single-Flight Refresh

**Pattern to follow**: none in-repo; the concurrency-control shape is a standard cache-lock-plus-shared-result pattern (`Illuminate\Support\Facades\Cache::lock()`).

**Overview**: On every request through the `authkit.session` middleware, if the resolved claims are near or at expiry, exactly one concurrent request performs the actual `SessionManager::refresh()` call; every other concurrent request either picks up that request's freshly-sealed cookie from a shared short-TTL cache entry, or (if the lock can't be acquired within a bounded wait) proceeds using its still-valid-for-this-request claims without refreshing, or — if the token is already hard-expired and no fresh result shows up in time — redirects to `authkit.login`.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use Illuminate\Support\Facades\Cache;
use WorkOS\SessionManager;

enum RefreshStatus
{
    case Refreshed;
    case ProceedWithExisting;
    case HardExpired;
}

final readonly class RefreshOutcome
{
    public function __construct(
        public RefreshStatus $status,
        public ?string $sealedCookie = null,
    ) {}
}

final readonly class SessionRefresher
{
    public function __construct(
        private SessionManager $sessionManager,
        private int $lockTtlSeconds,
        private int $lockWaitSeconds,
    ) {}

    public function refresh(string $sealedCookie, string $sessionId, string $cookiePassword, string $clientId): RefreshOutcome
    {
        $resultKey = "authkit:refresh-result:{$sessionId}";

        $cached = Cache::get($resultKey);
        if (is_string($cached)) {
            return new RefreshOutcome(RefreshStatus::Refreshed, $cached);
        }

        $lock = Cache::lock("authkit:refresh-lock:{$sessionId}", $this->lockTtlSeconds);

        if (! $lock->block($this->lockWaitSeconds)) {
            // Someone else is refreshing and took longer than we're willing to wait.
            // Caller decides ProceedWithExisting vs HardExpired based on remaining exp budget.
            return new RefreshOutcome(RefreshStatus::ProceedWithExisting);
        }

        try {
            // Re-check: the lock holder before us may have already finished.
            $cached = Cache::get($resultKey);
            if (is_string($cached)) {
                return new RefreshOutcome(RefreshStatus::Refreshed, $cached);
            }

            $result = $this->sessionManager->refresh($sealedCookie, $cookiePassword, $clientId);
            if (! $result['authenticated']) {
                return new RefreshOutcome(RefreshStatus::HardExpired);
            }

            Cache::put($resultKey, $result['sealed_session'], now()->addSeconds($this->lockTtlSeconds * 2));

            return new RefreshOutcome(RefreshStatus::Refreshed, $result['sealed_session']);
        } finally {
            $lock->release();
        }
    }
}
```

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Auth\RefreshStatus;
use Authkit\Authkit\Auth\SessionRefresher;
use Authkit\Authkit\Events\SessionCookieOversized;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class RefreshWorkosSession
{
    public function __construct(private readonly SessionRefresher $refresher) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('workos');
        $user = $guard->user();

        $newCookie = null;

        if ($user !== null && method_exists($user, 'claims') && $user->claims() !== null) {
            $claims = $user->claims();
            $threshold = (int) config('authkit.session.refresh_before_seconds', 60);

            if ($claims->secondsUntilExpiry() <= $threshold) {
                $outcome = $this->refresher->refresh(
                    sealedCookie: (string) $request->cookie((string) config('authkit.session.cookie')),
                    sessionId: $claims->sessionId,
                    cookiePassword: (string) config('authkit.cookie_password'),
                    clientId: (string) config('authkit.client_id'),
                );

                if ($outcome->status === RefreshStatus::HardExpired && $claims->secondsUntilExpiry() <= 0) {
                    return redirect()->route('authkit.login')->withCookie(
                        Cookie::forget((string) config('authkit.session.cookie')),
                    );
                }

                if ($outcome->status === RefreshStatus::Refreshed && $outcome->sealedCookie !== null) {
                    $newCookie = $outcome->sealedCookie;
                    $this->warnIfOversized($newCookie);
                }
                // ProceedWithExisting (or a HardExpired result while still inside the buffer
                // window): fall through and let this one request use its still-valid claims.
            }
        }

        $response = $next($request);

        if ($newCookie !== null) {
            $response->headers->setCookie(new Cookie(
                name: (string) config('authkit.session.cookie'),
                value: $newCookie,
                expire: 0,
                secure: (bool) config('session.secure', true),
                httpOnly: true,
                sameSite: (string) config('authkit.session.same_site', 'lax'),
            ));
        }

        return $response;
    }

    private function warnIfOversized(string $sealedCookie): void
    {
        $max = (int) config('authkit.session.max_cookie_bytes', 3800);
        if (strlen($sealedCookie) > $max) {
            event(new SessionCookieOversized(strlen($sealedCookie), $max));
        }
    }
}
```

**Key decisions**:

- Refresh is triggered by a **buffer window before actual expiry** (default 60s), not at hard expiry. This is what makes "proceed with existing claims when the lock can't be acquired" safe: the current token is still cryptographically valid for the rest of this one request even if the refresh attempt for the *next* request hasn't landed yet.
- The shared result cache (`authkit:refresh-result:{sid}`) is checked **before** attempting the lock at all, so only the unlucky first concurrent request per refresh cycle contends for the lock — the rest pay a single cache read.
- `Cache::lock()` requires a real distributed lock store in production (redis/memcached/database) to get cross-process coordination — see Failure Modes for the degraded-but-still-safe behavior when it doesn't have one.
- Cookie attachment happens **after** `$next($request)`, on the way out — so downstream code in the same request already saw `$guard->setUser()`'s effect implicitly (the guard was resolved once, before the refresh decision; note this middleware does **not** call `$guard->setUser()` with updated claims after a refresh within the same request, since the refreshed token's claims are for the *next* request's lifetime and the current request's authorization decisions were already made against the pre-refresh, still-valid claims — re-injecting mid-request claims would be surprising and isn't required for correctness). This is a deliberate simplification, not an oversight: flagged under Key Decisions so a reviewer doesn't "fix" it into unnecessary complexity.

**Implementation steps**:

1. Create `src/Auth/SessionRefresher.php`, `src/Http/Middleware/RefreshWorkosSession.php`.
2. Register the `authkit.session` middleware alias in `AuthkitServiceProvider::boot()`.
3. Bind `SessionRefresher` in the container reading `authkit.session.lock_ttl_seconds`/`lock_wait_seconds` from config.

**Feedback loop**:

- **Playground**: `tests/Feature/SessionRefreshTest.php`, using `Cache::lock()` against the `array` cache driver (sufficient for single-process test correctness) plus a fake/MockHandler-backed `SessionManager::refresh()`.
- **Experiment**: (1) claims with 30s left, threshold 60s → refresh triggers, new cookie attached; (2) claims with 300s left → no refresh; (3) two sequential calls to `SessionRefresher::refresh()` with the same session ID and a pre-populated result cache → second call never touches `SessionManager::refresh()` (assert via a spy/mock call count of exactly 1); (4) `SessionManager::refresh()` returns `authenticated: false` → `RefreshStatus::HardExpired`; (5) sealed cookie longer than `max_cookie_bytes` → `SessionCookieOversized` dispatched.
- **Check command**: `vendor/bin/pest --filter=SessionRefresh`

### 6. `HasWorkosUser` Trait + `workos_id` Migration

**Pattern to follow**: standard Laravel model trait shape (`Illuminate\Notifiable`-style — a trait with a handful of small, focused methods).

**Overview**: Gives the consumer's User model `workos_id` awareness, a runtime (non-persisted) claims accessor the guard populates per-request, and the find-or-create + `external_id`-linking logic the callback flow needs.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Auth\AccessTokenClaims;
use WorkOS\Resource\User as WorkosUser;
use WorkOS\Service\UserManagement;

trait HasWorkosUser
{
    private ?AccessTokenClaims $workosClaims = null;

    private ?array $workosImpersonator = null;

    public function claims(): ?AccessTokenClaims
    {
        return $this->workosClaims;
    }

    public function setWorkosClaims(AccessTokenClaims $claims): void
    {
        $this->workosClaims = $claims;
    }

    public function impersonator(): ?array
    {
        return $this->workosImpersonator;
    }

    public function setWorkosImpersonator(?array $impersonator): void
    {
        $this->workosImpersonator = $impersonator;
    }

    public static function findOrCreateForWorkosUser(WorkosUser $workosUser): static
    {
        /** @var static|null $user */
        $user = static::query()->firstWhere('workos_id', $workosUser->id);

        if ($user === null) {
            // Upgrade path: a pre-existing local account with a matching email
            // gets linked rather than duplicated.
            $user = static::query()->firstWhere('email', $workosUser->email);
        }

        if ($user === null) {
            $user = new static();
            $user->email = $workosUser->email;
            $user->name = $workosUser->name ?? trim(($workosUser->firstName ?? '').' '.($workosUser->lastName ?? ''));
        }

        $user->workos_id = $workosUser->id;
        $user->save();

        if ($workosUser->externalId !== (string) $user->getKey()) {
            app(UserManagement::class)->updateUser(id: $workosUser->id, externalId: (string) $user->getKey());
        }

        return $user;
    }
}
```

**Key decisions**:

- `$workosClaims`/`$workosImpersonator` are plain declared properties, not Eloquent attributes — they never touch `$attributes`/persistence, avoiding any collision with Eloquent's `__get`/`__set` magic (a directly declared property short-circuits that).
- `updateUser(externalId: ...)` is skipped when WorkOS already reports the correct `external_id` — avoids an API write (and rate-limit consumption) on every single login, not just the first.
- Email-match fallback exists for the "pre-existing local account, first WorkOS login" upgrade path, matching what most adopting apps actually need; **no** de-duplication/merge logic beyond that single fallback — if two local accounts already share conflicting state, this trait doesn't try to reconcile them; that's out of scope.
- Trait method is a `static::query()` lookup, not a repository abstraction — this package doesn't need a repository layer for one query shape.

**Implementation steps**:

1. `vendor/bin/testbench make:migration add_workos_id_to_users_table --path=database/migrations` then edit the generated stub to add a nullable, unique `workos_id` string column (see Data Model below) and register it in `AuthkitServiceProvider`'s `loadMigrationsFrom`/`publishesMigrations`.
2. Create `src/Concerns/HasWorkosUser.php`.
3. Add `use HasWorkosUser;` to `workbench/app/Models/User.php`.

**Feedback loop**:

- **Playground**: `tests/Feature/HasWorkosUserTraitTest.php` using an in-memory SQLite Testbench DB (`RefreshDatabase`) and a fake `WorkOS\Resource\User`. Suite setup: `defineDatabaseMigrations()` calls `$this->loadLaravelMigrations()` first (provides the skeleton `users` table — see File Changes note; not automatic in package suites), then loads this package's `add_workos_id` migration.
- **Experiment**: no existing local user + no email match → creates one, sets `workos_id`, calls `updateUser`; existing local user matched by email → links it (no duplicate row) and sets `external_id`; local user's `external_id` already correct → `updateUser` is **not** called (assert call count 0 via a spy).
- **Check command**: `vendor/bin/pest --filter=HasWorkosUser`

### 7. Impersonation Surfacing (`act` claim)

**Overview**: Already wired into components 1 and 3 above (`AccessTokenClaims::isImpersonated()`/`actorId`, `WorkosGuard` dispatching `Impersonating`). This subsection exists because the phase brief calls it out as its own deliverable — there is no additional class beyond the `Impersonating` event itself.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class Impersonating
{
    public function __construct(
        public Authenticatable $user,
        public string $impersonatorWorkosUserId,
        /** @var array{email: string, reason: ?string}|null */
        public ?array $impersonatorContext,
    ) {}
}
```

**Key decisions**:

- Dispatched on **every** request where the guard resolves an impersonated user, not deduplicated per-session. Deduplicating would require new local state to track "have we already notified for this `sid`" — which the canonical-state doctrine forbids adding for a phase that has no other reason to persist session bookkeeping. Downstream consumers (Phase 6's Audit Logs, most plausibly) own their own rate-limiting/deduplication if they need it.
- `impersonatorWorkosUserId` comes from the JWT `act.sub` claim (the cryptographically-asserted signal, per the phase's explicit direction); `impersonatorContext` (email/reason) comes from the SDK's `authenticate()` result's sealed `impersonator` field when present — richer but non-authoritative metadata, included because it's already sitting right there in the same authenticate() call.

**Feedback loop**: covered by component 3's `SessionSecurity` suite (dataset case: token with `act.sub` set → guard dispatches `Impersonating` with the right actor ID) — no separate loop needed.

### 8. Test Fixture: Local JWKS/JWT Forge Helper

**Overview**: `SessionSecurity` needs to forge tokens with an attacker-controlled key, an arbitrary `iss`, and corrupted bytes — none of which `emulate` can do (it signs tokens server-side with its own key and doesn't expose a "sign with this key instead" knob). This fixture owns a committed test RSA keypair and produces sealed cookies with fully controlled claims.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Tests\Fixtures;

use WorkOS\SessionManager;

final class JwtFixture
{
    private const KEY_ID = 'test-key-1';

    public static function sign(array $claimOverrides = [], ?string $signingKeyPath = null): string
    {
        $signingKeyPath ??= __DIR__.'/jwks/test-signing-key.pem';
        $privateKey = (string) file_get_contents($signingKeyPath);

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KEY_ID];
        $payload = array_merge([
            'sub' => 'user_fixture',
            'iss' => 'https://api.workos.com',
            'client_id' => 'client_fixture',
            'sid' => 'session_fixture',
            'jti' => 'jwt_fixture',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claimOverrides);

        $segments = [self::b64(json_encode($header)), self::b64(json_encode($payload))];
        $signingInput = implode('.', $segments);

        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $segments[] = self::b64($signature);

        return implode('.', $segments);
    }

    public static function jwks(): array
    {
        $publicKeyPem = (string) file_get_contents(__DIR__.'/jwks/test-signing-key.pub.pem');
        $details = openssl_pkey_get_details(openssl_pkey_get_public($publicKeyPem));

        return ['keys' => [[
            'kid' => self::KEY_ID,
            'kty' => 'RSA',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ]]];
    }

    public static function sealedCookie(string $accessToken, string $cookiePassword, string $refreshToken = 'refresh_fixture'): string
    {
        return SessionManager::sealSessionFromAuthResponse($accessToken, $refreshToken, $cookiePassword);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
```

**Key decisions**:

- Static committed keypair (`tests/Fixtures/jwks/test-signing-key.pem` + `.pub.pem`), not generated per test run — deterministic, no flakiness from key-generation timing, and the public JWKS fixture bytes stay stable across runs so assertions on cached-vs-fresh JWKS bodies are meaningful.
- "Forged signature" cases use a *second*, different committed key to sign, while the JWKS served still advertises only the first key's public half — the mismatch is what makes `openssl_verify` fail in the SDK, exactly reproducing a real forged-token attempt.
- Lives under `tests/Fixtures/`, not `tests/Feature/` or `tests/Unit/`, and is named without a `Test` suffix so Pest's discovery doesn't try to run it as a test file.

**Feedback loop**:

- **Playground**: a tiny throwaway script or a single Pest smoke test asserting `JwtFixture::sign()` produces a 3-segment string whose signature `openssl_verify`s against the fixture's own public key.
- **Experiment**: sign with the primary key → verifies against `jwks()`'s advertised key; sign with the secondary (non-advertised) key → fails verification; override `exp` to the past → downstream `SessionManager::authenticate()` rejects it.
- **Check command**: `vendor/bin/pest --filter=JwtFixture`

## Data Model

### Schema Changes

```sql
-- Migration: add_workos_id_to_users_table (via Laravel's schema builder, shown here for clarity)
ALTER TABLE users ADD COLUMN workos_id VARCHAR(255) NULL;
CREATE UNIQUE INDEX users_workos_id_unique ON users (workos_id);
```

Actual migration file (anonymous-class style, matching the existing placeholder's convention):

```php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('workos_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('workos_id');
        });
    }
};
```

Nullable because not every row need be WorkOS-linked at migration time (existing local accounts pre-adoption); unique because it's the projection key the guard's `retrieveByCredentials(['workos_id' => ...])` lookup and the trait's find-or-create both rely on being unambiguous.

### State Shape

No new tables. `workos_id` is the only new column, matching the contract's "declared projections" whitelist (user link). No session/claims state is persisted — `AccessTokenClaims` lives only as an in-memory, per-request object attached to the resolved user model.

## API Design

### New Routes

| Method | Path (default prefix `authkit`) | Name | Description |
| --- | --- | --- | --- |
| `GET` | `/authkit/login` | `authkit.login` | Builds the PKCE authorization URL and redirects the browser to WorkOS. |
| `GET` | `/authkit/callback` | `authkit.callback` | WorkOS's redirect target; exchanges `code`, seals the session, sets the cookie, redirects to the intended URL. |
| `POST` | `/authkit/logout` | `authkit.logout` | Builds the WorkOS logout URL, clears the local cookie, redirects to WorkOS (which redirects back to `return_to`). |

`logout` is `POST` (not `GET`) specifically so it sits behind Laravel's default `VerifyCsrfToken` middleware in the `web` group — a GET-triggerable logout is a minor but real CSRF surface (`<img src="/logout">` forcing a victim's session to end).

### Request/Response Examples

```
GET /authkit/login
  → 302 Found
    Location: https://api.workos.com/user_management/authorize?client_id=...&redirect_uri=...&response_type=code&code_challenge=...&code_challenge_method=S256&state=...

GET /authkit/callback?code=01ABC...&state=deadbeef...
  → 302 Found
    Set-Cookie: authkit_session=<sealed>; HttpOnly; Secure; SameSite=Lax
    Location: /dashboard   (the pre-login intended URL, or "/")

POST /authkit/logout
  → 302 Found
    Set-Cookie: authkit_session=; Max-Age=0
    Location: https://api.workos.com/user_management/sessions/logout?session_id=...&return_to=...
```

## Testing Requirements

### Unit Tests

| Test File | Coverage |
| --- | --- |
| `tests/Unit/AccessTokenClaimsTest.php` | Payload → DTO mapping, missing-optional-claim defaults, `isImpersonated()`, `secondsUntilExpiry()`. |

**Key test cases**:

- Full claim set decodes correctly.
- Missing `role`/`org_id`/`act` decode to `null`/`[]` without throwing.
- `act.sub` present → `isImpersonated()` true, `actorId` matches.
- `JwtClaimsValidator::validate()` true only when both `iss` and `client_id` match; false on either mismatch alone.

### Integration/Feature Tests

| Test File | Coverage | Test path |
| --- | --- | --- |
| `tests/Feature/SessionSecurityTest.php` | Forged signature, expired token, wrong `iss`, wrong `client_id`, tampered cookie bytes, missing cookie, malformed cookie. | MockHandler + `JwtFixture` (local JWKS) |
| `tests/Feature/AuthenticationFlowTest.php` | Login redirect shape, full callback exchange, guard resolution, replayed `state` rejected, logout clears cookie + redirects. | emulate |
| `tests/Feature/SessionRefreshTest.php` | Near-expiry refresh, hard-expiry redirect to `authkit.login`, single-flight lock (refresh call count == 1 across simulated concurrent callers), oversized-cookie event. | MockHandler (refresh outcomes are fully controlled, not network-dependent) |
| `tests/Feature/HasWorkosUserTraitTest.php` | Find-or-create by `workos_id`, email-match linking fallback, idempotent `external_id` update. Setup: `defineDatabaseMigrations()` → `$this->loadLaravelMigrations()` + package `add_workos_id` migration (skeleton users table is not loaded automatically in package suites). | MockHandler (for the `updateUser` call assertion) |

**Key scenarios**:

- Happy path: login → callback → `Auth::guard('workos')->user()->workos_id` set, `Login` event dispatched with the right user.
- `SessionSecurity`: every case in the table above independently returns `Auth::guard('workos')->user() === null` (or the equivalent `authenticated: false` at the `SessionManager` layer for cases the SDK itself catches) — assert per-case, not just "the suite is green."
- Single-flight: dispatch the refresher twice for the same `sessionId` with a pre-seeded result cache entry; assert the underlying `SessionManager::refresh()` fake is invoked exactly once total, not twice.
- emulate's refresh-tokens-always-rotate behavior (flagged in the context brief as stricter than confirmed prod) means `AuthenticationFlowTest` and `SessionRefreshTest`'s emulate-touching cases must always capture and use the freshly-returned `refresh_token` after each refresh call — never assert that reusing a pre-refresh refresh token succeeds against emulate, since that assumption may not transfer to production and isn't testable there anyway.

### Manual Testing

- [ ] `composer serve` against a locally running `npx @workos/emulate`; click through login → land on an emulate-hosted consent/redirect → land back on `/dashboard` authenticated.
- [ ] Inspect the `Set-Cookie` header in browser devtools: confirm `HttpOnly`, `Secure` (if serving over HTTPS locally) or absent-but-documented (if plain HTTP dev), `SameSite=Lax`, and eyeball the byte length is well under 4KB for a normal test user with a handful of permissions.
- [ ] Trigger logout, confirm the cookie is gone and the browser lands back on `/` after WorkOS's own logout redirect.

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| JWKS fetch | **JWKS outage collapses to universal sign-out** | WorkOS's JWKS endpoint is unreachable/5xx while sessions are still otherwise valid | Every request appears to have an `invalid_jwt` token; all active sessions look forged | `JwksGraceCache` middleware serves the last successfully-fetched JWKS body (up to `jwks_grace_ttl_seconds`, default 24h) so `authenticate()` never sees the outage; `JwksServedStale` event dispatched for operator visibility |
| JWKS grace cache | **Stale key served past a real rotation** | WorkOS rotates its signing key *during* a prolonged outage, and the grace cache keeps serving the old public key for up to 24h | A token signed with the *new* key fails verification against the stale cached key until the outage clears and a live fetch succeeds | Accepted, named tradeoff — 24h is a deliberately bounded ceiling, not indefinite; shortening it trades outage resilience for faster recovery from this specific edge case; not engineered further this phase |
| `WorkosGuard` | **Cross-application token replay** | A WorkOS environment issues tokens for multiple `client_id`s (applications) sharing one JWKS signing key; a validly-signed, non-expired token minted for a *different* application is presented to this app's guard | Without the added check, the SDK's signature+exp-only verification would accept it — an auth bypass across applications sharing an environment | `JwtClaimsValidator` rejects any token whose `client_id` claim doesn't match `authkit.jwt.audience` (defaults to `authkit.client_id`), even though the signature verifies cleanly |
| `WorkosGuard` | **Wrong/stale `iss` configuration** | `authkit.jwt.issuer`'s default (`https://api.workos.com`) doesn't match the actual `iss` on tokens from an environment using a custom AuthKit auth domain | Every request is rejected as unauthenticated even with perfectly valid sessions — a silent full lockout, not a crash | Phase 1's empirical token audit is the source of truth for the real value; config-driven design means fixing it is a one-line env-var change, not a code change — flagged as an Open Item until that audit's numbers are available |
| `WorkosGuard` / `JwtClaimsValidator` / form requests | **Config missing/empty fails late and opaquely** | `authkit.cookie_password`, `authkit.client_id`, or `authkit.jwt.issuer` is unset or empty at runtime (missing env var, `.env` typo, `config:cache` run before the var was set) | Nothing in `WorkosGuard`, `JwtClaimsValidator`, or the three form requests validates these values before use today; an empty `cookie_password` surfaces only as an opaque sodium/decrypt failure deep inside `SessionManager::unsealData()`, and an empty `client_id`/`issuer` surfaces as a silent full lockout indistinguishable from the row above, with nothing pointing at the actual missing config key | Per the shared conventions' fail-fast prompt: validate these keys at first use (e.g. the container bindings in `AuthkitServiceProvider::register()`) and throw an actionable exception naming the specific missing config key, rather than letting the failure surface as a cryptographic error several layers down. Named here as a requirement; not engineered as a separate validation subsystem in this phase |
| `AccessTokenClaims` (zero-HTTP claims doctrine) | **Stale JWT claims between refreshes** | A role, permission, or org membership is revoked or changed in WorkOS while a user's access token is still inside its validity window — up to the full token lifetime, not bounded by `refresh_before_seconds`'s 60s buffer, since that setting only controls *when* a refresh is attempted, not how fresh claims are in between attempts | The guard-resolved claims — and any zero-HTTP RBAC check built on them, per the contract's claims doctrine — keep reflecting the old, now-revoked authorization state until the access token's own `exp` forces a refresh | Accepted, named tradeoff — bounded staleness is the deliberate cost of zero-HTTP claims-based authorization, not a bug to engineer around; Phase 5's Gate integration inherits the same bound and should not "fix" it with a network call per authorization decision. Apps needing a tighter revocation window can lower `refresh_before_seconds` (a bounded improvement only) or use FGA's explicit Check API calls (Phase 5), which read live rather than from cached claims |
| `WorkosGuard` | **Orphaned session (valid claims, no local user)** | Local `users` row deleted or DB reset while a browser still holds a cryptographically valid WorkOS session cookie; or read-replica lag between the callback's write and the guard's read | `retrieveByCredentials` returns null; naive downstream code calling methods on a null user would crash | Guard returns `null` from `user()` — identical to "no cookie at all" from the caller's perspective; `auth` middleware's normal unauthenticated handling applies unchanged. Documented because it's surprising: a cryptographically valid WorkOS session can still mean "guest" in Laravel |
| `SessionRefresher` | **Concurrent refresh race** | Multiple in-flight requests from the same browser all observe a near-expiry token simultaneously (parallel XHR/asset requests, duplicate tabs) | Without coordination, two requests could both call `SessionManager::refresh()`; since refresh tokens rotate on every use, the second call's refresh token is already invalidated by the first's success | `Cache::lock()` keyed on session ID serializes actual refresh calls; a shared short-TTL result cache lets losing requests reuse the winner's freshly-sealed cookie without a second network call |
| `SessionRefresher` | **Lock store without real cross-process locking** | Production deployment uses the `array` cache driver, or per-server local `file` cache across multiple app servers with no shared cache backend | Each server/process gets its own independent lock — single-flight coordination silently degrades to "advisory only" across the fleet | Not a security issue (WorkOS's own token rotation still makes each individual refresh call safe/correct in isolation; a losing concurrent call just gets a legitimate "invalid refresh token" response, handled as `ProceedWithExisting`) — documented as a required-for-full-benefit dependency: production deployments need a shared cache store (redis/memcached/database) for this optimization to actually coordinate across servers |
| `SessionRefresher` / middleware | **Hard expiry with no lock available** | Token is already past hard `exp` (buffer window missed entirely — e.g. a long-idle browser tab), and the refresh lock can't be acquired within `lock_wait_seconds` | Request has no valid claims to proceed with and no fresh cookie to attach | Redirect to `authkit.login`, clearing the stale cookie — safer than serving a request against expired claims |
| Sealed cookie | **Cookie exceeds ~4KB** | User has enough `permissions`/`feature_flags`/`roles` claims that the access token itself (embedded in the sealed cookie) grows past what browsers reliably store in one cookie | Browsers may silently truncate or drop the cookie; the app believes it set a session that the browser actually rejected, producing confusing intermittent "logged out" behavior | Detected via byte-length check post-seal; `SessionCookieOversized` event dispatched for operator visibility. Not engineered around at this layer — WorkOS's own documented mitigation (drop the bloating claim from the JWT template, fall back to a runtime API poll) is a Dashboard-side change, out of scope for this phase; acknowledged, not fixed in code |
| Sealed cookie | **Tampered/forged cookie bytes** | An attacker (or a corrupted browser storage write) flips bytes in the sealed cookie value | AEAD authentication tag check inside `SessionManager::unsealData()` fails | Caught by the SDK itself (`invalid_session_cookie`/`invalid_jwt` reasons) — guard treats as unauthenticated; no separate handling needed here beyond not crashing on the exception, which the guard's `try/catch` already covers |
| `SessionManager` (SDK, not this phase's code) | **Clock skew rejects a technically-valid session right at the boundary** | Server clock drifts from WorkOS's issuing clock; the SDK's `exp < time()` check has no leeway/`nbf` support | Sessions can be rejected a few seconds early (or accepted a few seconds "late") right around expiry | Acknowledged, not fixed here — this is SDK behavior, not this phase's code; an upstream SDK contribution is already tracked as a contract Stretch item. Operational mitigation: keep app servers NTP-synced |
| Login/Callback | **Stateless app has no session driver on these routes** | An app disables sessions entirely, or routes the login/callback paths through an API-only middleware group without session support | `AuthKitLoginRequest` has nowhere to stash `code_verifier`/`state`; the callback can't verify state | Documented hard requirement: the `web` middleware group (or any group with session middleware) must wrap these routes; this phase's own route registration already does this by default |
| Callback | **`state` replay or mismatch** | An attacker captures a `code`/`state` pair (e.g. via referrer leakage) and replays the callback URL, or a stale/duplicated browser tab submits an old callback link | Without a check, a captured authorization code could be exchanged by someone other than the original requester | `hash_equals()` comparison against the *pulled* (single-use) session value; the state is consumed by `pull()` so even the legitimate browser tab can't accidentally double-submit successfully |
| `HasWorkosUser::findOrCreateForWorkosUser` | **Redundant `external_id` writes** | Naively calling `updateUser(externalId: ...)` on every single login rather than only when it's wrong | Unnecessary WorkOS API traffic on every login, consuming rate-limit budget for no behavior change | Guarded by an equality check against the already-fetched `WorkOS\Resource\User::$externalId` before calling `updateUser` |
| Logout | **Logging out an already-invalid session** | Cookie present but already expired/tampered by the time logout is requested | `SessionManager::authenticate()` inside `AuthKitLogoutRequest` returns `authenticated: false`, so there's no `session_id` to build a WorkOS logout URL from | Falls back to just clearing the local cookie and redirecting to `returnTo`/`/` directly — no WorkOS round-trip attempted for a session that isn't valid anyway |

## Validation Commands

```bash
# Static analysis
composer analyse

# Formatting check
composer lint:check

# Type coverage (must stay 100)
composer test:types

# This phase's fast inner loop
vendor/bin/pest --filter=SessionSecurity

# This phase's slower, emulate-backed confirmatory loop
vendor/bin/pest --filter=AuthenticationFlow

# Full phase-relevant suite
vendor/bin/pest --filter=SessionSecurity --filter=AuthenticationFlow --filter=SessionRefresh --filter=HasWorkosUser --filter=AccessTokenClaims --filter=JwksGraceCache --filter=JwtFixture

# No env() reads anywhere outside config files
grep -rn 'env(' src/ --include='*.php'   # must exit 1 (no matches)

# Full validation — must be green before this phase is considered done
composer test
```

## Rollout Considerations

No feature flags — this phase either lands green on `composer test` or it doesn't ship. Since this is the auth core, there is no partial/gradual rollout within the package itself; the consumer app adopting it is an all-or-nothing switch from whatever guard they used before to `workos`.

- **Monitoring**: `JwksServedStale` and `SessionCookieOversized` are Laravel events specifically so a consuming app (or a later phase's audit-log wiring) can observe them — this phase does not add its own logging/alerting infrastructure beyond dispatching the events.
- **Rollback plan**: `git revert` of this phase's commit(s), or reset to the contract's recorded anchor (`git reset --hard 4d04d0b`) if the whole express run needs to unwind.

## Open Items

- [ ] **Confirm the real `iss` value from Phase 1's empirical token audit.** This spec's default (`https://api.workos.com`) is the SDK's own `baseUrl` default, not a confirmed value — environments using a custom AuthKit auth domain may issue a different `iss`. Update `config/authkit.php`'s default (or require it be always explicitly set) once the audit lands.
- [ ] **Confirm whether an `aud` claim exists on AuthKit access tokens at all.** The context brief's live-docs claim inventory omits `aud` entirely, listing `client_id` instead — this spec validates `client_id` as the audience-equivalent. If Phase 1's audit finds a genuine `aud` claim too, extend `JwtClaimsValidator` to check it as well (additive, non-breaking).
- [ ] **Wire `JwksGraceCache::middleware()` into the shared `\WorkOS\WorkOS` singleton's Guzzle `HandlerStack`.** This phase owns the middleware class; Phase 1 owns the client's construction. Whoever implements this phase needs to either find Phase 1's handler-stack extension point or add the one-line `$stack->push(...)` call to wherever that singleton is built.
- [ ] **`workbench/app/Providers/WorkbenchServiceProvider.php`'s guard/provider wiring** is this phase's minimum viable version for interactive `composer serve` use; Phase 13's full workbench build-out may restructure or replace it — don't treat this phase's workbench edits as final.

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
