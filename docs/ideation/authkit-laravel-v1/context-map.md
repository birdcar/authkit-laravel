# Context Map — Phase 13: Integration, Quickstart & Release Readiness

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 11/12/6/10/8/4/9/7/5/3 maps retained below._

## Phase 13 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-13.md carries full bodies for all 12 components. Top-of-document gate on `spec-phase-3.md` is SATISFIED: spec-phase-3.md exists AND Phase 3 shipped (commit 952e344) — Components 1/2/6/7 reconcile against real code below. All prerequisite phases 1–12 are committed with recorded `composer test` evidence in progress.md. Baseline green this session: 514 tests / 1625 assertions, PHPStan level 7 clean, Pint clean, 100% type coverage. |
| Pattern familiarity | READY | Read `PipesController` (workbench controller shape: final, abort_unless instanceof User narrowing, package-facade-only), workbench routes (auth:workos middleware convention, query-param org), `WorkbenchServiceProvider` (register(): guard/provider/org-model config; boot(): loadRoutesFrom ai.php), `TestCase` (config pinning incl. jwt.issuer), `AuthenticationFlowTest` (login flow mechanics + emulate smoke), `AuthorizationMockTest` (can()-via-route-closure + sealed-cookie pattern), `EmulateServer` (port+seedPath ctor, isAvailable() false on Windows), `UsesWorkosMockHandler`, ArchTest. |
| Dependency awareness | READY | Every Assumed-Interfaces row reconciled (see table below). Emulate 0.6.0 dist probed: seed schema supports organizations[].memberships (by email + role), roles[] (slug/type/permissions), permissions[], invitations, connectApplications, apiKeys, jwtTemplate; access token mints role/roles/permissions/org_id/feature_flags/entitlements claims for org-scoped sessions; single-active-membership users auto-scope; GET /user_management/authorize (non-interactive) resolves login_hint-or-first-user and 302s back with code+state; PKCE verified at authenticate; PUT users/:id + GET users/external_id/:x exist; formatOrganization carries metadata+domains (SDK-parseable). |
| Edge case coverage | READY | Redirect URI must be localhost (assertLocalRedirectUri) → acceptance test pins authkit.redirect_uri to http://localhost/authkit/callback. TestCase pins authkit.jwt.issuer to JwtFixture::ISSUER → acceptance test clears it (issuer check is opt-in; emulate mints iss = its base URL). aud check reads client_id claim = the client_id the login flow sends (JwtFixture::CLIENT_ID) — passes unchanged. Emulate rotates refresh tokens per authenticate — acceptance asserts only durable identifiers per spec failure mode. grep exit-code contract: 1 = pass, 0/2 = fail. base_path() in package tests points at the Testbench SKELETON, not this repo → the workbench grep test resolves the real workbench dir via dirname(__DIR__). |
| Test strategy | READY | `vendor/bin/pest --filter={Acceptance,ProjectionBoundary,IdiomCoverage,WorkbenchZeroSdkReference}` inner loops; acceptance emulate on port 4189 (free; 4100/4190-4199/4321 taken) with a dedicated seed file (precedent: api-keys/depth fixtures) because the SHARED fixture must stay role/permission-free — AuthorizationTest\'s emulate smoke asserts EMPTY env-role+permission lists (emulate\'s non-empty payloads are SDK-unparseable, Phase 5 finding). Workbench web routes ARE loaded in package tests (testbench.yaml discovers.web: true) so demo routes are directly assertable. |

## Phase 13 Reconciliation Against Landed Phases (decisive)

- **Route names**: `authkit.login/callback/logout/switch-org` + `authkit.webhooks` confirmed. Well-known route is **`authkit.oauth-protected-resource`** (NOT the spec\'s assumed `authkit.mcp.protected-resource`).
- **Middleware**: workbench convention is **`auth:workos`** guard middleware (Pipes precedent), not a bare `workos` alias (none exists). Aliases landed: authkit.session/org/webhook/mcp.
- **Claims access**: `WorkosGuard implements HasAccessTokenClaims` → `accessTokenClaims(): ?array` (keys: sub, org_id, role, roles, permissions, feature_flags, entitlements). Current org: `$request->organization()` macro or `Authkit::currentOrganization()`. Dashboard reads these, not request attributes.
- **API keys**: `HasApiKeys::createApiKey(name, organization, ?permissions, ?expiresAt, ?idempotencyKey): ApiKeyCreated` (raw value on `->value`), `revokeApiKey(id)`, NOT the spec\'s `issueApiKey()`. User keys REQUIRE an organization.
- **Vault**: facade is `Vault::set(array $keyContext, string $name, string $value)` / `get(name): VaultObject` (NOT put/get(string,string)). Filesystem driver config: `['driver' => 'vault', 'disk' => '<inner>']` (NOT `wraps`). **Vaulted-cast demo model already exists (`VaultDemoRecord`, Phase 9)** → Post/secret_note migration+factory changes from the spec\'s File Changes are DROPPED (the spec\'s own key decision was "reuse the existing demo model, don\'t introduce a new one" — the landed one is VaultDemoRecord). Posts table has no user_id → no `->for($user)` anywhere.
- **Connect**: `Authkit::connect()->listApplications()` confirmed.
- **Events**: `GenericWorkosEvent` at `Events\GenericWorkosEvent` (type/id/payload/occurredAt); typed events at `Events\Workos\*` with public array $payload. Workbench listeners are hand-written (Phase 10/11 precedent: no artisan generators inside workbench) and registered via Event::listen in WorkbenchServiceProvider::boot(). demo:trigger-event uses `Organization::create()` → HasWorkosOrganization observer → organization.created event (pure package API).
- **Feature flags**: driver registered as Pennant store `workos` (pennant.stores.workos injected at boot). Demo uses `Feature::store('workos')` explicitly. Claim key: `feature_flags`.
- **Acceptance org projection**: the local org row + workos_memberships row come from `UpsertOrganizationAndMembershipFromLogin` (Login listener) reading org_id claim; `$user->organizations()` (BelongsToWorkosOrganizations) joins workos_memberships on WorkOS ids. `$user->can()` is asserted through an HTTP request with the sealed cookie (ClaimsGateHook reads guard claims; bare actingAs carries no claims — AuthorizationMockTest precedent).
- **`Authkit::users()` does not exist** — external-id linkage asserted via the container-bound `WorkOS\Service\UserManagement` (`getUserByExternalId`), the landed test-side accessor pattern.
- **ProjectionBoundary whitelist (landed names)**: users, organizations, workos_organization_domains, workos_memberships, **workos_event_cursor** (singular, Phase 4 migration). No local table carries `external_id`; detection keys on either column name as spec\'d.
- **IdiomCoverage**: route macro IS confirmed → assert `Route::hasMacro('workosWebhooks')` + `Request::hasMacro('organization')`. No custom Blade directive shipped by ANY phase (grep-verified) and none is named by any spec — the contract idiom "Gate/Blade directives" landed as the two Gate::before hooks; asserted behaviorally via `Gate::forUser(WorkosApiKeyActor)` (no fabricated directive name, per the spec\'s own no-tautology rule).
- **CI emulate**: the landed harness boots emulate PER-SUITE via EmulateServer (npx, per-suite ports/seeds) — ubuntu cells already exercise emulate-backed suites in CI; Windows is excluded by EmulateServer::isAvailable() DESIGN. The spec\'s external boot + WORKOS_BASE_URL env would be dead config (nothing reads it). Reconciliation: add an ubuntu-only npx cache-priming step so per-suite cold boots can\'t eat the 60s health budget (spec Open Item 6\'s "simplify to reuse the same mechanism" branch).
- **quickstart.md exists** (Phase 4 seed, explicitly deferring to this phase) — rewritten to ≤5 numbered steps with Phase 4\'s events/webhooks/dev-wiring content retained as unnumbered reference sections (grep counts only `^[0-9]+\.`).
- **feature_list.json**: statuses written as "done" WITH evidence — every phase 1–12 has a commit + composer-test evidence line in progress.md (verified this session).

---

# Context Map — Phase 11: Pipes

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 12/6/10/8/4/9/7/5/3 maps retained below._

## Phase 11 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-11.md carries full class bodies for all 6 components + file-change tables. §2's scope-traceability flag resolved per its own directive (below): `connect()`/`disconnect()`/`import()` + `PipesInvalidImportException` are trimmed — `contract-data.json` `scope.mvp[14]` and `execution.phases[10].notes` both name exactly three capabilities, no ratifying artifact exists in-repo, and Phase 8's `expireApiKey` CUT is the direct precedent for the spec's "absent that ratification, trim" branch. |
| Pattern familiarity | READY | Read `Authkit` (app()-resolved accessors, no constructor by design), `InvitationManager` (manager pattern: constructor-inject `Contracts\WorkosClientManager`, `->client()->...` per call), `Facades/Authkit` @method docblock, `InteractsWithWorkosApiKeys` (getAttribute('workos_id') + is_string guard + descriptive RuntimeException; per-call client resolution in traits), `HasWorkosUser` (current shape), `GroupsTest` (MockHandler wire-assertion style, describe wrapper, helper-fixture functions), `HasWorkosUserTraitTest` (migratePackageDatabase + UserFactory), workbench routes conventions (depth-extensions query-param org precedent). |
| Dependency awareness | READY | Vendored SDK v9.1 verified 1:1: `Pipes::{listUserDataProviders, getAccessToken, authorizeDataIntegration}` + `PipesProvider::{listOrganizationDataIntegrationConfigurations, updateOrganizationDataIntegrationConfiguration}`; `WorkOS::pipes()`/`pipesProvider()` accessors (WorkOS.php:126,161). List rows carry `DataIntegrationsListResponseDataConnectedAccount` (NOT `ConnectedAccount` — same field names, different class); `UnprocessableEntityException` exists (unused after trim). New files: zero external consumers; modified files additive-only. |
| Edge case coverage | READY | Fixture minimums from `fromArray`: list item requires id/name/slug/integration_type/credentials_type/ownership(`userland_user`\|`organization`)/created_at/updated_at; connected_account requires id/scopes/state/created_at/updated_at; access_token requires access_token/scopes/missing_scopes (expires_at optional → `?DateTimeImmutable`); config response requires id/organization_id/slug/name/enabled/config/created_at/updated_at; credentials requires credentials_type(`shared`\|`custom`\|`organization`)/has_credentials/redirect_uri. accessToken branches: `error=not_installed`, `error=needs_reauthorization` (hard), `active` with non-empty `missing_scopes` (soft), `active` with null token payload (defensive RuntimeException). |
| Test strategy | READY | MockHandler-only per Decision #2 (Pipes named explicitly; emulate has zero Pipes coverage — no probing needed). `vendor/bin/pest --filter=Pipes` with `describe('Pipes')` wrapper; `UsesWorkosMockHandler::fakeWorkosResponses()` + `workosRequestHistory` wire assertions (GroupsTest precedent). |

## Phase 11 Reconciliation Against Landed Phases (decisive)

- **Scope trim per spec §2's own directive**: the spec flags `connect()`/`disconnect()`/`import()` (+ its 422 exception, Failure Mode 3, Open Item 3) as a genuine contract-scope expansion needing conscious approver ratification, and directs that absent ratification Component 1 trims to the four contract-traceable methods. This headless run has no approver; both canonical scope statements name exactly `connectedAccounts` / access-token fetch / org provider-config passthrough. → Ship `connectedAccounts()`, `accessToken()`, `providerConfig()`, `configureProvider()`; defer the other three (Phase 8 `expireApiKey` CUT precedent). Missing-scope surfacing (Failure Mode 2) is NOT part of the flagged expansion — the reauth URL is fetched eagerly on the exceptional branch via a private `authorizeDataIntegration` call.
- **Open Item 1 (client accessor)**: `Contracts\WorkosClientManager::client(): \WorkOS\WorkOS` — constructor-inject the CONTRACT (InvitationManager precedent), never `Support\WorkosClientManager`.
- **Open Item 2 (MockHandler injection)**: no `WorkosClientManager::fake()` exists — tests use `UsesWorkosMockHandler::fakeWorkosResponses(array)` (binds a HandlerStack instance + forgets the manager singleton). The spec's `beforeEach` hook is replaced wholesale.
- **Open Item 3 (import idempotency)**: moot — `import()` deferred with the trim.
- **Open Item 4 (trait path)**: `src/Concerns/HasWorkosUser.php` confirmed at the assumed namespace. No `requireWorkosId()` guard landed — use `getAttribute('workos_id')` + `is_string` narrowing + descriptive RuntimeException (`InteractsWithWorkosApiKeys::workosApiKeyOwnerId` precedent).
- **Provider registration: NO provider change.** Phase 12's managers (Invitation/JwtTemplate/CorsOrigin/Group) have no bindings in `AuthkitServiceProvider` — auto-wired non-singleton is the landed pattern, and spec §6's `singleton()` would pin the pre-`fakeWorkosResponses()` client manager (Phase 9 bind-not-singleton rationale). `PipesManager` resolves by auto-wiring; the accessor + facade @method are the only registration surface.
- **DTO source types**: `fromProvider()` maps `DataIntegrationsListResponseData` (whose `connectedAccount` is `DataIntegrationsListResponseDataConnectedAccount`); the spec's `fromConnectedAccount(ConnectedAccount)` factory is import-only and drops with the trim. Null-account guard inside `fromProvider` (level 7 nullable-property access).
- **Workbench**: no artisan generators inside `workbench/` (Phase 10 precedent) — write `PipesController` by hand under `workbench/app/Http/Controllers/` (new directory). `workbench/app` IS PHPStan-analysed at level 7 → `abort_unless($user instanceof User, 401)` narrowing before trait-method calls; `workbench/routes` is NOT analysed. Demo routes reshaped after trim: `pipes.index` (trait list), `pipes.token` (trait pipe() + reauth-redirect demo), `pipes.providers` / `pipes.providers.update` (org passthrough, query-param org per depth-extensions precedent).

## Phase 11 Build Notes

- Package enums `ConnectedAccountState`/`AuthMethod` seal the SDK enums out of every public signature; `ConnectedAccountState::fromSdk()` takes the SDK response enum only (import-side `PipeConnectedAccountState` mapping dropped with `import()`). Pint global_namespace_import → alias `use WorkOS\Resource\ConnectedAccountState as SdkConnectedAccountState`.
- PSR-7 Response bodies are consumed on first read — construct a fresh Response per queued fixture (never reuse one instance for two queue slots).
- Wire paths: `GET /user_management/users/{id}/data_providers`, `POST /data-integrations/{slug}/token`, `POST /data-integrations/{slug}/authorize`, `GET|PUT /organizations/{org}/data_integration_configurations[/{slug}]`.
- `$response->accessToken?->missingScopes ?? []` narrows cleanly; `scopes` docblocks ride the SDK's `array<string>` annotations.
- Test helper functions are Pest-global — prefix with `pipes*` to avoid redeclaration collisions.

---

# Context Map — Phase 12: Depth Extensions (Full Tier)

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 6/10/8/4/9/7/5/3 maps retained below._

## Phase 12 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-12.md carries full class bodies for all 7 components, file-change tables, config keys, and per-suite test cases. Reconciliation deltas against landed Phases 3/4/5 identified below (largest: `FgaManager` is really `FgaChecker` with a different public `check()` signature; `Authkit` has no constructor so accessors are container-resolved). |
| Pattern familiarity | READY | Read `Authkit` (app()-resolved accessors, "no constructor by design"), `AuthkitServiceProvider` (register/boot split, listener registration before console early-return, Repository-based config reads), `FgaChecker`, `ResourceTarget`, `ResourceManager`, `HasWorkosResource` + `Contracts\WorkosResource`, `RoleManager` (manager pattern: constructor-inject `Contracts\WorkosClientManager`), `GenericWorkosEvent`, membership typed events, `UsesWorkosMockHandler`, `EmulateServer`, `AdminPortalTest` (emulate suite shape), `AuthorizationMockTest` (fixture helpers), `ConnectTest` (`uses()->group()`), Facades/Authkit @method docblock. |
| Dependency awareness | READY | Vendored SDK v9.1 verified 1:1 against every wrapped method: `UserManagement` invitations ×7 + `listJWTTemplate`/`updateJWTTemplate` + `listCorsOrigins`/`createCorsOrigin` (NO delete — spec Deviations #1 confirmed); `Groups` ×8; `Authorization` group-role ×6 + `listResourcesForMembership` (ParentResourceById\|ByExternalId, query params) + `listMembershipsForResource`/`ByExternalId` (AuthorizationAssignment enum) + `createResource` accepts `?ParentResourceById\|ParentResourceByExternalId $parentResource`; `OrganizationMembershipService::listOrganizationMembershipGroups`. `WorkOS::groups()`/`organizationMembership()` accessors exist (WorkOS.php:166,186). `ParentResourceById{id}` / `ParentResourceByExternalId{externalId,typeSlug}` constructor shapes confirmed. |
| Edge case coverage | READY | Fixture minimums from `fromArray`: `Group` = id, organization_id, name, created_at, updated_at; `GroupRoleAssignment` = id, group_id, role{slug}, resource{id, external_id, resource_type_slug}, created_at, updated_at; `GroupRoleAssignmentList` = data[], list_metadata; `UserInvite`/`Invitation` = id, email, state, expires_at, created_at, updated_at, token, accept_invitation_url; `JWTTemplateResponse` = content, created_at, updated_at; `CORSOriginResponse` = id, origin, created_at, updated_at; `UserOrganizationMembershipBaseListData` = id, user_id, organization_id, status, directory_managed, created_at, updated_at, user{id, email, email_verified, created_at, updated_at}; `AuthorizationResource` = name, organization_id, id, external_id, resource_type_slug, created_at, updated_at. `AuthorizationCheck` = {"authorized": bool} (existing helper). |
| Test strategy | READY | Emulate 0.6.0 probed via dist route source: invitations routes are FULL and SDK-compatible (send/list/get/by_token/accept/revoke/resend); `resend` does NOT enforce pending state (drift — use revoke-on-revoked for the error case, 400 → BadRequestException); `GET /user_management/jwt_template` 404s until a template is seeded/PUT (seed key `jwtTemplate.content` exists, validator enforces template syntax); CORS has POST but NO list route → MockHandler as spec assumed; NO group routes → MockHandler. Ports 4190 (JwtTemplate) and 4194 (Invitations) free (4100/4191-4193/4195-4199/4321 taken). Dedicated seed `workos-emulate-depth.config.yaml` (pinned org id — validator allows `organizations[].id`) so the shared fixture stays untouched. `uses()->group('depth-extensions')` + `describe()` wrappers matching each spec `--filter`. |

## Phase 12 Reconciliation Against Landed Phases (decisive)

- **`FgaManager` → landed `Authkit\Authkit\Authorization\FgaChecker`** with public `check(string $permissionSlug, string $resourceExternalId, string $resourceTypeSlug, ?string $organizationMembershipId = null, ?Authenticatable $user = null, ?string $organizationId = null, ?RequestOptions $options = null): bool` — membership resolution happens inside. Per spec ("the public check() signature is unchanged"), the SDK-call tail becomes `private rawCheck(string $membershipId, string $permissionSlug, ResourceTarget $target, ?RequestOptions $options): bool`; the cache branch keys on the RESOLVED membership id. The class docblock's "there is no cache … Do not add one" comment is Phase 5's record of the contract decision this phase explicitly supersedes (opt-in + invalidation wiring) — rewrite it to state the new boundary.
- **No `Authkit::fga()` accessor exists** — add `fga(): FgaChecker` following the landed app()-resolution accessor pattern; Component 6's discovery helpers become public methods on `FgaChecker`.
- **`ResourceTarget`**: conversion method is `toSdkTarget()` (not the spec's `toSdk()`); properties are `resourceId`/`externalId`/`typeSlug`. Add `toParentTarget()` and `cacheFragment()` alongside.
- **`GenericWorkosEvent` lives at `Authkit\Authkit\Events\GenericWorkosEvent`** (NOT `Events\Workos\` — it is deliberately not an AbstractWorkosEvent subclass). Membership typed events are at `Events\Workos\OrganizationMembership{Created,Updated,Deleted}` as assumed.
- **`Authkit` has NO constructor** (documented: "additive app() resolution avoids cross-phase constructor collisions") — the spec's `new Invitations\InvitationManager($this->clients)` becomes `app(InvitationManager::class)`; managers constructor-inject `Contracts\WorkosClientManager` (auto-wired non-singleton = MockHandler mid-test swap honored, Phase 5/7/8/9 precedent). `GroupManager` additionally injects `FgaChecker`.
- **`HasWorkosResource` has no `syncAsWorkosResource()`** — sync is `static::created`/`static::deleted` boot hooks calling `Authkit::resources()`. Add `workosParentResource(): ?Model` + `workosParentResourceTarget(): ?ResourceTarget` (package DTO, not the SDK class — the landed boundary doctrine gives ResourceTarget exactly this job, RoleManager::assign precedent); `ResourceManager::create()` gains `?ResourceTarget $parentResource = null` converted via `toParentTarget()`. Malformed-parent guard is `$parent instanceof Contracts\WorkosResource` (the landed interface) — the spec snippet's `in_array(self::class, class_uses_recursive($parent))` is wrong in a trait (`self::class` resolves to the using class, not the trait). Post-sync `forgetCache()` fires in both hooks.
- **Config**: no `fga` subtree exists (`authorization.membership_resolver` is separate and stays). Add the spec-literal top-level `'fga' => ['cache' => [...]]` block with `AUTHKIT_FGA_CACHE_{ENABLED,TTL,STORE}` env reads.
- **`JwtTemplateManager::update()` fresh-environment gap**: GET jwt_template 404s (NotFoundException) when no template was ever set — real WorkOS behavior per emulate's spec-citing comment. Spec's unconditional before-read would make the FIRST template write impossible; catch NotFoundException → previousContent `''` (logged in implementation notes).
- **Listener hand-authored** at `src/Authorization/Listeners/InvalidateFgaCache.php` (every landed listener is hand-authored; the spec's make:listener-then-fully-rewrite scaffold retains zero generated content). Registered in `boot()` before the console early-return, gated on `authkit.fga.cache.enabled` via the Repository read pattern.
- **No `workbench.demo_organization_id` config key exists** (spec Open Item 5) — the two demo routes read `organization_id` from the query string, falling back to that config key for parity with the spec.
- **Facade docblock**: add `@method` lines for `invitations()`, `jwtTemplate()`, `corsOrigins()`, `groups()`, `fga()`.

## Phase 12 Build Notes (PHPStan level 7 / conventions)

- `config()` returns mixed: `(bool)`/`(int)` casts at read sites; cache store name needs `is_string` narrowing before `Cache::store()`.
- `Cache::store()->get('…generation', 0)` returns mixed → `is_int`/is_numeric narrowing before `sprintf('%d')`.
- Spec's `$store->has($key)` + `get()` double-read is racy AND `get()` can return null after eviction between the calls — use a sentinel-default `get()` read instead (single read, same fail-open semantics).
- Pint `global_namespace_import`: all spec-snippet FQCNs become `use` imports; `declare(strict_types=1)` on every src file; 100% type coverage enforced.
- SDK retries 429/5xx × `authkit.max_retries` — cache-fault tests fake at the Laravel cache layer (throwing store), not the HTTP layer, so no retry interference.
- Emulate suites skip via `EmulateServer::isAvailable()`; each owns its port (4194 invitations, 4190 jwt) and the dedicated seed file.
- `PaginatedResponse` is NOT generic — plain return type + prose docblock (`@return PaginatedResponse<T>` is a phpstan error).

---

# Context Map — Phase 6: Audit Logs & Admin Portal

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 10/8/4/9/7/5/3 maps retained below._

## Phase 6 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-6.md carries full class bodies for all 8 components, file-change tables, config keys, and per-suite test cases. All four Open Items resolved decisively against landed code (below); one cross-phase seam (§4.8 vs Phase 4's landed listener registration) resolved with a named ownership transfer. |
| Pattern familiarity | READY | Read `AuthkitServiceProvider` (register/boot split, bind-not-singleton for client-holding services, listener registration before console early-return), `Authkit` (container-resolved accessors, "no constructor by design" comment), `Facades/Authkit` (facade shape + @method docblocks), `DeleteWorkosOrganization` (queued job + contract injection), `MembershipNotResolvedException` (static-factory style), `HasWorkosOrganization` (trait boot + `getAttribute()` narrowing), `UsesWorkosMockHandler`, `JwtFixture` + CurrentOrganizationTest (guard auth via sealed cookie + JWKS MockHandler), workbench model/factory/migration conventions. |
| Dependency awareness | READY | Vendored SDK v9.1 matches spec 1:1: `AuditLogs::{createEvent,createSchema,listActions,listActionSchemas,createExport,getExport,getOrganizationAuditLogsRetention,updateOrganizationAuditLogsRetention}` (AuditLogs.php:30-264), `AdminPortal::generateLink` (AdminPortal.php:38), `GenerateLinkIntent` 7 cases, `RequestOptions(idempotencyKey:)`, `WorkOS::auditLogs()/adminPortal()` accessors (WorkOS.php:171,206). `AuditLogEventCreateResponse` = `{success: bool}`; `createSchema` targets take `AuditLogSchemaTargetInput` objects; `PaginatedResponse` NOT generic (no @template). New files have zero external consumers; modified files get additive changes except the §4.8 ownership transfer (below). |
| Edge case coverage | READY | Spec §8 failure-mode table complete (13 rows). SDK exception properties confirmed: `ApiException{statusCode, error, errorCode, rawBody}` public readonly. Soft-delete archive/delete discrimination rule verified against `SoftDeletes::forceDelete()` semantics; `SoftDeletableProject` precedent exists for soft-delete workbench fixtures. |
| Test strategy | READY | Emulate 0.6.0 probed via dist route source (`routes/audit-logs.js`, `routes/portal.js`): see empirical findings. MockHandler-primary; emulate smoke for portal-link + GET-retention + empty listActions. Ports 4191/4192 free (4100/4193/4195-4199/4321 taken). `describe()` wrappers per filter name (Phase 9 precedent) so the spec's `--filter=` inner loops match. |

## Phase 6 Open-Item Resolutions (decisive)

- **Open Item 1 — `Authkit::currentOrganizationId()` does NOT exist.** Phase 3 landed `Authkit::currentOrganization(): ?Model` (local projection row — null when the app configures no org model) and keeps the claims read private (`CurrentOrganizationResolver::currentOrganizationIdFromClaims()`, CurrentOrganizationResolver.php:51). Audit events need the raw WorkOS org id independent of any local model, so `AuditActorResolver` reads the `org_id` claim directly off `Auth::guard('workos')` via the landed `Contracts\HasAccessTokenClaims` interface (WorkosGuard implements it, WorkosGuard.php:17) — exactly the sanctioned §4.2-side adjustment.
- **Open Item 2 — typed event class names match the assumption** (`Events\Workos\OrganizationDomainVerified`/`OrganizationDomainVerificationFailed`), but the payload is a flat `array<string, mixed>` (`AbstractWorkosEvent::$payload` = raw event `data`, with `resourceId()` reading top-level `id` — verification_failed fixtures in ProjectionRefreshListenersTest.php:189 carry top-level `id` + `reason`, never a nested `organizationDomain` object). §4.8's `$event->payload->organizationDomain->id` becomes `$event->resourceId()` for both handlers.
- **Open Item 3 — domains projection = `Models\WorkosOrganizationDomain`** (table `workos_organization_domains`: `workos_id`, `organization_id`, `domain`, `state`, `verification_prefix`, `verification_token`). **Cross-phase seam:** Phase 4 already routes Verified/VerificationFailed into `UpsertOrganizationDomainProjection` (AuthkitServiceProvider.php:380-385) with semantics §4.8 contradicts: it `updateOrCreate`s (spec: warn + no-op on unknown row), never clears token fields on verify (ProjectionRefreshListenersTest.php:175 asserts token KEPT), and never writes `state=failed` (test :195 asserts state unchanged). Resolution: **ownership of the verification pair transfers to the new `UpdateOrganizationDomainVerificationState` listener** — `UpsertOrganizationDomainProjection` narrows to Created|Updated, the provider registers the two new handlers, and the two Phase 4 test cases update to the spec'd semantics (spec §8 failure mode 8 itself says unknown rows are "reconciled later by Phase 4's created/updated listeners", which only works under this split).
- **Open Item 4 — client accessor**: `Contracts\WorkosClientManager::client(): \WorkOS\WorkOS`, constructor-inject the CONTRACT. `AuditLogManager` registers via `bind()` NOT the spec's `singleton()` (holds the client manager; a singleton pins the pre-`fakeWorkosResponses()` instance — Phase 9 precedent, provider comment at AuthkitServiceProvider.php:218). `AuditActorResolver` is stateless → singleton as spec'd. `Authkit` has NO constructor by documented design (Authkit.php:29 "additive app() resolution avoids cross-phase constructor collisions") → `portalLink()` resolves the client via `app(WorkosClientManagerContract::class)`, not the spec snippet's `$this->clients`.

## Phase 6 Empirical Emulate Findings (probed @workos/emulate@0.6.0 dist source)

- `POST /audit_logs/events` reads `body.action.name` at TOP level; SDK v9.1 nests everything under `body.event` (AuditLogs.php:188-191) → SDK createEvent always 422s. **createEvent → MockHandler.**
- `formatAuditLogAction` returns the bare entity (no `schema` key); `AuditLogAction::fromArray` REQUIRES `schema` (with `version`/`targets`/`created_at`) → non-empty `listActions` and every `createSchema` response fatal at SDK parse. **Schema suite → MockHandler**; empty-store `listActions` DOES parse (`data: []`) → wire-fidelity smoke.
- `GET /audit_logs/actions/:name/schemas` (listActionSchemas) and `PUT /organizations/:id/audit_logs_retention` routes DO NOT EXIST → 404. **→ MockHandler.**
- `GET /organizations/:id/audit_logs_retention` exists but returns `retention_days`; SDK reads `retention_period_in_days ?? null` → parses (null value) → usable as smoke round-trip.
- Exports: `POST /audit_logs/exports` auto-transitions to `ready` — poll state machine unobservable → MockHandler per spec §7 (spec's "emulate lacks export" reason is outdated; the routes exist but auto-ready, same conclusion).
- `POST /portal/generate_link` works (requires `intent`+`organization`, returns `{link}`) → AdminPortalTest emulate-backed as spec'd.

## Phase 6 Build Notes (PHPStan level 7 / conventions)

- Pint `global_namespace_import` — spec snippets' inline FQCNs become `use` imports; `declare(strict_types=1)` on every src file (ArchTest).
- Attribute/property reads on `Authenticatable`/`Model`: `data_get($user, 'workos_id')` + `is_string` narrowing (Phase 7 precedent); `getAttribute('workos_id')` inside Model context (HasWorkosOrganization precedent). Trait property override read via `get_object_vars($this)['auditActions'] ?? null` (PHPStan-safe for classes that never declare it).
- `PortalIntent` cases mirror SDK values 1:1 → `GenerateLinkIntent::from($this->value)` is total.
- Unsynced org model (null `workos_id`) passed to `portalLink()` throws a descriptive `RuntimeException` (Phase 8 precedent), never sends an empty id.
- SDK internally retries 429/5xx × `authkit.max_retries` — any failure-injection test sets it to 0 before `fakeWorkosResponses()`.
- Config additions keep the `AUTHKIT_*` env prefix + `(int)` casts; no `env()` outside config/.
- Workbench: `Post` model omits strict_types, uses `#[Fillable]`, `HasFactory` with `@use` docblock; migration named `2026_05_01_000005_create_posts_table.php`; PHPStan analyses `workbench/app` (docblocks needed) but not `workbench/database`.
- Test suites: `describe('HasAuditLogsTrait'|'AuditLogContext'|'AuditLogFacade'|'AuditLogSchema'|'AuditLogExport'|'AdminPortal'|'OrganizationDomainVerification')` wrappers so every spec `--filter` matches; guard auth via `fakeWorkosResponses([jwks])` + `withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign([...])))` with a `UserFactory` row carrying `workos_id: user_fixture`.

---

# Context Map — Phase 10: Connect & MCP Auth

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 8/4/9/7/5/3 maps retained below._

## Phase 10 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-10.md carries full class bodies for all four components, the provider diff, config shape, and per-file test-case lists. Every file named; reconciliation deltas against landed Phases 1–9 identified below (largest: the assumed Phase 2 `JwksVerifier` does not exist and must be authored here to its §4.1 contract). |
| Pattern familiarity | READY | Read `AuthkitServiceProvider` (register/boot split, bind-not-singleton for client-holding services, aliasMiddleware block), `RoleManager` (manager pattern: constructor-inject `Contracts\WorkosClientManager`, call `->client()->...` per call), `RequireOrganizationContext` (middleware shape), `JwtFixture` (committed deterministic RSA keys, `jwks()`, `sign()` with claim overrides + never-advertised forged key), `UsesWorkosMockHandler` (container `HandlerStack` instance + history), `TestCase` (config pinning), `AuthkitConfig` (fail-fast key-naming errors), ArchTest/Pint/PHPStan constraints. |
| Dependency awareness | READY | Vendored SDK v9.1 `Service\Connect` matches spec §6.1 1:1 (verified all 9 wrapped methods + `PaginationOrder`/`ApplicationsRegistrationTypes` enums, `RedirectUriInput`, generic `ConnectApplication` return, `RequestOptions(idempotencyKey:)`); `WorkOS::connect()` accessor exists (WorkOS.php:106). All new files have zero external consumers; modified files get additive-only changes. `laravel/mcp` Packagist check: latest v0.9.2, `^0.9.1` constraint resolves inside the 0.9 line (Open Item 5 re-verified 2026-08-09); requires illuminate ^11.45.3|^12.41.1|^13.0 — compatible. `guzzlehttp/guzzle` is ALREADY a direct require (composer.json:21) — the spec's "keep transitive" note is moot. |
| Edge case coverage | READY | Spec §9 failure-mode table is complete (no-token vs invalid-token WWW-Authenticate split, alg-confusion, kid-stampede debounce, expired, wrong-iss, wrong-aud both shapes, JWKS-down→503-not-401, config-missing asymmetry, blank org id fail-fast, rotation ordering, idempotency passthrough, no-sub M2M, no-local-user). §11 enumerates the parameterized datasets per suite. |
| Test strategy | READY | MockHandler-only per D3 (emulate has zero Connect coverage — no emulate probing needed this phase). `vendor/bin/pest --group=connect-mcp` inner loop + per-suite `--filter`. MCP tokens locally signed via the landed `JwtFixture` keys; JWKS document MockHandler-served through the container `HandlerStack` the new `JwksVerifier` consumes. |

## Phase 10 Reconciliation Against Landed Phases (decisive)

- **§4.1 `JwksVerifier` does NOT exist** (spec Open Item 1 resolves to the bad branch): Phase 2 delegated session JWKS verification to the vendored SDK's private `SessionManager::decodeAccessToken()` and shipped only `Http\JwksGraceCache` — a Guzzle-transport stale-grace middleware hard-scoped to `/sso/jwks/` paths (JwksGraceCache.php:33), useless for `https://{authkit_domain}/oauth2/jwks`. There is nothing to reuse, so **this phase authors `src/Support/Jwt/JwksVerifier.php` to exactly the §4.1 contract** (URL/cacheKey/TTL-parameterized, RS256 allow-list before any fetch, required `kid`, debounced single force-refresh on kid miss, `exp` check, no iss/aud) plus `Support/Jwt/Exceptions/JwtVerificationException` and a distinct `JwksUnavailableException` (NOT a subclass of the 401-mapped exception) so F-jwks-down can map to 503. Verification mechanics mirror `SessionManager::decodeAccessToken()`/`jwkToRsaPublicKeyPem()` (SessionManager.php:372-566): openssl_verify + hand-rolled DER SPKI, no new JWT library. Transport: `GuzzleHttp\Client` built from the container-bound `HandlerStack` when present (same hook `UsesWorkosMockHandler` swaps), plain `HandlerStack::create()` otherwise.
- **`SelfSignedJwksFixture` not created** (spec §7 note + Open Item 2 anticipated this): Phase 2 landed the equivalent fixture as `tests/Fixtures/JwtFixture.php` with committed keys. Reuse it; extend `sign()` with an additive `$headerOverrides` param for the forged-alg/unknown-kid cases rather than duplicating crypto fixtures.
- **Config**: Phase 1 already landed `authkit.mcp.resource_indicator` reading `AUTHKIT_MCP_RESOURCE_INDICATOR` (zero consumers, grep-verified). Phase 10's spec (§8) names `WORKOS_MCP_RESOURCE_INDICATOR` and adds `authkit_domain`/`resolve_user`/`user_model`/`scopes`. Package is unreleased and the key is unconsumed → follow spec-10's env names; note the Phase-1 conflict in implementation notes for Phase 13.
- **`testbench.yaml` change is a no-op**: `Workbench\App\Providers\WorkbenchServiceProvider` is already uncommented under `providers:` (landed by an earlier phase). Only the `loadRoutesFrom(workbench_path('routes/ai.php'))` boot() addition is needed.
- **`ConfigurationException` does not exist** — create `src/Exceptions/ConfigurationException.php` (extends RuntimeException, message names both config keys; AuthkitConfig::require precedent for actionable key-naming messages).
- **ConnectManager client access**: spec §8's snippet constructor-injects the raw `\WorkOS\WorkOS` via a singleton — the landed harness reconciliation (Phases 5/7/8/9, recorded in prior maps) is constructor-inject `Contracts\WorkosClientManager` and call `->client()->connect()` per call, bound non-singleton, so the MockHandler mid-test swap is honored. Follow the landed pattern.
- **User resolution chain**: spec's `config('authkit.mcp.user_model') ?? config('auth.providers.users.model')` gains the landed middle link: `authkit.mcp.user_model` → `auth.providers.workos.model` → `auth.providers.users.model` (`AuthKitController::userModel()` precedent, AuthKitController.php:53), with is_string/class_exists/is_a(Model) narrowing at level 7.
- **Workbench recipe**: no artisan generators exist inside `workbench/` (Testbench CLI drives the skeleton) — write `workbench/routes/ai.php` and `workbench/app/Mcp/Servers/DemoServer.php` directly (prior-phase precedent for workbench files). `workbench/app` IS PHPStan-analysed at level 7 → DemoServer needs iterable value-type docblocks; `workbench/routes` is not analysed.

## Phase 10 Build Notes (PHPStan level 7 / conventions)

- `PaginatedResponse->data` untyped → instanceof-narrow before mapping to DTOs (Phase 8 precedent).
- Enum translation: `PaginationOrder::from($order)`, `array_map([ApplicationsRegistrationTypes::class, 'from'], ...)`, `array_map(fn (string $uri) => new RedirectUriInput(uri: $uri), ...)` — SDK types never appear in ConnectManager's signatures.
- Wrap SDK/Guzzle throwables per method via one `@template`-typed private `call(\Closure $op)` → `ConnectException::operationFailed($e)`; catch `WorkOS\Exception\WorkOSException|GuzzleHttp\Exception\GuzzleException`.
- Middleware alg-none test asserts ZERO mock-handler requests → JwksVerifier must reject the alg before any JWKS fetch.
- 401 challenges: no `error=` param when no token was presented (RFC 6750); `error="invalid_token"` otherwise; both carry `resource_metadata="url('/.well-known/oauth-protected-resource')"`.
- Well-known route registers in `routes/authkit-laravel.php` OUTSIDE the prefixed/middleware group (RFC 9728 fixed path, no session needed) but inside the file's `authkit.routes.enabled` gate.
- Suites tagged `->group('connect-mcp')` via `uses()->group()` (first group usage in the repo; `--group=connect-mcp` is the spec's inner loop).
- Boolean config: middleware casts at read site (`(bool) config('authkit.mcp.resolve_user', false)`) per spec snippet; config file keeps the bare `env()` read (spec §8 literal).

---

# Context Map — Phase 8: API Keys Guard

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 4/9/7/5/3 maps retained below._

## Phase 8 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-8.md carries full class bodies for all components, the provider diff, config shape, fixtures, and per-file test-case lists. Scope-expansion flag (§1/§11) resolved decisively: `expireApiKey()` is CUT — `contract-data.json`'s only two "expire" mentions are about expired JWTs (SessionSecurity criterion), phase-8 notes say "issue/revoke APIs on user + org models", and no ideation transcript exists in the repo to confirm the unquoted "phase direction" source. Per the spec's own directive, the method, its test case, and its file-trace rows are cut rather than shipped unapproved. |
| Pattern familiarity | READY | Read `AuthkitServiceProvider` (register/boot split, `bindIf`, `$app->make(Repository::class)` config reads, pennant.stores runtime-merge precedent for the guards entry, `registerGuard()`'s rebinding-trap comment), `ClaimsGateHook` (invokable Gate::before hook returning true/null only + `stringValues()` claim narrowing), `ResolvesProjectionModels` (user model = `auth.providers.workos.model` → `auth.providers.users.model` fallback chain; org model = `authkit.organization.model`, both with is_string/class_exists/is_a narrowing), `MembershipNotResolvedException` (static-factory style), `RoleManager` (constructor-inject the `Contracts\WorkosClientManager` CONTRACT), `Log::warning('authkit: ...', [...])` convention, Pint `global_namespace_import`, ArchTest (strict_types, no env()). |
| Dependencies | READY | Vendored SDK v9.1.0 matches the spec 1:1: `ApiKeys::createValidation(value)` → `ApiKeyValidationResponse{?ApiKey $apiKey}`; `ApiKey::$owner` is `ApiKeyOwner\|UserApiKeyOwner` (only `UserApiKeyOwner` carries `organizationId`; owner discrimination on `$data['owner']['type']`); `createOrganizationApiKey(organizationId, name, ?permissions, ?expiresAt, ?options)` → `OrganizationApiKeyWithValue`; `UserManagement::createUserApiKey(userId, name, organizationId, ?permissions, ?expiresAt, ?options)` → `UserApiKeyWithValue`; `listUserApiKeys(userId, ..., ?organizationId)` / `listOrganizationApiKeys(organizationId, ...)` → `PaginatedResponse` of `UserApiKey`/`OrganizationApiKey` (untyped `->data` — instanceof-narrow); `deleteApiKey(id)` → void (204-safe); `RequestOptions(idempotencyKey: ...)` confirmed (named ctor param, sent as Idempotency-Key header). Framework: `AuthManager::viaRequest()` already does `$this->app->refresh('request', $guard, 'setRequest')` — no manual request-refresh needed (unlike the workos guard). `RequestGuard` memoizes per request via `$this->user`. |
| Edge case coverage | READY | Spec Failure Modes table complete (§7 rows 1–10). Fixture minimums from `fromArray`: ApiKey/UserApiKey/OrganizationApiKey REQUIRE `id`, `owner` (with valid `type`), `name`, `obfuscated_value`, `permissions`, `created_at`, `updated_at` (`last_used_at`/`expires_at` optional via isset); `*WithValue` additionally REQUIRE `value`; validation response `{"api_key": null}` parses to `apiKey === null`. Emulate 0.6.0 probed via its dist route source (`routes/api-keys.js`) and seed loader (`workos/index.js`): POST `/api_keys/validations`, DELETE `/api_keys/:id` (drops the value from the auth allow-list), GET `/organizations/:orgId/api_keys` exist; **`POST /organizations/:orgId/api_keys` (create) does NOT exist** — spec §3.6's emulate create case is impossible; org-scoped `createApiKey()` moves to the MockHandler suite. **Array-form `apiKeys:` seed REPLACES the default allow-list** (`sk_test_default` no longer authenticates unless re-seeded) → the API keys suite gets its own seed file re-seeding `sk_test_default`, never the shared fixture. Seed schema: `apiKeys[].{name (req), organization (org NAME ref, req), user_id, value (must start sk_), permissions, expires_at}`; organizations may pin `id` (validator enforces uniqueness) — pin org ids so local projections can match `workos_id`. |
| Test strategy | READY | `vendor/bin/pest --filter=ApiKeys` inner loop (`--filter=ApiKeysTest` does NOT match `ApiKeysMockedTest` — distinct class names). MockHandler-primary via landed `UsesWorkosMockHandler` (`fakeWorkosResponses` + `workosRequestHistory`), emulate for the org-scoped guard journey (seeded key → validate/list/revoke) with `EmulateServer(port: 4193, seedPath: dedicated fixture)` — ports 4100/4195/4197/4198/4199/4321 taken. Emulate tests `->skip(fn () => ! EmulateServer::isAvailable())` (Windows CI). SDK retries 5xx × `authkit.max_retries` — WorkOS-down test sets it to 0 before `fakeWorkosResponses()`. Actor unit suite: `Gate::forUser($actor)` needs no auth wiring. |

## Phase 8 Reconciliation Against Landed Phases (decisive)

- **Client accessor (spec Open Item 1)**: `Authkit::client()` does NOT exist. Constructor-inject `Authkit\Authkit\Contracts\WorkosClientManager` and call `->client()->apiKeys()` / `->client()->userManagement()` (RoleManager/VaultCrypto precedent). Traits (no constructor) resolve via `app(WorkosClientManager::class)->client()` at call time. The guard's `viaRequest` callback must `$container->make(ApiKeyAuthenticator::class)` PER INVOCATION — a boot-time instance would pin the pre-`fakeWorkosResponses()` manager (singleton forgotten mid-test).
- **User model key (spec Open Item / §0 row 2)**: use `config('auth.providers.workos.model', config('auth.providers.users.model'))` — the chain `AuthKitController::userModel()` and `ResolvesProjectionModels::userProjectionModel()` already use; NOT bare `auth.providers.users.model` (and `authkit.user.model` is Phase-1 install-command surface, not the runtime resolution chain).
- **Org model key (spec Open Item 2)**: `authkit.organization.model` (nested, singular) — not the spec's `authkit.organization_model`. `MissingModelConfigurationException` messages name the real keys.
- **`expireApiKey()` CUT** (scope-expansion §1/§11): trait `InteractsWithWorkosApiKeys` ships revoke only; no expire test case; no `ApiKey` arm needed in `ApiKeyMapper::fromResource` (only `UserApiKey|OrganizationApiKey` flow through it).
- **Gate hook shape**: spec inlines a closure in `boot()`; the landed pattern is an invokable class (`ClaimsGateHook`) with the true/null-only contract documented. Ship `src/Authorization/ApiKeyGateHook.php` mirroring it, registered immediately after `ClaimsGateHook` in `boot()` (reading order). Mutual exclusivity holds: `ClaimsGateHook` reads `Auth::guard('workos')` claims (null on key-authenticated requests — no sealed cookie), the key hook reads only actor/`apiKeyPermissions()` state (null on session requests).
- **Guard config default**: set `auth.guards.authkit-key` in `register()` via `$this->app->make(Repository::class)` (never `$this->app['config']` — PHPStan offset-access precedent), array_merge so a consumer's own entry wins.
- **Permissions narrowing**: `ApiKey::$permissions` is untyped `array` — normalize to `list<string>` at the boundary (ClaimsGateHook::stringValues precedent) before storing on the actor / user.
- **`workos_id` reads in traits**: `getAttribute('workos_id')` + `is_string` narrowing (HasWorkosOrganization::workosOrganizationId precedent), never bare `$this->workos_id` (mixed at level 7). Unsynced owner (null workos_id) throws a descriptive RuntimeException rather than sending an empty id to WorkOS.
- **Workbench**: `User` uses `#[Fillable]` attributes; models omit strict_types (arch test scopes to `Authkit\Authkit`). Demo route goes in `workbench/routes/web.php` (PHPStan analyses `workbench/app` only, but keep it SDK-free — grep criterion covers all of workbench/).

## Phase 8 Build Notes (PHPStan level 7 / conventions)

- `PaginatedResponse->data` untyped → `collect($page->data)->whereInstanceOf(UserApiKey::class)->map(...)` (or filter+instanceof) before `ApiKeyMapper::fromResource()`.
- `method_exists($user, 'setApiKeyPermissions')` narrows for the call; returns mixed (ignored).
- `Request::header($name)` returns `string|array|null` — is_string-narrow; treat `''` as absent.
- `WorkosApiKeyActor::setRememberToken(mixed $value): void` — interface param untyped → `mixed` for 100% type coverage (Phase 9 precedent).
- `Gate::before` hook param typed non-nullable `Authenticatable $user` → never invoked for guests; returns `?bool`, true/null ONLY (ClaimsGateHook doctrine).
- Emulate seed for this suite re-seeds `sk_test_default` as an org-owned key (SDK admin auth) under a SEPARATE seeded org, so Acme's key listing assertions see only Acme's keys.
- Pint `global_namespace_import`: no inline FQCNs; `declare(strict_types=1)` on every src file.

---

# Context Map — Phase 4: Events Pipeline & Webhooks

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 9/7/5/3 maps retained below._

## Phase 4 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-4.md carries full class bodies for all ten components, the provider diff, config shape, stub text, and per-file test-case lists; reconciliation deltas against landed Phases 1–3 identified below. |
| Pattern familiarity | READY | Read `AuthkitServiceProvider` (register/boot split, `$app->make(Repository::class)` config reads, console-only block + `$this->commands()`), Phase 3 migrations (anonymous-class style, opaque `state` string), `UpsertOrganizationAndMembershipFromLogin` (listener + model-resolution pattern), `AuthKitController::userModel()` (auth.providers.workos.model fallback chain), `HasWorkosUser::findOrCreateForWorkosUser` (verified-email linking semantics), Pint config, phpstan level 7 paths, ArchTest (strict types, no env()). |
| Dependencies | READY | Vendored SDK v9.1.0 matches spec 1:1: `Events::listEvents(before, after, limit, order: PaginationOrder(::Asc), events, rangeStart, rangeEnd, organizationId)` → `PaginatedResponse` (untyped `->data`); `EventSchema` readonly (`object`, `id`, `event`, `data: array`, `createdAt: DateTimeImmutable`, `context`), `fromArray` public static (requires `id`/`event`/`data`/`created_at`); `WebhookVerification::verifyEvent(eventBody, eventSignature, secret, tolerance)` → array, throws `InvalidArgumentException`, `computeSignature` public static, DEFAULT_TOLERANCE 180; exceptions: `WorkOSException` is an interface extending Throwable; 400→`BadRequestException`, 404→`NotFoundException`. Framework v13.24: `PreventRequestForgery` IS what the web group applies (`Foundation/Configuration/Middleware.php:490`); `Command::trap` exists (guards via `Signals::whenAvailable`; pass signals as a CLOSURE `fn () => [SIGTERM, SIGINT]` per `ServeCommand:203` so constants never evaluate on Windows); `Lock::refresh()` exists on base Lock + ArrayLock. |
| Edge case coverage | READY | Spec Failure Modes table complete. Emulate 0.6.0 probed live: `GET /events` parses via `EventSchema::fromArray` (seeded users/orgs/domains each emit `*.created` events; runtime `POST user_management/users` emits more); `after` cursor + `order=asc` work; **bogus `after` returns 200 with the full list (never 400/404)** → the stale-cursor→rangeStart fallback path MUST be MockHandler-tested; **emulate does not enforce the `range_start` 3-digit-ms format** (accepts date-only) → format assertions go against MockHandler request history (real WorkOS rejects non-`Y-m-d\TH:i:s.v\Z` per prior session finding). |
| Test strategy | READY | MockHandler-primary + one emulate smoke test per suite (Phase 2/3/5/7/9 precedent; emulate is skipped on Windows CI via `EmulateServer::isAvailable()`, and the named success-criterion suites must run on all platforms). `array` cache driver supports atomic locks. Daemon-path test: a dispatched-event listener sends `posix_kill(posix_getpid(), SIGINT)` (pcntl-gated skip). Generator tests write to the shared Testbench skeleton — unique class names + afterEach cleanup. |

## Phase 4 Reconciliation Against Landed Phases (decisive)

- **Projection models (spec Prerequisites row 4)**: Phase 3 shipped `Authkit\Authkit\Models\WorkosOrganizationDomain` (table `workos_organization_domains`: `workos_id` unique, `organization_id` indexed, `domain`, `state` nullable opaque string, `verification_prefix`, `verification_token`) and `WorkosMembership` (table `workos_memberships`: `workos_id` unique, `organization_id`, `user_id`, `role` slug nullable, `status`) — NOT the spec's assumed `OrganizationDomain`/`OrganizationMembership`. All four domain/membership listeners target these.
- **Org model config key**: `authkit.organization.model` (SINGULAR), not the spec's `authkit.organizations.model`. May be null/unconfigured — org listeners must no-op gracefully (precedent: `UpsertOrganizationAndMembershipFromLogin::sync()` is_string/class_exists/is_a guard). Org table has `name` + `workos_id` only (no local `external_id` column — external_id maps remotely to the local key).
- **User model resolution**: `config('auth.providers.workos.model', config('auth.providers.users.model'))` per `AuthKitController::userModel()` — not the spec's bare `auth.providers.users.model`. Users table: skeleton (`name`, `email` unique, `password` nullable) + `workos_id` nullable unique. No `external_id` column — the spec's illustrative `['external_id' => ...]` update payload maps to `email`/`name` here (spec itself flags "verify column names before implementing").
- **Client accessor**: constructor-inject `Authkit\Authkit\Contracts\WorkosClientManager` (the CONTRACT), call `->client()->events()` / `->client()->webhookVerification()` — Phase 5/7/9 precedent; never `Support\WorkosClientManager`.
- **Config subtree**: `config/authkit.php` already has `events` with `enabled` + `poll_interval` + `cursor_cache_store` — `enabled` and `cursor_cache_store` are dead Phase 1 placeholders no code reads (grep-verified). This phase owns the subtree (Phase 9 precedent): replace with the spec's `poll_interval`/`batch_limit`/`backfill_minutes`/`lock_ttl`; add the `webhooks` subtree.
- **CSRF exclusion must cover BOTH support lanes** (composer: laravel/framework `^12.0||^13.0`, CI matrix runs both): exclude `[PreventRequestForgery::class, ValidateCsrfToken::class]` — on L13 the group applies `PreventRequestForgery` (exact match drops it; `ValidateCsrfToken` is its deprecated child, harmless extra), on L12 the group applies `ValidateCsrfToken` (exact match; `PreventRequestForgery` string matches nothing). Excluding only the spec's single L13 class would leave every L12 webhook POST 419-ing.
- **docs/quickstart.md does not exist** (spec says "created by an earlier phase; append here" — no phase created it). Create it fresh with this phase's content; log the gap.
- **`workbench/routes/web.php`** is minimal (no strict_types, plain web routes) — add the macro call there; orchestra/workbench loads it under the `web` group, exercising the CSRF exclusion for real.
- **Migration naming**: keep the spec's literal `2026_04_01_000001_*` (sorts before Phase 3's `2026_05_01_*`; no FK or ordering dependency between the tables).

## Phase 4 Build Notes (PHPStan level 7 / conventions)

- `PaginatedResponse->data` is untyped `array` — instanceof-narrow `EventSchema` before dispatch/commit (Phase 3/5 precedent).
- `AbstractWorkosEvent::resourceId()` reads `payload['id']` (mixed) — needs `is_string` narrowing; throw a descriptive RuntimeException on a payload without a string `id` rather than returning mixed.
- Webhook controller: `$request->attributes->get(...)` is mixed — `is_array` narrow before `EventSchema::fromArray`.
- Commands read config via `config()` helper + `(int)` casts (established) or Repository injection; never `env()` outside config/ (arch test + larastan rule).
- Pint `global_namespace_import`: all FQCNs in spec snippets become `use` imports; `declare(strict_types=1)` on every src file.
- SDK internally retries 429/5xx × `authkit.max_retries` (default 3) — failure-injection tests set it to 0 before `fakeWorkosResponses()`.
- `trap()` signals: closure form `fn () => [SIGTERM, SIGINT]` (Windows-safe; `ServeCommand` precedent).
- Emulate `.reset()` is off-limits for this phase's suites (known quirk breaks auth-event webhooks) — isolate by fresh boot/reseed; `EmulateServer` helper already boots per-instance with a seed file. Use a unique port (not 4100/4198/4321-adjacent collisions across parallel workers — pick per-suite constants).
- `WebhooksTest` needs no emulate at all: signatures are computed locally via `WebhookVerification::computeSignature()` and posted to our own route.

---

# Context Map — Phase 9: Vault

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 7/5/3 maps retained below._

## Phase 9 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-9.md carries full class bodies for all five components, the provider diff, the canonical MockHandler round-trip pattern, and per-file test-case lists; reconciliation deltas against landed Phase 1 identified below. |
| Pattern familiarity | READY | Read `AuthkitServiceProvider` (register/boot split, membership-resolver config-swappable binding precedent, derived-SDK-service `bind` precedent), `UsesWorkosMockHandler`, `TestCase` (`migratePackageDatabase` runs workbench migrations), `MembershipNotResolvedException` (static-factory exception style), Pint config (`global_namespace_import` — no inline FQCNs), ArchTest (strict_types on `Authkit\Authkit`, no `env()`), phpstan level 7 over `src|config|database|routes|workbench/app`. |
| Dependencies | READY | Vendored `WorkOS\Service\Vault` matches the spec 1:1: `encrypt(string, array, ?string): string` / `decrypt(string, ?string): string` (client-side AES-256-GCM, one `createDataKey`/`createDecrypt` call each), `createKv(array,string,string): ObjectMetadata`, `getName`, `getKv`, `updateKv(id,value,?versionCheck): ObjectWithoutValue`, `deleteKv(id,?versionCheck): void`, `listKvMetadata: ObjectWithoutValue`, `listKvVersions: VersionListResponse`, `listKv(...): PaginatedResponse`. `WorkOS::vault()` accessor exists. 409 → `ConflictException`; 204/empty body decodes to null (deleteKv-safe); error bodies are normalized (missing `message` synthesized). |
| Edge case coverage | READY | Spec Failure Modes table complete. Fixture minimums confirmed from `fromArray`: `CreateDataKeyResponse` = context, data_key, encrypted_keys, id; `DecryptResponse` = data_key, id; `ObjectMetadata` = context, environment_id, id, key_id, updated_at, updated_by{id,name} (version_id optional); `VaultObject` = id, metadata{…}, name, value; `ObjectWithoutValue` = id, metadata{…}, name (no `value` property — compile-time proof holds); `VersionListResponse` = data[] of ObjectVersion{created_at,current_version,etag,id,size} + list_metadata; `ObjectSummary` = id, name (updated_at optional). |
| Test strategy | READY | 100% MockHandler via the landed `UsesWorkosMockHandler` trait (spec Open Item 2: shared helper EXISTS — adapt `fakeVaultRoundTrip()` onto `fakeWorkosResponses()`; never `new WorkOS(...)` directly). Queue-untouched assertions via `$this->workosMockHandler->count()`; wire assertions via `$this->workosRequestHistory`. `describe('Vault', ...)` wrapper per file so `vendor/bin/pest --filter=Vault` catches all suites (no existing suite uses describe; Pest 4 supports it). |

## Phase 9 Reconciliation Against Landed Phases (decisive)

- **Spec Open Item 1 — client binding**: Phase 1 does NOT bind `\WorkOS\WorkOS::class`. The container binds `Authkit\Authkit\Contracts\WorkosClientManager` (singleton) with `->client(): WorkOS`. `VaultCrypto`/`VaultManager` must constructor-inject the CONTRACT and call `->client()->vault()` (Phase 5/7 precedent).
- **Singleton vs bind**: the provider deliberately `bind()`s (not singleton) every SDK-derived service ("so swapping the handler stack mid-test is picked up" — `UserManagement`/`SessionManager`/`PKCEHelper`). `fakeWorkosResponses()` works by `forgetInstance(WorkosClientManagerContract::class)`; a singleton `VaultCrypto`/`VaultManager` would pin the pre-fake manager. → register both as `bind`, not the spec's `singleton` (deviation, logged).
- **Spec Open Item 2 — MockHandler helper**: `tests/Concerns/UsesWorkosMockHandler` exists; use it instead of the spec's self-sufficient `fakeVaultRoundTrip()` constructing a raw `WorkOS`.
- **Config**: `config/authkit.php` already has a `vault` subtree — but it holds only a dead `'key_context' => env('AUTHKIT_VAULT_KEY_CONTEXT')` placeholder no code reads. This phase owns the subtree: replace the placeholder with `key_context_resolver` + `filesystem.max_encrypt_bytes` (logged).
- **Membership-resolver binding precedent** (`register()`): config-swappable class with `is_string` narrowing + instanceof check throwing a named RuntimeException — mirror it for `ResolvesVaultKeyContext`, adding the instanceof failure path to `InvalidVaultKeyContextResolverException` (spec §6 only catches `BindingResolutionException`).
- **`Illuminate\Contracts\Filesystem\Filesystem`** (vendored Laravel 12): 22 methods incl. `putFile`/`putFileAs` — the spec's adapter covers every one. Interface params are untyped → implementation params must be `mixed` (or docblock-typed) for 100% type coverage; return types may be added covariantly; `readStream` returns `resource|null` → docblock only.
- **`FilesystemManager::resolve()`** confirmed: custom creator receives `($app, $config)` and its return is used directly — a contract implementation needs no Flysystem adapter.
- **CastsAttributes is generic** (`@template TGet/TSet`) → `Vaulted` needs `@implements CastsAttributes<string, string>` for PHPStan level 7 missing-generics.
- **Workbench**: models omit `declare(strict_types=1)` (arch test scopes to `Authkit\Authkit`); `casts(): array` method style confirmed on `User`; migrations live in `workbench/database/migrations` (auto-run by `TestCase::migratePackageDatabase()`), naming `2026_05_01_0000NN_*`; PHPStan analyses `workbench/app` but NOT `workbench/database`.

## Phase 9 Build Notes (PHPStan level 7 / conventions)

- `DefaultVaultKeyContextResolver`: `method_exists()` narrowing lets PHPStan accept the dynamic calls, but their returns are `mixed` → `is_string`/key-value narrowing loops needed to satisfy `array<string, string>`.
- Provider closures: use `$app->make(Repository::class)->get(...)` (never `$this->app['config']`), `(int)` casts of mixed config reads are established and pass here.
- Pint `global_namespace_import` imports all classes — spec snippets' inline FQCNs must become `use` imports.
- SDK internal retries (429/5xx × `authkit.max_retries`, default 3): the outage test sets `authkit.max_retries` to 0 before `fakeWorkosResponses()` (which forgets the manager instance).
- The cast's `set()` runs inside `Model::fill()` → an encrypt throw happens before any INSERT is built — the fail-closed/no-row assertions need no transaction plumbing.
- `Storage::fake('vault')` would erase the custom driver — fake the INNER disk only (spec §7 pattern).
- The vault-disk closure resolves `VaultCrypto` at disk-resolution time and `FilesystemManager` memoizes disks → each test must call `fakeWorkosResponses()` (once, full queue) before first `Storage::disk('vault')` touch.

---

# Context Map — Phase 7: Feature Flags (Pennant Driver)

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 5/3 maps retained below._

## Phase 7 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-7.md carries the full authoritative driver class body, DTO, provider diff, and 12 canonical test cases; reconciliation deltas against landed Phases 1–3/5 identified below. |
| Pattern familiarity | READY | Read `AuthkitServiceProvider` (register/boot split, `bindIf`, `(int)`-cast config reads pass PHPStan here), `WorkosGuard` (real claims accessor), `UsesWorkosMockHandler` (swaps HandlerStack + forgets manager instance), `JwtFixture`, `AuthorizationMockTest` (MockHandler fixture + guard-auth patterns), `tests/TestCase.php`. |
| Dependencies | READY | `laravel/pennant:^1.22` installed (resolves v1.24.0); `Contracts/Driver.php` + `HasFlushableCache` match the spec verbatim. `FeatureManager::callCustomCreator` invokes `($container, $config)` as specced; `PennantServiceProvider::register()` does the top-level `mergeConfigFrom('pennant')` D-4 defends against. SDK `FeatureFlags::listUserFeatureFlags/listOrganizationFeatureFlags` signatures match; paths are `user_management/users/{id}/feature-flags` and `organizations/{id}/feature-flags`. `Flag::fromArray` REQUIRES `id`, `slug`, `name`, `tags`, `enabled`, `default_value`, `created_at`, `updated_at` (object/description/owner optional) — MockHandler fixtures must carry all. `PaginatedResponse` is not generic; `autoPagingIterator()` yields untyped — instanceof-narrow `Flag` in the loop. |
| Edge case coverage | READY | Spec Failure Modes table complete; Pennant `Decorator::get()` memoizes per (feature, scope) — the log-dedupe test must vary scope (dedupe key is `unknown:{feature}:{type}`, id-independent) or flush; `Decorator::define()` does call `driver->define()` (throw path reachable); undefined string features pass straight to `driver->get()` (no dynamic-define interception for non-class names). |
| Test strategy | READY | `vendor/bin/pest --filter=FeatureFlags`, MockHandler-only per spec; SDK internally retries 5xx up to `authkit.max_retries` (default 3) — failure-injection cases set it to 0 before the first `Feature::` call (driver+manager resolve lazily). |

## Phase 7 Reconciliation Against Landed Phases (decisive)

- **Spec Open Item 1 — client accessor**: `Contracts\WorkosClientManager::client(): \WorkOS\WorkOS` exists exactly as assumed. Driver must type-hint the CONTRACT (`Authkit\Authkit\Contracts\WorkosClientManager`), not `Support\WorkosClientManager` — Phase 5 precedent; container binds the contract.
- **Spec Open Item 2 — guard claims accessor**: Phase 2 landed `WorkosGuard::accessTokenClaims(): ?array` behind the `Contracts\HasAccessTokenClaims` interface (Phase 5 added the interface). Neither `claims(): ?array` on the guard nor `workosClaims()` on the user exists (the user trait's `claims()` returns the `AccessTokenClaims` DTO, not the raw payload). → `guardClaims()` checks `$guard instanceof HasAccessTokenClaims` and calls `accessTokenClaims()`; the raw payload it returns carries `feature_flags` when present.
- **Spec Open Item 3/4 — config**: `config/authkit.php` already exists (Phase 1 rename done) AND already carries `feature_flags => ['cache_ttl' => (int) env('AUTHKIT_FEATURE_FLAGS_CACHE_TTL', 30)]` — the spec's config Modified row is a no-op. `authkit.client_id` key confirmed.
- **tests/TestCase.php** must add `PennantServiceProvider` to `getPackageProviders()` — package suites don't rely on skeleton discovery being fresh; registration is idempotent if discovery also loads it. Registration order [Authkit, Pennant] is exactly the dangerous D-4 order, making test case 11 meaningful.
- Workbench `User` model has `workos_id` (Phase 2 projection) — user-scope duck-typing works against `UserFactory` rows unchanged.

## Phase 7 Build Notes (PHPStan level 7 / conventions)

- `$scope->workos_id ?? null` on `Authenticatable`/`Model` interface types fails PHPStan — use `data_get($scope, 'workos_id')` + `is_string` narrowing (routes through Eloquent `__get` for models, plain property for other objects).
- Cache entry reads need shape normalization before arithmetic: narrow `cachedAt` to int and `slugs` to `list<string>` via a helper returning `array{slugs: list<string>, cachedAt: int}|null`.
- `WorkosFeatureScope::$type` needs a `@param 'user'|'organization'` docblock so the `match` in `fetchFromApi()` is exhaustive without a default arm.
- `(int)`/`(string)` casts of mixed config reads are established in this codebase and pass its PHPStan config.
- Provider must not use `$this->app['config']` (offset access is mixed) — `$this->app->make(Repository::class)` per existing provider style.
- Pint: Laravel preset, no spaces around `.` concatenation; run `composer lint` before `lint:check`.
- Log-once dedupe test: two scopes sharing a type hit the same `unknown:{feature}:{type}` key; Decorator memoization makes same-scope repeats invisible to the driver.
- `ServerException`/`ConnectException` handling: queue `Response(500, ..., json body)` with `authkit.max_retries` 0; SDK wraps it in a `WorkOSException` subclass.

---

# Context Map — Phase 5: Authorization (RBAC + FGA) (prior)

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates). Prior Phase 3 map retained below._

## Phase 5 Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-5.md carries full code for all six components; reconciliation deltas against landed Phase 3 identified (see below). |
| Pattern familiarity | READY | Read `HasWorkosOrganization` (trait boot + defensive getAttribute narrowing), `CurrentOrganizationResolver` (org_id claim resolution + duck-typing note anticipating `HasAccessTokenClaims`), `UsesWorkosMockHandler`, `JwtFixture`, `EmulateServer`, `MembershipProjectionResolverTest`, `CurrentOrganizationTest`, workbench model/migration patterns. |
| Dependencies | READY | SDK signatures re-confirmed against vendored `Authorization.php` (all 1:1 with spec; `deleteEnvironmentRole` confirmed absent; `listEnvironmentRoles`/`listOrganizationRoles` return `RoleList` not `PaginatedResponse`). Client accessor is `Contracts\WorkosClientManager::client()` (interface, container-bound singleton) — managers must type-hint the CONTRACT for auto-wiring, not `Support\WorkosClientManager` as the spec snippets literally show. `Authkit.php` has no `roles`/`permissions`/`resources`/`check` collisions. |
| Edge case coverage | READY | Spec Failure Modes §1–12 + empirical emulate drift (below) + SDK fixture shape requirements (Role::fromArray REQUIRES `resource_type_slug` + `permissions`; Permission requires `system` + `resource_type_slug`; UserRoleAssignment requires `role`/`resource`/`source` sub-objects; AuthorizationResource requires `name`). |
| Test strategy | READY | `vendor/bin/pest --filter=Authorization` inner loop; MockHandler-primary (empirically forced, spec-sanctioned fallback); one emulate smoke test per Phase 2/3 precedent; zero-HTTP enforcement via empty MockHandler queue; `composer test` full chain. |

## Phase 5 Reconciliation Against Landed Phase 3 (decisive)

- **Phase 3 HAS landed** (commit 952e344). It already authored `src/Contracts/ResolvesOrganizationMembershipId.php` (identical to spec §4.4's interface), the provider `bindIf` reading `config('authkit.authorization.membership_resolver')`, the `config/authkit.php` `authorization.membership_resolver` key defaulting to `MembershipProjectionResolver::class` (a REAL projection-backed resolver), and `WorkosGuard::accessTokenClaims()` (plain method, no interface — docblock explicitly says "until the HasAccessTokenClaims contract lands with the authorization phase").
- Phase 5 therefore: does NOT re-author the contract/bindIf/config key; DOES author `HasAccessTokenClaims` + adds `implements` to `WorkosGuard`; DOES NOT flip the config default to `NullMembershipResolver` (the real resolver is strictly better — §3a's seam is already filled); still ships `NullMembershipResolver` (spec-listed, used by §8's MembershipNotResolvedException test as the explicit "no resolution" binding).
- `CurrentOrganizationResolver` duck-types `method_exists($guard, 'accessTokenClaims')` — adding the interface to the guard is a safe superset; no change needed there.

## Phase 5 Empirical Emulate Findings (probed live against @workos/emulate@0.6.0)

Both spec Open Items (§4.4, §8) verified empirically; both downgrade fallbacks triggered:

- Emulate SERVES `authorization/roles`, `authorization/permissions`, `authorization/organizations/{org}/roles`, `authorization/resources`, membership `role_assignments`/`check` routes, and memberships ARE creatable at runtime via `POST user_management/organization_memberships` (status `active`) — but:
- **`check` expects body key `permission` — SDK v9.1 sends `permission_slug` → 422.** FgaChecker's emulate path is impossible → MockHandler (spec §4.4 fallback).
- **`assignRole` expects `role_id` — SDK sends `role_slug` + resource target → 422.** Assignment cases → MockHandler (spec §8 fallback).
- **Role/permission/resource response shapes are legacy**: role lacks `resource_type_slug` + `permissions` (Role::fromArray fatals), permission lacks `system` + `resource_type_slug`, created resource lacks `name`. Even CRUD round-trips fail at SDK parsing → MockHandler for all non-empty-response cases.
- Empty list responses DO parse (`RoleList`/`PaginatedResponse` tolerate `data: []`) → the emulate smoke test asserts empty env-role + permission list round-trips through the real wire (path/auth/base-url fidelity), matching the one-smoke-test-per-suite precedent.
- Do NOT add roles/permissions to `workos-emulate.config.yaml` — seeded entries would make list responses non-empty and unparseable by the SDK.

## Phase 5 Build Notes

- PHPStan level 7 covers `workbench/app` — the workbench `Project` model and `ProjectPolicy` are analysed; `HasWorkosResource` is analysed in Project's context. Trait event closures should type the model param `self` (resolves to the using class) so `workosResourceType()` calls analyse clean.
- Spec snippets need PHPStan-level-7 narrowing the spec itself doesn't show: `$claims['permissions'] ?? []` is `mixed` (needs `is_array` guard before `in_array`); `accessTokenClaims()['org_id']` needs `is_string`; `ResourceTarget::toSdkTarget()` needs a null-impossible branch; `PaginatedResponse` is NOT generic — do not write `@return PaginatedResponse<T>` (use plain return + prose docblock).
- `PaginatedResponse->data` / `RoleList->data` are untyped `array` — returning them against `array<int, Role>` docblocks needs a values-narrowing pattern (Phase 3 precedent reads `->data[0] ?? null` and instanceof-checks).
- TestCase does NOT set `auth.defaults.guard` to `workos` (Failure Mode §3) — feature tests exercising `$user->can()` must auth via `auth('workos')` explicitly or set the default in-test.
- MockHandler fixture minimums (from fromArray): Role = slug,id,name,type(`EnvironmentRole`|`OrganizationRole`),resource_type_slug,permissions,created_at,updated_at; Permission/AuthorizationPermission += system; UserRoleAssignment = id,organization_membership_id,role{slug},resource{id,external_id,resource_type_slug},source{type},created_at,updated_at; AuthorizationResource = name,organization_id,id,external_id,resource_type_slug,created_at,updated_at.
- SDK internal retries: failure-injection tests must set `authkit.max_retries` 0 + `forgetInstance` (Phase 3 risk #2) — applies to the `system: true` permission-delete 4xx case (4xx aren't retried, but keep in mind for any 5xx case).

---

# Context Map — Phase 3: Organizations & Org Context (prior)

_Produced by inline scout (ideation:scout agent unregistered in this session's harness — no subagent dispatch tool). Verdict: **GO** (5/5 gates)._

## Readiness Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| Scope clarity | READY | spec-phase-3.md carries full code for every component, config diff, route shape, and canonical test-case list. |
| Patterns | READY | Phase 2 analogues exist for every component class shape (trait: `HasWorkosUser`; middleware: `RefreshWorkosSession`; controller: `AuthKitController`; cookie: `SessionCookie`; tests: `AuthenticationFlowTest`/`HasWorkosUserTraitTest`). Phase 4/5 patterns the spec cites (`WorkosEventCursor`, `NullMembershipResolver`) do NOT exist — Phases 4/5 have not landed; substitutes identified below. |
| Dependencies | READY | All SDK signatures re-confirmed against vendored `workos/workos-php`: `Organizations::{createOrganization,getOrganizationByExternalId,getOrganization,deleteOrganization}`, `OrganizationMembershipService::listOrganizationMemberships(organizationId:, userId:)` → `PaginatedResponse->data` of `UserOrganizationMembership` (`id`, `userId`, `organizationId`, `status: OrganizationMembershipStatus` enum, `role: SlimRole` with `->slug`), `SessionManager::refresh(sessionData, cookiePassword, clientId, ?organizationId)` → `['authenticated' => bool, 'sealed_session' => ...]`, exceptions `NotFoundException`(404)/`ConflictException`(409)/`UnprocessableEntityException`(422)/`ServerException`(5xx). |
| Environment | READY | Baseline was RED on session start (10 errors: "No application encryption key" — Testbench skeleton `.env` purged by fresh `composer install`); repaired by pinning `app.key` in `tests/TestCase.php`. 124/124 green after repair, matching Phase 2's recorded evidence. |
| Risks | READY (named) | See below. |

## Phase Landing Order (decisive for this spec)

- **Phase 5 has NOT landed**: no `src/Authorization/`, no `src/Contracts/ResolvesOrganizationMembershipId.php`, no `HasAccessTokenClaims`. → Component 7 **step 0 "no" branch**: this phase authors the interface, the `bindIf()` registration, and sets the config default straight to `MembershipProjectionResolver::class`. The `Authkit::check()` integration test case is impossible (no `Authkit::check()` exists) — dropped, noted.
- **Phase 4 has NOT landed**: no `src/Models/`, no event cursor migration. Migration pattern substitute: this phase's own anonymous-class style matching `database/migrations/2026_01_02_000000_add_workos_id_to_users_table.php`.
- **`WorkosGuard` has no `accessTokenClaims()`** — without it, `CurrentOrganizationResolver` would be dead code until Phase 5 and this phase's own canonical test cases could not pass. spec-phase-5.md §4.1 explicitly sanctions adding the thin accessor "as part of this phase's work" wherever first needed. → Add plain `accessTokenClaims(): ?array` method (no interface) to `WorkosGuard`; resolver still duck-types via `method_exists` exactly as specced.

## Key Patterns

- All `src/` files: `declare(strict_types=1)` (arch test), no `env()` outside config (arch test + larastan rule), 100% type coverage enforced (`composer test:types`), PHPStan level 7 over `src|config|database|routes|workbench/app`.
- SDK access: always `app(WorkosClientManagerContract::class)->client()->...` or the provider's derived `bind`s. `UsesWorkosMockHandler::fakeWorkosResponses([...])` swaps the Guzzle stack (`$this->app->instance(HandlerStack::class, ...)` + `forgetInstance` of the manager); `$this->workosRequestHistory` counts calls.
- SDK internally retries 429/500/502/503/504 up to `authkit.max_retries` (default 3) with sleeps — failure-injection tests must set `authkit.max_retries` to 0 (and forget the client manager instance) or queue N copies.
- Sealed-cookie issuance: `SessionCookie::issue($sealed)` is the single sanctioned constructor (docblock demands every issuer agree) — the org-switch controller uses it, not a hand-rolled `Cookie`.
- Guard auth in tests: `fakeWorkosResponses([<JWKS response>])` + `withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign([...claims])))`. `org_id` claim rides via `JwtFixture::sign(['org_id' => ...])`.
- Model-class-from-config: follow `AuthKitController::userModel()` (is_string + class_exists + is_a narrowing) for PHPStan-safe `class-string<Model>` resolution.
- Emulate: `EmulateServer` (pinned 0.6.0), per-test port, seed YAML at `tests/Fixtures/workos-emulate.config.yaml` (supports `users`, `organizations` w/ domains); enable via `authkit.emulate.enabled` + `base_url` + `forgetInstance`. Phase 2 precedent: ONE emulate smoke test per suite, everything else MockHandler. Emulate cannot seed memberships as far as the fixture schema shows → login-projection + switch suites are MockHandler-backed per spec Open Item 4's sanctioned downgrade.
- Tests: Pest 4, `uses(TestCase::class)` global, `$this->migratePackageDatabase()` in beforeEach; workbench models for fixtures (`Workbench\App\Models\User` already referenced by `tests/TestCase.php`).

## Dependencies (landed, confirmed)

- `Authkit\Authkit\Events\Login` (`$user`, `$response: AuthenticateResponse` with `->accessToken`) dispatched in `AuthKitAuthenticationRequest::authenticate()`.
- `AuthKitLoginRequest::redirect(?string $intendedUrl = null)` — gains trailing `?string $organizationId = null`.
- `AccessTokenClaims::fromPayload()` / `JwtPayloadDecoder::decode()` — `organizationId` from `org_id`, `sub` present.
- `AuthkitServiceProvider::boot()` already calls `loadMigrationsFrom` + `publishesMigrations` — new migrations need no provider change.
- Testbench `loadLaravelMigrations()` provides skeleton `users` AND `jobs`/`failed_jobs` tables (database queue driver usable in worker-path tests).

## Risks

1. **Skeleton .env drift** (hit + fixed this session): never rely on skeleton state; pin config in TestCase.
2. **SDK internal retry sleeps** in failure tests → set `authkit.max_retries` 0.
3. `UserOrganizationMembership::fromArray` REQUIRES `directory_managed`, `role`, `roles`, `user`, timestamps — MockHandler membership fixtures must carry all of them.
4. `Organization::fromArray` REQUIRES `domains` + `metadata` keys — MockHandler org fixtures must carry both.
5. Open redirect via `return_to` on the switch route — constrain to app-relative paths.
6. Parallel pest (`--parallel`): emulate tests must use unique ports (Phase 2 used 4198).
