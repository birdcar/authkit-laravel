# Implementation Spec: AuthKit Laravel v1 - Phase 3 — Organizations & Org Context

**Contract**: `./contract-data.json`
**Context brief**: canonical research digest supplied alongside this spec — treated as authoritative for every SDK signature and repo convention cited below.
**Estimated Effort**: L

**Risk**: Medium (per contract `execution.phases`). The risk isn't any single mechanism here being hard — it's breadth: this phase is the most heavily cross-cited interface surface in the whole project (five other phases assume its shapes), and it is the one phase in the dependency graph that both *consumes* an existing phase's public API (Phase 2's login flow) and *produces* the seam a landed phase (Phase 5) explicitly built a swappable placeholder for.

## Confirmed Foundations From Prior Phases

Unlike Phase 4/5/8/13 — which were written in parallel before every prerequisite phase had a landed spec — Phase 3 is being written **after** Phases 1 and 2 already exist as committed spec files. Their real, confirmed interfaces (not assumptions) are load-bearing for this phase and are used verbatim below, not guessed:

| Symbol | Confirmed shape | Source |
|---|---|---|
| `Authkit\Authkit\Contracts\WorkosClientManager` | Interface: `client(): \WorkOS\WorkOS`. Bound as a singleton in `AuthkitServiceProvider::register()`; concrete class `Authkit\Authkit\Support\WorkosClientManager`. | spec-phase-1.md §1 |
| `config('authkit.organization.model')` | Env `AUTHKIT_ORGANIZATION_MODEL`, default `null`. The app's org model FQCN — **singular** `organization`, nested `model` key. | spec-phase-1.md §2 (`config/authkit.php` schema) |
| `config('authkit.organization.external_id_column')` | Declared by Phase 1 with value `'workos_id'`, but — confirmed by reading Phase 2's shipped `HasWorkosUser` trait — no phase's code actually reads this key; Phase 2 hardcodes the literal column name `workos_id` throughout instead. This phase follows that same precedent (hardcodes `workos_id`) rather than resurrecting an unused config indirection — see Component 3 Key Decisions. |
| `config('authkit.routes.paths')` | `['login' => 'login', 'logout' => 'logout', 'callback' => 'callback']` under `config('authkit.routes.*')`, prefix `authkit`. This phase adds a `switch_organization` key to the same array. | spec-phase-1.md §2 |
| `Authkit\Authkit\Auth\AccessTokenClaims` | Readonly DTO with `?string $organizationId` (from the `org_id` claim), decoded via `Authkit\Authkit\Auth\JwtPayloadDecoder::decode()`. | spec-phase-2.md Component 1 |
| `Authkit\Authkit\Contracts\HasAccessTokenClaims` | Interface: `accessTokenClaims(): ?array`, implemented by the `workos` guard. Already consumed by Phase 5's `FgaChecker::currentOrganizationIdFromClaims()`. **Caveat**: this is a written Phase 5 spec commitment, not landed code — spec-phase-5.md §4.1 itself only *assumes* the guard implements this and says to "add the implementation as part of this phase's work" if it doesn't. Per spec-phase-2.md's actual landed signature (`final class WorkosGuard implements Guard`, no `accessTokenClaims()` method), the guard does not yet implement it. This phase does not take a hard compile-time dependency on the interface existing — see Interface Reconciliation item 9. | spec-phase-5.md §4.1, §4.4 |
| `Authkit\Authkit\Events\Login` | Dispatched by `AuthKitAuthenticationRequest::authenticate()` after a successful callback exchange. Carries `$user` and the SDK's `AuthenticateResponse` (`$response->accessToken`, `$response->user`, etc.). | spec-phase-2.md Component 4, File Changes |
| `Authkit\Authkit\Http\Requests\AuthKitLoginRequest::redirect(?string $intendedUrl = null)` | Public form request method: builds a PKCE authorization URL via `UserManagement::getAuthorizationUrl()` and redirects. This phase adds an additive `?string $organizationId = null` parameter — see Interface Reconciliation and File Changes. | spec-phase-2.md Component 4 |
| `\WorkOS\Service\UserManagement::getAuthorizationUrl(..., ?string $organizationId = null, ...)` | Confirmed signature (`vendor/workos/workos-php/lib/Service/UserManagement.php:484-522`) — the SDK itself already supports an org hint at authorize time. | vendored SDK |
| `\WorkOS\SessionManager::refresh(string $sessionData, string $cookiePassword, string $clientId, ?string $organizationId = null): array` | Confirmed signature (`SessionManager.php:200-256`). Returns `['authenticated' => true, 'sealed_session' => ..., 'session_id' => ..., 'user' => ..., 'impersonator' => ...]` or `['authenticated' => false, 'reason' => ...]`. | vendored SDK |
| `Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId` | Interface: `resolve(Authenticatable $user, string $organizationId): ?string`. Phase 5 ships `NullMembershipResolver` as the default, bound via `bindIf()` reading the concrete class name from `config('authkit.authorization.membership_resolver')`. This phase supplies the real implementation and changes only the config default — Phase 5's provider code is untouched. **Caveat**: the interface file itself, plus the `bindIf()` registration and the `authorization.membership_resolver` config key, are Phase 5 deliverables (spec-phase-5.md §6, §7) — not yet landed code if Phase 5 hasn't executed. This phase must be able to define them itself when it runs first. See Interface Reconciliation item 9. | spec-phase-5.md §3a, §4.4, §7 |
| Middleware alias table (`authkit.session`, `authkit.org`, `authkit.mcp`) | `authkit.org` is this phase's alias to register; the name is already treated as canonical by Phase 4's and Phase 13's shared-conventions expectations. | spec-phase-4.md, spec-phase-13.md |
| Route name `authkit.switch-org` | Already treated as canonical by Phase 13's route-name table. | spec-phase-13.md |

## Technical Approach

Organizations & Org Context has two distinct halves that this spec keeps deliberately separate because they run in opposite directions:

1. **Local → remote (the trait).** An app creates a local Eloquent row for its own org/team/workspace concept (`Organization`, `Team`, whatever it calls it) and wants a matching WorkOS organization to exist, linked by `workos_id` locally and `external_id` remotely. `HasWorkosOrganization` + a model Observer + two queued Jobs (`CreateWorkosOrganization`, `DeleteWorkosOrganization`) handle this direction, mirroring the shape Phase 2 already established for users (`HasWorkosUser`) and Phase 5 established for FGA resources (`HasWorkosResource`): a trait with a boot hook, idempotent by construction, fire-and-forget from the app's point of view.

2. **Remote → local (the login-time projection).** A user can show up with an `org_id` JWT claim for an organization and membership that already exist in WorkOS — created via the WorkOS dashboard, a directory-sync provision, an invitation accept, or another app entirely — with no local row for either. Waiting for Phase 4's events poller to notice and backfill this would leave `$user->organizations()` empty and `Authkit::check()` throwing `MembershipNotResolvedException` for as long as `poll_interval` takes, which is wrong for the very first thing a freshly-authenticated user does. A listener on Phase 2's `Login` event closes this gap synchronously, at login time, before the response is even returned.

Both halves write into the same two declared projection tables this phase introduces — `workos_organization_domains` and `workos_memberships` — which are populated from **three** independent paths over the org's lifetime: this phase's login-time listener (immediate, narrow), Phase 4's events pipeline (eventual, comprehensive), and nothing else. Neither table stores a local foreign key to the org model's physical table, because that table's name is configured per-app (`config('authkit.organization.model')`) and is not known at package-migration-authoring time; every cross-reference is a plain WorkOS ID string, joined via Eloquent's custom-key relation support rather than a database-level `FOREIGN KEY` constraint. This is not a stylistic choice — it is the only way a package can ship a fixed-shape migration that links to an app-configured, arbitrarily-named table.

The third component, current-org resolution and the org-switch/tenant-middleware trio, is the "living in an org" ergonomics layer: a request-scoped resolver reading the `org_id` claim (mirroring Phase 5's claims-reading pattern, duck-typed rather than interface-typed against Phase 5's `HasAccessTokenClaims` — see Interface Reconciliation item 9), a `Request::organization()` macro and `Authkit::currentOrganization()` accessor built on top of it, a tenant-requiring middleware, and an org-switch route that calls the SDK's own `SessionManager::refresh(organizationId: ...)` hint with a re-authorize fallback when refresh can't satisfy the switch.

## Decisions Considered and Rejected

_Carried from the contract; every entry that plausibly touches organizations, projections, sessions, or the SDK relationship is included._

- **Full org context in v1: claims-resolved current org, org-switch route via AuthKit re-auth, tenant middleware** — rejected: read-only org context, apps build their own switcher. Reason: multi-org ergonomics are table stakes for the Team/Workspace apps the org trait targets. This is this phase's charter, not background context.
- **Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id` ↔ `external_id` linking, refreshed by the events pipeline** — rejected: no local state / read-through API calls per request. Reason: Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events. This phase is two of the four declared projections (org, domains, memberships) plus the login-time refresh path that supplements the events pipeline for the org/membership pair specifically.
- **Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state; app's Laravel session stays free for app state** — rejected: exchange code then hydrate Laravel's standard session guard. Reason: WorkOS must remain the session source of truth for authn and authz. Org-switch calls `SessionManager::refresh()` directly against the sealed cookie, never touching Laravel's session for org state.
- **Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage** — rejected: SDK fakes only. Reason: wire fidelity where possible; emulate v0.6.0's organizations/domains/webhooks coverage is solid per the context brief, so the trait/observer/login-listener/switch-happy-path suites are emulate-backed; failure-injection cases (WorkOS down, conflict races, refresh rejection) are MockHandler-backed since emulate cannot be told to fail on demand outside its own `/_emulate/hooks` mechanism, which this phase does not depend on.
- **Credentials read from config only; env is never read outside config files** — rejected: runtime `env()` reads. Reason: `config:cache` empties env at runtime. Every new config key this phase adds lives under `config('authkit.organization.*')` and is read via `config()` in `src/`, never `env()`.
- **API Keys Guard and Connect & MCP phases depend on Organizations & Org Context** — rejected: original auth-core-only prereq graph. Reason: `ApiKeys::createOrganizationApiKey` and `Connect::createM2MApplication` both require a non-null `organizationId` at the SDK signature level. Directly explains why Phase 8's and Phase 10's org-model config lookups exist and why this phase's Interface Reconciliation section corrects their assumed config key.
- **Phase 1 ends with an empirical AuthKit token audit: decode a real AuthKit-issued token to confirm canonical `iss`/`aud` values and default presence of `role`/`permissions`/`feature_flags` claims** — rejected: assume the SDK's TODO values. Reason: hidden-dependency blocker. Directly relevant here too: this phase's login-time listener and current-org resolver both assume `org_id` rides the claims by default with no dashboard configuration — the same unconfirmed-until-the-audit assumption Phase 5 already flagged for `role`/`permissions`. Carried forward as an Open Item, not re-litigated.
- **Directory Sync: prefer WorkOS-managed directory provisioning; ship events-pipeline listener recipes for custom mapping; no dedicated module** — rejected: full dsync provisioning module. Reason: most apps need zero dsync code. Directly relevant: a directory-provisioned user showing up with an `org_id` claim for a membership this app has never seen is exactly the scenario the login-time listener exists to handle gracefully, without this phase building any dsync-specific logic.
- **Typed sidecar events are bounded to types feeding the declared projections + audit/domain-verification; everything else dispatches a generic `WorkosEvent`** — rejected: a typed class per event type. Reason: not directly this phase's mechanism (that's Phase 4's), but this phase's projection tables are exactly the ones those typed events must be able to write into — the table/column shapes below are the contract Phase 4's listeners must target.
- **FGA ships without caching — direct Check API per check; opt-in caching with events-driven invalidation is Full tier** — rejected: default per-check cache in MVP. Reason: not directly this phase, but `MembershipProjectionResolver` (this phase's contribution to Phase 5's seam) resolves a membership ID from the *local projection*, not a live API call — this is not the caching the contract forbids (that's about the Check API's authorization *result*, not the ID lookup needed to make the call in the first place).
- **Quickstart criterion split into a mechanical ≤5-step doc check plus a recorded human timing trial logged in release notes; projection-boundary arch test added** — rejected: single judgment-only quickstart criterion. Reason: this phase's two new tables (`workos_organization_domains`, `workos_memberships`) are two of the five entries the projection-boundary arch test (Phase 13) will whitelist; getting their names and shapes right here is what makes that test pass instead of needing a post-hoc fixup.
- **Stay on Pest 4 with PHP ^8.3 floor** — rejected: Pest 5. Reason: PHP 8.3 supported until Dec 2027. Governs this phase's test suite conventions (no Pest 5 APIs).
- **v1 targets the Full tier: MVP's 16 areas plus the 5 depth extensions** — rejected: MVP-only v1. Reason: stakeholder tier selection. Not directly this phase's scope (Groups API / FGA resource-graph conveniences are Phase 12's), but Phase 12's `HasWorkosOrganization`-adjacent depth work builds on top of this phase's trait and tables without modifying them.
- **Express run executes directly on main (no isolation branch); recovery anchor recorded: `git reset --hard 4d04d0b`** — rejected: isolation branch. Process note: commit directly to `main`.

## Interface Reconciliation

Five sibling specs (Phases 2, 4, 5, 8, 13) were written concurrently with or before this one and each made assumptions about Phase 3's interfaces. This section names every place a sibling's assumption does not match what this phase actually delivers, and states which spec must adjust.

### 1. Org model config key — Phase 4 and Phase 8 must adjust

- **Confirmed (Phase 1, landed)**: `config('authkit.organization.model')` — singular `organization`, nested `model`.
- **Phase 4 assumed**: `config('authkit.organizations.model')` (plural) — spec-phase-4.md line 18, line 632, and Open Items.
- **Phase 8 assumed**: `config('authkit.organization_model')` (flat, no nesting) — spec-phase-8.md §0, §3.1 (`ApiKeyAuthenticator::resolveOrganizationActor()`), and its `MissingModelConfigurationException::forOrganizationModel()` message string.
- **Resolution**: both must change every reference to `config('authkit.organization.model')`. For Phase 8 specifically, this also means correcting the literal config-key string inside `MissingModelConfigurationException::forOrganizationModel()`'s error message (currently names the wrong key, which defeats the "actionable exception naming the config key" design goal that same spec states as its own requirement).

### 2. Projection table/model names and shape — Phase 4 must adjust

- **Confirmed (this phase)**: `Authkit\Authkit\Models\WorkosOrganizationDomain` (table `workos_organization_domains`), `Authkit\Authkit\Models\WorkosMembership` (table `workos_memberships`). Both confirmed independently by Phase 13's `ProjectionBoundaryTest` whitelist, which already names these exact table strings.
- **Phase 4 assumed**: `Authkit\Authkit\Models\OrganizationDomain` (table `organization_domains`) and `Authkit\Authkit\Models\OrganizationMembership` (table `organization_memberships`) — spec-phase-4.md Prerequisites table and Component 8.
- **Resolution**: Phase 4 must rename its eight projection-refresh listeners' target classes/tables for the domain and membership pair (`UpsertOrganizationDomainProjection`, `DeleteOrganizationDomainProjection`, `UpsertOrganizationMembershipProjection`, `DeleteOrganizationMembershipProjection`) to `WorkosOrganizationDomain`/`WorkosMembership`. It must also change its column assumptions: this phase's `workos_memberships` columns are `workos_id`, `organization_id` (WorkOS org ID string), `user_id` (WorkOS user ID string), `role` (slug string), `status` (string); Phase 4's listener bodies should key upserts on `workos_id` (the resource's own WorkOS ID from `$event->resourceId()`), which already matches Phase 4's own stated convention — only the class/table names and the membership row's column names need correcting.
- **Also corrects Phase 4's user/org listener bodies**: neither `workos_organization_domains` nor `workos_memberships` (nor the org model itself) stores a local `external_id` column — see reconciliation item 3.

### 3. `external_id` is remote-only, never a local column — Phase 4 must adjust

- **Confirmed (Phase 2 precedent, followed here)**: the User model stores only `workos_id` locally; `external_id` is set on the *remote* WorkOS user via `updateUser(externalId: ...)`, and its value is always the local model's own primary key — it is never independently persisted, because it is always derivable.
- **This phase applies the identical pattern to organizations**: the org model gains only a `workos_id` column (app-added, documented below); `external_id` is set on the *remote* WorkOS organization at `createOrganization(externalId: ...)` time, equal to `(string) $organization->getKey()`, and is never stored locally.
- **Phase 4 assumed** (Prerequisites table, and Phase 13's Standalone-Implementability table copying the same phrasing) that the org model has "`workos_id` (unique) and `external_id` columns."
- **Resolution**: Phase 4's `UpsertOrganizationProjection` listener must write only `workos_id` from the event payload; it must not attempt to read or persist an `external_id` column on the org model, because none exists.

### 4. Current-org exposure to controllers — Phase 13 must adjust

- **Confirmed (this phase)**: `$request->organization(): ?Model` — a `Request` macro, resolvable on any authenticated route without requiring the `authkit.org` middleware. Equivalently, `Authkit::currentOrganization(): ?Model`.
- **Phase 13 assumed**: `DashboardController` reads `$request->attributes->get('current_organization')` — a raw attribute-bag key that nothing in this spec ever populates outside routes explicitly wrapped in the `authkit.org` middleware (which most dashboard-style routes should *not* require, since a user without an org yet still needs to see a dashboard).
- **Resolution**: Phase 13's `DashboardController` (and any other workbench controller reading that same placeholder attribute) must call `$request->organization()` instead. This is a one-line change per call site; the response JSON shape Phase 13 already specifies (`'organization' => ...`) is unaffected — only the right-hand side of the assignment changes.

### 5. FGA membership resolver seam — no sibling change needed (resolution, not conflict)

Phase 5 shipped `NullMembershipResolver` as the default for `config('authkit.authorization.membership_resolver')` and explicitly designed the binding (`bindIf()`, config-driven class name) to accept a real implementation with zero changes to Phase 5's own files once Phase 3 landed. This phase supplies `Authkit\Authkit\Organizations\MembershipProjectionResolver` and changes only the **default value** of that one config key in `config/authkit.php`. Phase 5's Open Item asking "should Organizations be added to this phase's prereqs, or should a follow-up bind the real resolver" is answered by this phase directly: the follow-up is this phase, and no prereq-graph edit is needed since the seam was built to be non-blocking by design.

### 6. Phase 8's organization actor resolution — already covered by item 1

Phase 8's `WorkosApiKeyActor`/`ApiKeyAuthenticator::resolveOrganizationActor()` needs nothing new from this phase beyond the config-key fix already named in item 1 — its assumption that a local org row is keyed by `workos_id` is already correct and requires no change.

### 7. Vault's `workosOrganizationId()` duck-type — no sibling change needed (resolution, not conflict)

Phase 9 invented a duck-typed convention — any model exposing `workosOrganizationId(): ?string` gets automatic org-scoped Vault key-context isolation — specifically because Phase 3 didn't exist yet to confirm a real method. `HasWorkosOrganization` (this phase) implements exactly that method, with exactly that signature (`?string`, not `string`), on the org model. Once both phases are merged, Vault's org-awareness activates for any model using this trait with zero additional wiring. Phase 9's file needs no change.

### 8. WorkOS client access pattern — named for completeness, does not block this phase

Phase 2 assumed a directly container-bound `\WorkOS\WorkOS` singleton (`app(\WorkOS\WorkOS::class)`); Phase 1 (landed, authoritative) instead binds `Authkit\Authkit\Contracts\WorkosClientManager` with a `client()` accessor method. This phase resolves the SDK client exclusively through Phase 1's real, confirmed interface (`app(Authkit\Authkit\Contracts\WorkosClientManager::class)->client()->organizations()`, etc.) everywhere, including in the org-switch controller's call to `SessionManager::refresh()` (via `->client()->sessionManager()`). This is not a blocker for this phase — it has no dependency on Phase 2's client-access code, only on Phase 2's `Login` event and `AuthKitLoginRequest::redirect()` — but is flagged here since Phase 2's own header already names this same reconciliation as outstanding, and this phase's org-switch fallback path is the first place in the codebase that needs both Phase 2's login-request class *and* the SDK's `SessionManager` in the same call, making the mismatch newly visible if left unresolved.

### 9. Compile-time dependency on Phase 5's `HasAccessTokenClaims`/`ResolvesOrganizationMembershipId` — no sibling change needed (this phase self-adjusts)

Two of this phase's own Confirmed Foundations rows (`HasAccessTokenClaims`, `ResolvesOrganizationMembershipId`) are Phase 5 spec commitments, not landed code, and the contract's prereq graph does not encode the dependency: `contract-data.json`'s `execution.phases` lists only "Auth Core & Sealed Sessions" (Phase 2) as this phase's prereq, and Phase 5 ("Authorization (RBAC + FGA)") is `blocking: false` with no edge to this phase at all. Nothing prevents — and the critical path (this phase blocks Phase 4; Phase 5 does not) actively encourages — an orchestrator running this phase before Phase 5. If that happens, two components as originally drafted break at runtime, not at review time:

- **Component 9** (`CurrentOrganizationResolver::currentOrganizationIdFromClaims()`) did `$guard instanceof HasAccessTokenClaims`. `instanceof` against a class/interface name triggers PHP's autoloader; if `src/Contracts/HasAccessTokenClaims.php` doesn't exist yet (it's Phase 5's job to add it — spec-phase-5.md §4.1: "if it doesn't yet implement `HasAccessTokenClaims`, add the implementation as part of this phase's work"), the check throws a fatal `Error`, not a graceful `null`. `CurrentOrganizationTest.php` and `RequireOrganizationContextTest.php` (which depends on `CurrentOrganizationResolver` transitively) fail before a single assertion runs.
- **Component 7** (`MembershipProjectionResolver implements ResolvesOrganizationMembershipId`) requires that interface's file to exist at class-load time — it's a Phase 5 file per spec-phase-5.md §6's File Changes table ("`src/Contracts/ResolvesOrganizationMembershipId.php` | Dependency contract on Phase 3's memberships projection"). If Phase 5 hasn't landed, `MembershipProjectionResolverTest.php` fails with "Interface ... not found."

**Resolution — this phase is made order-independent, rather than editing the contract's prereq graph**:

- **Component 9 duck-types instead of type-checking.** `currentOrganizationIdFromClaims()` uses `method_exists($guard, 'accessTokenClaims')` instead of `$guard instanceof HasAccessTokenClaims`. This drops the `use` of Phase 5's interface entirely — no autoload risk either way — and keeps working unchanged once Phase 5 lands and the guard genuinely implements the interface, since a method-exists check is a safe superset of an instanceof check for this single-method contract. See Component 9's updated code below.
- **Component 7 defines the interface itself, conditionally.** Before authoring `MembershipProjectionResolver`, check whether `src/Contracts/ResolvesOrganizationMembershipId.php` already exists (Phase 5 landed first). If it does, this phase changes nothing about it — only the config default's *value*, exactly as item 5 above already describes. If it doesn't (this phase is landing first), this phase hand-authors that interface file itself, verbatim per spec-phase-5.md §4.4's shape, adds the `bindIf()` registration to `AuthkitServiceProvider::register()`, and sets `authorization.membership_resolver`'s default straight to `MembershipProjectionResolver::class` (no `NullMembershipResolver` fallback needed, since a real resolver already exists) — Phase 5, landing second, must then diff its own File Changes against what this phase already shipped and reconcile rather than redefine, the same discipline this spec already asks of Phase 4 elsewhere (see Open Items). See Component 7's updated implementation steps below.

Both changes keep this phase's own test suite green regardless of execution order, without requiring `contract-data.json` to gain a new edge — carried forward as a numbered Open Item for orchestration visibility below, not silently absorbed.

### 10. Phase 13's `workos_memberships` table assumption — Phase 13 must adjust

- **Confirmed (this phase, Implementation Details §1)**: `workos_memberships` columns are `workos_id`, `organization_id`, `user_id`, `role`, `status` — no `external_id` column exists on this table at all, for the identical reason item 3 above gives for the org model's own table: `external_id` is a remote-only WorkOS field, never a local column.
- **Phase 13 assumed** (spec-phase-13.md's Standalone-Implementability table, line 46): "`workos_memberships` table — Local org-membership projection, `workos_id`/`external_id` present ... column list assumed."
- **Resolution**: Phase 13 must drop the `external_id` half of that assumption — the table carries only `workos_id`, not `workos_id`/`external_id`. This is functionally inert today (Phase 13's `ProjectionBoundaryTest` whitelist check, referenced by item 2 above, is column-presence-based and `workos_memberships` already carries `workos_id`, so no test breaks either way), but the documented column shape in Phase 13's own table is still wrong and should not ship uncorrected into `docs/quickstart.md` or any other Phase 13 artifact that repeats it. Item 3 above already makes this same correction for the org model's own table; this item extends it to the `workos_memberships` projection specifically, since Phase 13's Standalone-Implementability table names it as its own, separate row.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest --filter=HasWorkosOrganization` (seconds when scoped to the MockHandler-backed race/failure cases; the emulate-backed happy-path cases in the same file take longer — see Secondary loop).

**Secondary loop**: `vendor/bin/pest --filter="CurrentOrganization|OrganizationSwitch|LoginProjectionUpsert|MembershipProjectionResolver|RequireOrganizationContext"` — the rest of this phase's suites, run before considering the phase done, not on every edit.

**Playground**: Pest feature suites are primary, split the same way Phase 5 split its own suite: `npx @workos/emulate` (Phase 1's harness) for the trait's happy path, org-switch happy path, and the login-time listener's happy path; Guzzle `MockHandler` (via Phase 1's `UsesWorkosMockHandler` trait) for every failure-injection case (WorkOS down, conflict race, refresh rejection) that emulate cannot be told to produce on demand. `composer serve` (Testbench workbench, pointed at a locally running emulate) is the secondary playground for creating a workbench `Organization` row via `tinker` and watching the queued job land a `workos_id`.

**Why this approach**: every component in this phase reduces to "read or write one Eloquent row, then make at most one or two SDK calls" — there is no long-running process, no signal handling, and no cryptography here (unlike Phase 2/4), so a Pest suite split cleanly along the emulate/MockHandler line gives fast, precise feedback on exactly the failure-injection cases (races, outages, missing models) that matter most for this phase's named failure modes.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `database/migrations/2026_05_01_000000_create_workos_organization_domains_table.php` | Domains projection table |
| `database/migrations/2026_05_01_000001_create_workos_memberships_table.php` | Org-membership projection table |
| `src/Models/WorkosOrganizationDomain.php` | Eloquent model wrapping `workos_organization_domains` |
| `src/Models/WorkosMembership.php` | Eloquent model wrapping `workos_memberships` |
| `src/Concerns/HasWorkosOrganization.php` | Trait for the app's org model: boot-registers the observer, `workosOrganizationName()`/`workosOrganizationId()`, `domains()`/`memberships()` relations |
| `src/Concerns/BelongsToWorkosOrganizations.php` | Trait for the app's User model: `organizations(): BelongsToMany` via the `workos_memberships` projection |
| `src/Observers/WorkosOrganizationObserver.php` | Eloquent observer: `created()` dispatches `CreateWorkosOrganization`, `deleted()` dispatches `DeleteWorkosOrganization` (configurable) |
| `src/Jobs/CreateWorkosOrganization.php` | Idempotent remote-org creation: `getOrganizationByExternalId` lookup before create, retries + backoff, no-ops if already synced or if the local row vanished before the job ran |
| `src/Jobs/DeleteWorkosOrganization.php` | Remote-org deletion by WorkOS ID (captured at observer time, never re-derived from a possibly-deleted local row) |
| `src/Events/OrganizationSyncFailed.php` | Acknowledgment event dispatched from either job's `failed()` hook |
| `src/Organizations/CurrentOrganizationResolver.php` | Request-scoped resolver: `org_id` claim → local org model via `workos_id`, memoized |
| `src/Organizations/MembershipProjectionResolver.php` | Implements `ResolvesOrganizationMembershipId`, reading the `workos_memberships` projection |
| `src/Contracts/ResolvesOrganizationMembershipId.php` | **Order-dependent** — only authored by this phase if Phase 5 hasn't landed yet (see Component 7 step 0, Interface Reconciliation item 9). If Phase 5 already shipped this file, this phase does not touch it. |
| `src/Listeners/UpsertOrganizationAndMembershipFromLogin.php` | Listens to Phase 2's `Login` event; synchronously backfills the org + membership projection rows from claims when they don't yet exist locally |
| `src/Http/Middleware/RequireOrganizationContext.php` | The `authkit.org` middleware: aborts (403) or redirects when no current org resolves |
| `src/Http/Controllers/SwitchOrganizationController.php` | `authkit.switch-org`: calls `SessionManager::refresh(organizationId: ...)`, falls back to a re-authorize redirect |
| `workbench/app/Models/Organization.php` | Workbench org model fixture using `HasWorkosOrganization` |
| `workbench/database/migrations/2026_05_01_000002_create_organizations_table.php` | Workbench-only: `id`, `name`, `workos_id` (nullable, unique) |
| `workbench/database/factories/OrganizationFactory.php` | Factory: `name` only — `workos_id` is populated by the trait's observer, never set by the factory |
| `tests/Feature/HasWorkosOrganizationTest.php` | Create → remote org created (emulate); idempotent retry; create-vs-create race (MockHandler); delete → configurable remote deletion |
| `tests/Feature/OrganizationSyncFailedTest.php` | WorkOS-down retries/backoff/failed event (MockHandler); model-deleted-before-job-runs no-op (MockHandler) |
| `tests/Feature/DomainsAndMembershipsProjectionTest.php` | Migration shape; `domains()`/`memberships()`/`organizations()` relations against manually-seeded rows, no SDK calls |
| `tests/Feature/LoginProjectionUpsertTest.php` | Login with a pre-existing-in-WorkOS org+membership never seen locally → both rows backfilled (emulate); no `org_id` claim → no-op; WorkOS failure during backfill → login still succeeds (MockHandler) |
| `tests/Feature/MembershipProjectionResolverTest.php` | `resolve()` against seeded rows; integration with Phase 5's `Authkit::check()` |
| `tests/Feature/CurrentOrganizationTest.php` | `Authkit::currentOrganization()` / `$request->organization()` parity; memoization; null cases |
| `tests/Feature/RequireOrganizationContextTest.php` | `authkit.org` middleware: pass-through, abort, redirect, misconfigured-redirect failure |
| `tests/Feature/OrganizationSwitchTest.php` | Successful switch (emulate); refresh-rejected fallback to re-authorize (MockHandler) |

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/AuthkitServiceProvider.php` | `register()`: `$this->app->singleton(CurrentOrganizationResolver::class)`. `boot()`: `$this->app['router']->aliasMiddleware('authkit.org', RequireOrganizationContext::class)`; `Illuminate\Http\Request::macro('organization', fn () => app(CurrentOrganizationResolver::class)->resolve())`; `Event::listen(\Authkit\Authkit\Events\Login::class, UpsertOrganizationAndMembershipFromLogin::class)`. **Conditionally** (Interface Reconciliation item 9, Component 7 step 0), only if Phase 5 hasn't landed yet: `register()` also gains `$this->app->bindIf(ResolvesOrganizationMembershipId::class, config('authkit.authorization.membership_resolver', MembershipProjectionResolver::class))`. |
| `src/Authkit.php` | Add `currentOrganization(): ?Model` method, delegating to `app(CurrentOrganizationResolver::class)->resolve()` — same container-resolution convention Phase 5 established for its own manager accessors. |
| `src/Facades/Authkit.php` | Add `@method static ?\Illuminate\Database\Eloquent\Model currentOrganization()` to the docblock. |
| `config/authkit.php` | Add new keys under the existing `organization` section (`sync_mode`, `delete_remote_on_delete`, `retry.tries`, `retry.backoff`, `middleware.on_missing`, `middleware.redirect_route`); add `switch_organization` to `routes.paths`; change `authorization.membership_resolver`'s default from `NullMembershipResolver::class` to `MembershipProjectionResolver::class`. Full diff under Implementation Details. |
| `routes/authkit-laravel.php` | Add the `authkit.switch-org` route, gated by `config('authkit.routes.enabled')`, inside the existing `web`-middleware-grouped block Phase 2 established. |
| `src/Http/Requests/AuthKitLoginRequest.php` (Phase 2) | `redirect()` gains an additive `?string $organizationId = null` trailing parameter, threaded into `getAuthorizationUrl(organizationId: ...)`. Default `null` preserves every existing call site (Phase 2's own `AuthKitController::login()`) unchanged. |
| `workbench/app/Models/User.php` | Add `use BelongsToWorkosOrganizations;` alongside Phase 2's `use HasWorkosUser;`. |
| `tests/TestCase.php` | Add `authkit.organization.model` to the package's own test-environment config (pointing at a test fixture org model — see Testing Requirements) so package-level Pest suites don't depend on the workbench's `Organization` model existing. |

### Deleted Files

None.

## Implementation Details

### 1. Projection Migrations (`workos_organization_domains`, `workos_memberships`)

**Pattern to follow**: `database/migrations/2026_04_01_000001_create_workos_event_cursor_table.php` (Phase 4's anonymous-class migration style) — the same style, no FK constraints, since neither table's parent (the org model) has a package-known table name.

**Overview**: Both tables are pre-approved by the contract's projection-boundary decision ("declared projections... org domains, memberships"). Every cross-table reference is a plain WorkOS ID string column with a non-unique index, never a database `FOREIGN KEY` — the org model's physical table name is app-configured and unknowable at migration-authoring time.

```php
// database/migrations/2026_05_01_000000_create_workos_organization_domains_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workos_organization_domains', function (Blueprint $table) {
            $table->id();
            $table->string('workos_id')->unique();
            $table->string('organization_id')->index(); // WorkOS organization ID — not a local FK
            $table->string('domain');
            $table->string('state')->nullable(); // opaque WorkOS-owned string — see Failure Modes #5
            $table->string('verification_prefix')->nullable();
            $table->string('verification_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workos_organization_domains');
    }
};
```

```php
// database/migrations/2026_05_01_000001_create_workos_memberships_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workos_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('workos_id')->unique(); // the membership's own WorkOS ID (om_...)
            $table->string('organization_id'); // WorkOS organization ID
            $table->string('user_id'); // WorkOS user ID
            $table->string('role')->nullable(); // role slug
            $table->string('status')->default('active'); // active|inactive|pending
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workos_memberships');
    }
};
```

**Key decisions**:

- `workos_id` is the only unique constraint on either table. A composite unique on `(organization_id, user_id)` was considered and rejected: WorkOS's own `createOrganizationMembership` reactivates a matching `inactive` membership under its *existing* ID rather than minting a new one, so in the common case there is at most one row per pair anyway — but if a membership is ever hard-deleted and a genuinely new one later created for the same pair (a new `workos_id`), a composite unique would collide with a stale row whose `.deleted` event was missed, turning a rare edge case into a hard database error instead of a harmless extra row that Phase 4's `DeleteOrganizationMembershipProjection`-equivalent listener will eventually clean up. Every projection listener in this project (Phase 4's pattern) upserts keyed on the resource's own `workos_id`, and this table follows that same, already-established convention rather than inventing a second one.
- No `state`/`status` enum-casts — both columns are plain nullable/defaulted strings. `OrganizationDomainState` is a confirmed 5-case backed enum in the vendored SDK (`failed`, `legacy_verified`, `pending`, `unverified`, `verified`) when read from `getOrganizationDomain`/`createOrganizationDomain`/`verifyOrganizationDomain` responses — but the `organization_domain.verification_failed` **event**'s payload shape (confirmed by reading `OrganizationDomainVerificationFailedData`) carries no top-level `state` at all; it carries `reason` (a 2-case enum: `domain_verification_period_expired`, `domain_verified_by_other_organization`) plus a nested `organizationDomain.state`. Casting this column to a PHP enum would force Phase 4's listener to normalize two structurally different payload shapes into one column before it could ever write — storing the raw string keeps the column able to receive whatever WorkOS actually sends from either payload shape without a code change on either side. This resolves half of the phase-specific "domain verification state strings are UNVERIFIED" concern with a confirmed enum (see also Open Items) while keeping the schema itself opaque-by-design regardless.

**Implementation steps**:

1. `php artisan make:migration create_workos_organization_domains_table` then replace the generated body with the schema above (fixed table name, not the pluralized-model default).
2. `php artisan make:migration create_workos_memberships_table` likewise.
3. Confirm `AuthkitServiceProvider::boot()` already calls `loadMigrationsFrom(__DIR__.'/../database/migrations')` (added by Phase 2) — no provider change needed for these migrations to auto-run under Testbench; the existing `publishesMigrations()` call (unchanged since the original skeleton) already publishes the whole `database/migrations/` directory to consumer apps.

**Feedback loop**: Skipped — schema-only, no branching logic. Correctness is exercised through Component 2's and Component 4/5's feature suites, which assert against the migrated tables' actual shape.

### 2. `WorkosOrganizationDomain` and `WorkosMembership` Models

**Pattern to follow**: `src/Models/WorkosEventCursor.php` (Phase 4) — minimal Eloquent wrapper, `protected $guarded = []`, no relations of its own (relations live on the *other* side, per Component 3/6).

```php
// src/Models/WorkosOrganizationDomain.php
final class WorkosOrganizationDomain extends Model
{
    protected $table = 'workos_organization_domains';
    protected $guarded = [];
}
```

```php
// src/Models/WorkosMembership.php
final class WorkosMembership extends Model
{
    protected $table = 'workos_memberships';
    protected $guarded = [];
}
```

**Key decisions**: Both are deliberately thin — no casts, no scopes, no relation methods here. `MembershipProjectionResolver` (Component 9) queries `WorkosMembership` directly; the org-side and user-side relations live on the trait/model that actually needs them (Components 3 and 6), not duplicated here, so there is exactly one place each relation is defined.

**Implementation steps**: `php artisan make:model WorkosOrganizationDomain`, `php artisan make:model WorkosMembership`, then trim each generated stub to the shape above.

**Feedback loop**: Skipped — thin wrappers, no logic of their own. Exercised transitively by every other component's tests.

### 3. `HasWorkosOrganization` Trait + `WorkosOrganizationObserver`

**Pattern to follow**: `src/Concerns/HasWorkosResource.php` (Phase 5) for the trait-with-boot-hook shape; this phase uses a dedicated Observer class instead of inline `static::created()`/`static::deleted()` closures specifically because the phase direction calls for "trait + model observer" as two distinguishable pieces, and because the create/delete logic here (idempotency lookup, configurable sync mode, configurable remote-deletion opt-out) is meaningfully heavier than Phase 5's two-line resource sync.

**Overview**: Applying this trait to an app's org model registers an Observer at class-boot time. The Observer's `created()`/`deleted()` hooks dispatch Jobs rather than calling the SDK inline, so a slow or failing WorkOS call never blocks the request that created the local row.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Models\WorkosOrganizationDomain;
use Authkit\Authkit\Observers\WorkosOrganizationObserver;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasWorkosOrganization
{
    protected static function bootHasWorkosOrganization(): void
    {
        static::observe(WorkosOrganizationObserver::class);
    }

    /**
     * The name sent to WorkOS when this organization is created remotely.
     * Override to customize name resolution — default assumes a `name`
     * attribute exists, falling back to the model's own key.
     */
    public function workosOrganizationName(): string
    {
        return (string) ($this->name ?? $this->getKey());
    }

    /**
     * Duck-typed org-awareness hook Phase 9's Vault key-context resolver
     * looks for (`method_exists($model, 'workosOrganizationId')`). Returns
     * null, not throws, when this organization hasn't synced remotely yet.
     */
    public function workosOrganizationId(): ?string
    {
        return $this->workos_id;
    }

    public function domains(): HasMany
    {
        return $this->hasMany(WorkosOrganizationDomain::class, 'organization_id', 'workos_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkosMembership::class, 'organization_id', 'workos_id');
    }
}
```

```php
declare(strict_types=1);

namespace Authkit\Authkit\Observers;

use Authkit\Authkit\Jobs\CreateWorkosOrganization;
use Authkit\Authkit\Jobs\DeleteWorkosOrganization;
use Illuminate\Database\Eloquent\Model;

final class WorkosOrganizationObserver
{
    public function created(Model $organization): void
    {
        $this->dispatch(new CreateWorkosOrganization($organization));
    }

    public function deleted(Model $organization): void
    {
        if (! (bool) config('authkit.organization.delete_remote_on_delete', true)) {
            return;
        }

        $workosId = $organization->getAttribute('workos_id');
        if ($workosId === null) {
            return; // never synced remotely — nothing to delete there
        }

        $this->dispatch(new DeleteWorkosOrganization((string) $workosId));
    }

    private function dispatch(object $job): void
    {
        if (config('authkit.organization.sync_mode', 'queue') === 'sync') {
            dispatch_sync($job);

            return;
        }

        dispatch($job);
    }
}
```

**`config/authkit.php` additions** (extends the existing `organization` section Phase 1 declared; also the `switch_organization` route path and the membership-resolver default change, since this component is the first to touch `config/authkit.php` in this phase):

```php
'organization' => [
    'model' => env('AUTHKIT_ORGANIZATION_MODEL'),               // Phase 1
    'external_id_column' => 'workos_id',                         // Phase 1 (declared, unused — see Confirmed Foundations)

    'sync_mode' => env('AUTHKIT_ORGANIZATION_SYNC_MODE', 'queue'), // 'queue'|'sync'
    'delete_remote_on_delete' => (bool) env('AUTHKIT_ORGANIZATION_DELETE_REMOTE_ON_DELETE', true),

    'retry' => [
        'tries' => (int) env('AUTHKIT_ORGANIZATION_SYNC_TRIES', 5),
        'backoff' => [10, 30, 60, 300, 900], // seconds
    ],

    'middleware' => [
        'on_missing' => env('AUTHKIT_ORGANIZATION_MIDDLEWARE_ON_MISSING', 'abort'), // 'abort'|'redirect'
        'redirect_route' => env('AUTHKIT_ORGANIZATION_MIDDLEWARE_REDIRECT_ROUTE'),
    ],
],

'routes' => [
    // ...existing enabled/prefix/middleware keys unchanged...
    'paths' => [
        'login' => 'login',
        'logout' => 'logout',
        'callback' => 'callback',
        'switch_organization' => 'organizations/{organizationId}/switch', // NEW
    ],
],

'authorization' => [
    // Phase 5's key; default changes from NullMembershipResolver to the real resolver:
    'membership_resolver' => \Authkit\Authkit\Organizations\MembershipProjectionResolver::class,
],
```

**Key decisions**:

- **Jobs, not inline SDK calls, from the Observer.** A slow WorkOS API response (or an outage) must never make `Organization::create()` itself slow or throw inside the app's own request lifecycle — that would turn "add a trait" into "every org-creating request now depends on WorkOS's uptime," which contradicts the whole point of a fire-and-forget projection pattern. `sync_mode = 'sync'` exists as a deliberate, documented opt-out (useful for CLI seed scripts, tests wanting immediate `workos_id` availability, or apps that genuinely want to block) — not the default.
- **`workosOrganizationId()` returns `?string`, not `string`.** Matches Phase 9's exact duck-typed expectation (confirmed wording: "any model that exposes `workosOrganizationId(): ?string`"). Returning `string` and throwing on null would break Vault's org-awareness hook for the — common — window between an org being created locally and its `CreateWorkosOrganization` job actually landing a `workos_id`.
- **`external_id_column` config key is not consumed.** Phase 1 declared it; Phase 2 never reads it for the User model, hardcoding `workos_id` directly instead. This phase does the same for organizations rather than being the first phase to honor a config key no other phase respects — a half-configurable column name would be worse than a consistently-hardcoded one.
- **Why the domains()/memberships() relations use custom foreign/local keys instead of a database FK**: see Component 1's Key Decisions — the org model's table name is unknowable at package-migration time, so the join is Eloquent-level (`hasMany($related, $foreignKey, $localKey)`), not database-level.

**Implementation steps**:

1. `vendor/bin/testbench make:observer WorkosOrganizationObserver --model=Organization` (proxied through the workbench's `Organization` fixture purely to get the generator's method-stub skeleton; relocate the output to `src/Observers/` and strip the `--model` type-hint down to the plain `Model $organization` shape shown above, since the real trait applies to an app-configured, not package-known, class).
2. Hand-author `src/Concerns/HasWorkosOrganization.php` (no generator targets a plain trait).
3. `vendor/bin/testbench make:job CreateWorkosOrganization` and `make:job DeleteWorkosOrganization`, relocate to `src/Jobs/`, implement per Component 4.
4. Add the `organization.sync_mode`/`delete_remote_on_delete`/`retry.*`/`middleware.*` keys, the `routes.paths.switch_organization` key, and the `authorization.membership_resolver` default change to `config/authkit.php`.
5. Register `Authkit\Authkit\Contracts\WorkosClientManager` resolution inside the Job (Component 4) — no new provider binding needed, Phase 1 already bound it as a singleton.

**Feedback loop**:

- **Playground**: `tests/Feature/HasWorkosOrganizationTest.php`, split between an emulate-backed happy path and a MockHandler-backed race/failure path (see Component 4 for the race/failure cases specifically).
- **Experiment**: create a local org row → assert (against emulate) exactly one remote organization exists with the right `name`/`external_id`, and the local row's `workos_id` matches it; delete that row with `delete_remote_on_delete = true` → assert the remote organization is gone; delete with the config set `false` → assert the remote organization still exists; delete a row whose `workos_id` was never set (job never ran / still queued) → assert zero HTTP calls made for the delete.
- **Check command**: `vendor/bin/pest --filter=HasWorkosOrganization`

### 4. `CreateWorkosOrganization` and `DeleteWorkosOrganization` Jobs

**Pattern to follow**: none in-repo for a queued job with this exact idempotency shape; `Illuminate\Contracts\Queue\ShouldQueue` + `SerializesModels` is the standard Laravel job shape used throughout the framework itself.

**Overview**: `CreateWorkosOrganization` is idempotent by construction (lookup-before-create) and doubly idempotent against the specific "already synced by another path" case (the login-time listener, Component 8, can create a local org row with `workos_id` already set — the job must recognize this and no-op). `DeleteWorkosOrganization` deliberately takes a plain `string`, not the Eloquent model, for a reason worth stating precisely: it exists specifically to run *after* the local row is already gone, and `SerializesModels` re-fetches a queued job's model arguments by primary key when the job is dequeued — a model argument here would make the delete job permanently unable to run, since the row it needs to reference has, by definition, already been deleted.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Jobs;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\OrganizationSyncFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use WorkOS\Exception\ConflictException;
use WorkOS\Exception\NotFoundException;
use WorkOS\Exception\UnprocessableEntityException;
use WorkOS\Resource\Organization;
use WorkOS\Service\Organizations;

final class CreateWorkosOrganization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Force queueing after the enclosing DB transaction commits. */
    public bool $afterCommit = true;

    public int $tries = 5;

    /** The org row was deleted before this job ran — silently drop it, not an error. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Model $organization) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return (array) config('authkit.organization.retry.backoff', [10, 30, 60, 300, 900]);
    }

    public function handle(WorkosClientManager $clients): void
    {
        if ($this->organization->getAttribute('workos_id') !== null) {
            return; // already synced (e.g. the login-time listener beat us to it)
        }

        $externalId = (string) $this->organization->getKey();
        $organizations = $clients->client()->organizations();

        try {
            $remote = $organizations->getOrganizationByExternalId($externalId);
        } catch (NotFoundException) {
            $remote = $this->createRemote($organizations, $externalId);
        }

        $this->organization->forceFill(['workos_id' => $remote->id])->saveQuietly();
    }

    private function createRemote(Organizations $organizations, string $externalId): Organization
    {
        try {
            return $organizations->createOrganization(
                name: $this->organization->workosOrganizationName(),
                externalId: $externalId,
            );
        } catch (ConflictException|UnprocessableEntityException) {
            // Lost a create-vs-create race: another process's create beat ours
            // past WorkOS's external_id-uniqueness check. Re-run the lookup —
            // the winner's record now exists.
            return $organizations->getOrganizationByExternalId($externalId);
        }
    }

    public function failed(\Throwable $exception): void
    {
        event(new OrganizationSyncFailed($this->organization, $exception));
    }
}
```

```php
declare(strict_types=1);

namespace Authkit\Authkit\Jobs;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\OrganizationSyncFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use WorkOS\Exception\NotFoundException;

final class DeleteWorkosOrganization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public bool $afterCommit = true;

    public int $tries = 5;

    public function __construct(public readonly string $workosOrganizationId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return (array) config('authkit.organization.retry.backoff', [10, 30, 60, 300, 900]);
    }

    public function handle(WorkosClientManager $clients): void
    {
        try {
            $clients->client()->organizations()->deleteOrganization($this->workosOrganizationId);
        } catch (NotFoundException) {
            // Already gone remotely (manual dashboard delete, or a retried job
            // whose earlier attempt actually succeeded) — deleting twice is a no-op.
        }
    }

    public function failed(\Throwable $exception): void
    {
        event(new OrganizationSyncFailed(null, $exception, $this->workosOrganizationId));
    }
}
```

```php
declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class OrganizationSyncFailed
{
    public function __construct(
        public ?Model $organization,
        public \Throwable $exception,
        public ?string $workosOrganizationId = null,
    ) {}
}
```

**Key decisions**:

- **`public bool $afterCommit = true` is a real, checked Laravel mechanism**, not a naming convention — confirmed by reading `Illuminate\Queue\Queue::createPayloadUsingCommand()`/`enqueueUsing()` (`vendor/laravel/framework/src/Illuminate/Queue/Queue.php:399-403`), which reads exactly this public property off the job object via `isset($job->afterCommit)` before deciding whether to defer the push until the active DB transaction commits. Setting it once on the job class, rather than requiring every dispatch call site to remember `->afterCommit()`, makes the guarantee unconditional regardless of how or where the job gets dispatched.
- **`deleteWhenMissingModels` only on the create job.** Confirmed real property, read by `Illuminate\Queue\CallQueuedHandler::handle()` (`CallQueuedHandler.php:311`) when `SerializesModels`'s restore step can't find the model by primary key. It is deliberately *not* set on the delete job — that job never carries a model to begin with (see Overview), so the property would be meaningless there.
- **`createRemote()`'s conflict catch is a defensive, not confirmed, mitigation.** Whether WorkOS's API actually enforces `external_id` uniqueness with a `409`/`422` on a genuine simultaneous double-create (both processes miss the `getOrganizationByExternalId` lookup, both call `createOrganization`) is not independently confirmed against live docs for this specific endpoint — flagged as an Open Item. The mitigation is written broadly (`ConflictException|UnprocessableEntityException`) so it degrades gracefully either way: if WorkOS *does* enforce it, the loser's job adopts the winner's record on retry; if WorkOS does *not* enforce it, this catch block is simply dead code and the race instead produces two remote organizations with the same `external_id` — a residual risk named, not silently assumed away, in Failure Modes.
- **`saveQuietly()`** avoids re-firing `updated`/`saved` model events for what is a system-internal linkage write, not an app-observable state change — an app listening for "organization updated" events to, say, invalidate a cache, should not see a spurious event fire purely because this job wrote back a `workos_id`.

**Implementation steps**:

1. `vendor/bin/testbench make:job CreateWorkosOrganization`, relocate to `src/Jobs/`, implement per above.
2. `vendor/bin/testbench make:job DeleteWorkosOrganization`, relocate to `src/Jobs/`, implement per above.
3. Hand-author `src/Events/OrganizationSyncFailed.php` (plain DTO, no generator applies).

**Feedback loop**:

- **Playground**: `tests/Feature/HasWorkosOrganizationTest.php` (happy path, emulate) and `tests/Feature/OrganizationSyncFailedTest.php` (failure injection, MockHandler).
- **Experiment**: (1) fresh org, no remote counterpart → lookup 404s, create succeeds, `workos_id` set; (2) job re-run against the *same* row after step 1 → lookup succeeds (finds the already-created remote org), create is never called a second time; (3) MockHandler queues a `409`/`422` on the create call and a subsequent `200` on the lookup → job adopts the "winner's" record without throwing; (4) MockHandler returns `500` on every call, `$tries` exhausted → `OrganizationSyncFailed` fires exactly once, job lands in the failed-jobs table, not retried forever; (5) local row deleted from the database between dispatch and a manually-invoked `Queue::pop()`/`artisan queue:work --once` → job silently drops (assert zero HTTP calls made, assert no exception surfaces, assert `OrganizationSyncFailed` does **not** fire — this is a no-op, not a failure).
- **Check command**: `vendor/bin/pest --filter=HasWorkosOrganization` and `vendor/bin/pest --filter=OrganizationSyncFailed`

### 5. Domains & Memberships Relations — Sanity Coverage

Already implemented as part of Component 3 (`domains()`/`memberships()` on `HasWorkosOrganization`) and Component 6 (`organizations()` on `BelongsToWorkosOrganizations`). Called out with its own test file because it has its own success criterion (the relations must resolve correctly against manually-seeded projection rows with **no** SDK involvement at all — this is pure Eloquent, and a regression here would otherwise only be caught indirectly through Component 8's heavier, SDK-touching suite).

**Feedback loop**:

- **Playground**: `tests/Feature/DomainsAndMembershipsProjectionTest.php`, seeding `workos_organization_domains`/`workos_memberships` rows directly via Eloquent factories/`create()`, no HTTP, no emulate.
- **Experiment**: seed an org with two domains and one membership → `$org->domains()->count()` is 2, `$org->memberships()->first()->role` matches the seeded value; seed a user and a membership row referencing the user's `workos_id` → `$user->organizations()->first()->is($org)` is true; an org with zero domains/memberships → both relations return empty collections, not null, not an exception.
- **Check command**: `vendor/bin/pest --filter=DomainsAndMembershipsProjection`

### 6. `BelongsToWorkosOrganizations` Trait (User model)

**Pattern to follow**: `src/Concerns/HasWorkosUser.php` (Phase 2) for trait-on-the-User-model conventions; kept as a **separate** trait from Phase 2's `HasWorkosUser` rather than added to that file, since this phase does not modify Phase 2's user-linking trait — it only adds a new, independent capability the app applies alongside it.

**Overview**: Gives the User model an `organizations()` relation resolving through the `workos_memberships` projection, joined entirely on WorkOS ID strings (never local numeric IDs) so it works regardless of which concrete org model class the app configures.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait BelongsToWorkosOrganizations
{
    public function organizations(): BelongsToMany
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $organizationModel */
        $organizationModel = (string) config('authkit.organization.model');

        return $this->belongsToMany(
            related: $organizationModel,
            table: 'workos_memberships',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'organization_id',
            parentKey: 'workos_id',
            relatedKey: 'workos_id',
        )->withPivot(['workos_id', 'role', 'status']);
    }
}
```

**Key decisions**:

- **Custom `parentKey`/`relatedKey` (`workos_id` on both sides), not the models' own primary keys.** `belongsToMany()`'s default join keys are each model's primary key; here both sides must join on their `workos_id` column instead, since that's the only column `workos_memberships` actually stores. This is standard, supported `BelongsToMany` behavior (Laravel has accepted explicit `$parentKey`/`$relatedKey` since 5.7), not a workaround.
- **No `.using(WorkosMembership::class)` pivot-model binding.** `WorkosMembership` is deliberately kept a plain `Model` (Component 2), not a `Pivot` subclass, because `MembershipProjectionResolver` (Component 9) also queries it directly as a first-class model. Forcing it to double as a pivot model would coerce two genuinely different use sites into one class shape for no benefit — `withPivot()` alone is sufficient to read `role`/`status` off `$organization->pivot` when iterating `$user->organizations`.
- **Resolves `config('authkit.organization.model')` at call time, not at trait-boot time** — the config value is guaranteed to be set by the time any request actually calls `->organizations()` (it must be, for the org model to exist at all), but is not guaranteed to be set at the moment Eloquent boots every model class in the container, which can happen before config is fully loaded in some artisan-command contexts.

**Implementation steps**:

1. Hand-author `src/Concerns/BelongsToWorkosOrganizations.php` (plain trait, no generator applies).
2. Add `use BelongsToWorkosOrganizations;` to `workbench/app/Models/User.php`, alongside Phase 2's existing `use HasWorkosUser;`.

**Feedback loop**: Covered by Component 5's `DomainsAndMembershipsProjectionTest.php` — no separate loop needed for a single relation method.

### 7. `MembershipProjectionResolver`

**Pattern to follow**: Phase 5's `NullMembershipResolver` (`src/Authorization/NullMembershipResolver.php`) for the interface shape being implemented; this class is its real replacement.

**Overview**: Resolves a WorkOS `organization_membership_id` for a `(user, organizationId)` pair by reading the local `workos_memberships` projection — never a live API call, since the whole point of this seam (per Phase 5) is that FGA's `Authkit::check()` needs a membership ID cheaply, not a second network round trip per check.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Models\WorkosMembership;
use Illuminate\Contracts\Auth\Authenticatable;

final class MembershipProjectionResolver implements ResolvesOrganizationMembershipId
{
    public function resolve(Authenticatable $user, string $organizationId): ?string
    {
        $userWorkosId = $user->getAttribute('workos_id');
        if ($userWorkosId === null) {
            return null;
        }

        return WorkosMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userWorkosId)
            ->where('status', 'active')
            ->value('workos_id');
    }
}
```

**Key decisions**:

- **Reads the local projection, not a live API call.** This is not the caching the contract's FGA-no-cache decision forbids — that decision is about caching the *authorization result* of a Check API call, which this class never touches. Resolving which membership ID to check *against* from already-synced local state is exactly what the declared-projections doctrine is for.
- **`status = 'active'` filter, not "any row for this pair."** A membership row can exist locally with `status = 'inactive'`/`'pending'` (from the projection faithfully mirroring WorkOS's own lifecycle states); only an active membership is a valid FGA-check subject. Returning an inactive membership's ID would let `Authkit::check()` run against a WorkOS `organization_membership_id` that WorkOS itself may reject or treat as non-authorized — filtering here keeps the resolver's contract ("a real, checkable membership, or null") honest.
- **`$user->getAttribute('workos_id')`, not `$user->workos_id`.** Defensive against the theoretical case of a `ResolvesOrganizationMembershipId::resolve()` call site passing an `Authenticatable` that isn't the app's actual User model (e.g. a test double) — `getAttribute()` degrades to `null` rather than a fatal "undefined property" error on a class that doesn't define the property at all.

**Implementation steps**:

0. **Order-dependent check (see Interface Reconciliation item 9)**: does `src/Contracts/ResolvesOrganizationMembershipId.php` already exist (Phase 5 landed first)?
   - **If yes**: skip straight to step 1 — Phase 5's interface file, `bindIf()` registration, and config key already exist; this phase only supplies the concrete class and flips the config default's value (step 2).
   - **If no** (this phase is landing before Phase 5): hand-author `src/Contracts/ResolvesOrganizationMembershipId.php` yourself, verbatim per spec-phase-5.md §4.4's interface shape (`resolve(Authenticatable $user, string $organizationId): ?string`), and add `$this->app->bindIf(\Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId::class, config('authkit.authorization.membership_resolver', \Authkit\Authkit\Organizations\MembershipProjectionResolver::class));` to `AuthkitServiceProvider::register()`. Phase 5, landing second, must diff its own File Changes against this phase's shipped code and reconcile rather than redefine the interface or the binding.
1. Hand-author `src/Organizations/MembershipProjectionResolver.php` (plain service class, no generator applies).
2. Set `config('authkit.authorization.membership_resolver')`'s default value to `\Authkit\Authkit\Organizations\MembershipProjectionResolver::class` in `config/authkit.php`. If step 0 found Phase 5 already landed, this is the one-line change from `NullMembershipResolver::class` already described in Interface Reconciliation item 5, and no other `AuthkitServiceProvider` change is needed — Phase 5's `bindIf()` call already reads this key. If step 0 found Phase 5 hasn't landed yet, this is simply the value set alongside the new config key/`bindIf()` call this phase just added in step 0, with no `NullMembershipResolver` fallback needed since a real resolver already exists.

**Feedback loop**:

- **Playground**: `tests/Feature/MembershipProjectionResolverTest.php`, seeding `workos_memberships` rows directly, no SDK involvement.
- **Experiment**: seeded active membership for the pair → returns its `workos_id`; seeded inactive membership for the same pair → returns `null`; no row at all → returns `null`; user with `workos_id = null` (never linked) → returns `null` without querying the database for a workos_id-less match. A second test binds this resolver into a real `Authkit::check()` call (reusing Phase 5's `AuthorizationTest.php` fixtures) and asserts the previously-thrown `MembershipNotResolvedException` no longer fires once a matching row exists.
- **Check command**: `vendor/bin/pest --filter=MembershipProjectionResolver`

### 8. Login-Time Projection Upsert (`UpsertOrganizationAndMembershipFromLogin`)

**Pattern to follow**: Phase 2's own `HasWorkosUser::findOrCreateForWorkosUser()` for the "synchronous, best-effort, never block the caller" tone; Phase 5's `FgaChecker::currentOrganizationIdFromClaims()` is *not* the pattern here, since this listener runs immediately after token exchange, before the sealed cookie (and therefore the `workos` guard) exists for the current request — it reads claims directly off the freshly-issued access token instead.

**Overview**: Fires once per login. If the freshly-authenticated user's access token carries an `org_id` claim, and either the org or the membership doesn't exist locally yet, fetches just enough from WorkOS to backfill both rows — closing the gap between "WorkOS already knows about this org/membership" and "the local projection has caught up," without waiting for Phase 4's poller.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Listeners;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Auth\JwtPayloadDecoder;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Models\WorkosMembership;
use Illuminate\Support\Facades\Log;
use WorkOS\Exception\WorkOSException;

final class UpsertOrganizationAndMembershipFromLogin
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function handle(Login $event): void
    {
        try {
            $this->sync($event);
        } catch (\Throwable $e) {
            // Never let a projection-backfill failure break a successful login.
            Log::warning('authkit: login-time org/membership projection upsert failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sync(Login $event): void
    {
        $claims = AccessTokenClaims::fromPayload(
            JwtPayloadDecoder::decode($event->response->accessToken),
        );

        if ($claims->organizationId === null) {
            return; // no current org on this login — nothing to project
        }

        $organizationModel = config('authkit.organization.model');
        if ($organizationModel === null || $organizationModel === '') {
            return; // app hasn't wired an org model — org context isn't in use
        }

        $organization = $organizationModel::query()->firstWhere('workos_id', $claims->organizationId);

        if ($organization === null) {
            $remote = $this->clients->client()->organizations()->getOrganization($claims->organizationId);
            $organization = $organizationModel::query()->create([
                'name' => $remote->name,
                'workos_id' => $remote->id,
            ]);
            // `HasWorkosOrganization::bootHasWorkosOrganization()`'s observer still
            // fires `created()` here — its job's first line ("already synced?")
            // sees `workos_id` already set and no-ops. See Component 4.
        }

        $this->syncMembership($claims, $organization->getAttribute('workos_id'));
    }

    private function syncMembership(AccessTokenClaims $claims, string $organizationWorkosId): void
    {
        $existing = WorkosMembership::query()
            ->where('organization_id', $organizationWorkosId)
            ->where('user_id', $claims->sub)
            ->exists();

        if ($existing) {
            return; // already projected — Phase 4's pipeline keeps it current from here
        }

        $memberships = $this->clients->client()->organizationMembership()->listOrganizationMemberships(
            organizationId: $organizationWorkosId,
            userId: $claims->sub,
        );

        $membership = $memberships->data[0] ?? null;
        if ($membership === null) {
            return; // WorkOS hasn't surfaced the membership via this endpoint yet
        }

        WorkosMembership::query()->create([
            'workos_id' => $membership->id,
            'organization_id' => $organizationWorkosId,
            'user_id' => $claims->sub,
            'role' => $membership->role->slug,
            'status' => $membership->status->value,
        ]);
    }
}
```

**Key decisions**:

- **Runs synchronously, in-request, not queued.** Unlike Component 4's Jobs, this listener's whole purpose is to close a gap *before* the login response is returned — queuing it would reintroduce exactly the latency window it exists to eliminate. The cost is one, occasionally two, extra WorkOS API calls per login where org context is present and not yet locally known — bounded, infrequent (once per org/membership pair's lifetime, not per login), and wrapped in a blanket try/catch so it can never turn a successful authentication into a failed one.
- **Decodes the access token directly, not via `WorkosGuard`'s security-invariant path.** Phase 2's `WorkosGuard` only decodes a token that `SessionManager::authenticate()` has *already* signature-verified in the same request — a documented invariant for reading an *unseal-then-reuse* cookie. This listener's token is different in kind: it came directly from `authenticateWithCode()`'s HTTPS response body, moments earlier, from WorkOS itself — there is no cookie to unseal and no signature-verification step to reuse, because the token's origin (a direct, authenticated HTTPS call to WorkOS) is already the trust boundary. Reusing `JwtPayloadDecoder`/`AccessTokenClaims` here is for code reuse (same DTO shape), not because the same security invariant applies.
- **Existence check before the extra API call, twice.** Both the org lookup and the membership lookup check "do we already have this locally" before making any HTTP call — the common case (a user logging in to an org their local projection already knows about) costs zero extra API calls, not one.
- **Membership lookup takes the first result, doesn't paginate.** `listOrganizationMemberships(organizationId:, userId:)` filtered to both IDs should return at most one *active* membership for that pair (WorkOS's own default status filter). If it somehow returns more, taking `[0]` is a deliberate, simple choice — reconciling a genuinely ambiguous multi-membership state for one (org, user) pair is not a case this listener tries to solve; Phase 4's events pipeline is the durable source of truth either way.

**Implementation steps**:

1. Hand-author `src/Listeners/UpsertOrganizationAndMembershipFromLogin.php` (no generator applies — this class has a specific, non-conventional constructor/dependency shape distinct from Laravel's plain event-listener stub).
2. Register it in `AuthkitServiceProvider::boot()`: `Event::listen(\Authkit\Authkit\Events\Login::class, UpsertOrganizationAndMembershipFromLogin::class);`.

**Feedback loop**:

- **Playground**: `tests/Feature/LoginProjectionUpsertTest.php`, driving a real login → callback round trip against `npx @workos/emulate` seeded with an organization and an active membership the local database has never seen.
- **Experiment**: login for a user whose token carries `org_id` for an org+membership that exist only in emulate → after login, both a local org row and a `workos_memberships` row exist with the right `workos_id`/`role`/`status`; login again for the same user → zero additional API calls beyond the login itself (assert via emulate's request log or a MockHandler history spy substituted for this one test), since both existence checks now short-circuit; a token with no `org_id` claim at all → no rows created, `Login` still dispatches successfully; force the membership-listing call to fail (MockHandler 500, swapped in for just this listener's dependency) → login still completes successfully (assert `Auth::guard('workos')->check()` is true), a warning is logged, no membership row exists.
- **Check command**: `vendor/bin/pest --filter=LoginProjectionUpsert`

### 9. `CurrentOrganizationResolver` + `Authkit::currentOrganization()` + `Request::organization()`

**Pattern to follow**: Phase 5's `FgaChecker::currentOrganizationIdFromClaims()` (`src/Authorization/FgaChecker.php`) — the exact same claims-reading pattern, reused rather than reinvented, so "how do we find the current org_id" has one implementation across the whole package, not two that could quietly drift.

**Overview**: A small, request-memoized resolver: `org_id` claim → local org row via `workos_id`. Exposed two ways per the phase direction ("container-bound + request helper") — both delegate to the same resolver instance, so there is exactly one source of truth regardless of which accessor a call site prefers.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CurrentOrganizationResolver
{
    private bool $resolved = false;

    private ?Model $organization = null;

    public function resolve(): ?Model
    {
        if ($this->resolved) {
            return $this->organization;
        }
        $this->resolved = true;

        $organizationId = $this->currentOrganizationIdFromClaims();
        if ($organizationId === null) {
            return $this->organization = null;
        }

        $organizationModel = config('authkit.organization.model');
        if ($organizationModel === null || $organizationModel === '') {
            return $this->organization = null;
        }

        return $this->organization = $organizationModel::query()
            ->where('workos_id', $organizationId)
            ->first();
    }

    private function currentOrganizationIdFromClaims(): ?string
    {
        $guard = Auth::guard('workos');

        if (! method_exists($guard, 'accessTokenClaims')) {
            return null;
        }

        return $guard->accessTokenClaims()['org_id'] ?? null;
    }
}
```

Service provider wiring and the two accessors:

```php
// AuthkitServiceProvider::register()
$this->app->singleton(CurrentOrganizationResolver::class);

// AuthkitServiceProvider::boot()
\Illuminate\Http\Request::macro('organization', function (): ?\Illuminate\Database\Eloquent\Model {
    return app(CurrentOrganizationResolver::class)->resolve();
});
```

```php
// src/Authkit.php addition
public function currentOrganization(): ?\Illuminate\Database\Eloquent\Model
{
    return app(CurrentOrganizationResolver::class)->resolve();
}
```

**Key decisions**:

- **Memoized (`$resolved`/`$organization`), same pattern as `WorkosGuard`.** Bound as a container singleton, so its lifetime matches the request under a typical PHP-FPM/Apache deployment — repeated calls to `$request->organization()`/`Authkit::currentOrganization()` within one request cost exactly one database query, not one per call. See Failure Modes for the named caveat this creates under long-running workers (Octane-style).
- **`$request->organization()` requires no middleware.** Deliberately not tied to `authkit.org` — a dashboard-style route that wants to *display* the current org (or its absence) without *requiring* one must be able to call this on any authenticated route. `RequireOrganizationContext` (Component 10) is a thin consumer of this same resolver, not the thing that populates it.
- **Reads from the guard, not `$user->claims()`.** Phase 2's `HasWorkosUser` trait exposes claims on the *user* model (`$user->claims(): ?AccessTokenClaims`); this resolver instead asks the *guard* directly (`Auth::guard('workos')`), matching Phase 5's `FgaChecker` in spirit. Either would technically work, but reading from the guard means this resolver has zero dependency on which user-model trait shape Phase 2 shipped.
- **Duck-types the guard (`method_exists($guard, 'accessTokenClaims')`) rather than type-checking against Phase 5's `HasAccessTokenClaims` interface.** See Interface Reconciliation item 9: this phase's declared prereq is Phase 2 only, and Phase 5 (which owns `HasAccessTokenClaims`) is `blocking: false` with no edge to this phase — an `instanceof` check against an interface that may not exist yet would risk a fatal "class not found" error, not a graceful `null`, if this phase runs first. `method_exists()` needs no `use` of Phase 5's interface at all, degrades to `null` (not a fatal error) if the guard exposes no such method, and keeps working unchanged once Phase 5 lands and the guard genuinely implements the interface.

**Implementation steps**:

1. Hand-author `src/Organizations/CurrentOrganizationResolver.php`.
2. Bind it as a singleton in `AuthkitServiceProvider::register()`.
3. Register the `Request::organization()` macro in `AuthkitServiceProvider::boot()`.
4. Add `currentOrganization()` to `src/Authkit.php` and its `@method` docblock entry to `src/Facades/Authkit.php`.

**Feedback loop**:

- **Playground**: `tests/Feature/CurrentOrganizationTest.php`.
- **Experiment**: authenticated request with an `org_id` claim matching a local row → both `Authkit::currentOrganization()` and `$request->organization()` return the same model instance's data; `org_id` claim with no matching local row → both return `null`; no `org_id` claim at all → both return `null`; call `$request->organization()` twice within one test and assert (via a query-count spy, e.g. `DB::listen()`) exactly one query ran.
- **Check command**: `vendor/bin/pest --filter=CurrentOrganization`

### 10. `authkit.org` Tenant Middleware (`RequireOrganizationContext`)

**Pattern to follow**: `src/Http/Middleware/RefreshWorkosSession.php` (Phase 2) for middleware-class shape and config-driven behavior branching.

**Overview**: Guards a route on "there must be a current org," with a configurable outcome when there isn't one — matching the phase direction's explicit "403/redirect configurable."

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireOrganizationContext
{
    public function __construct(private readonly CurrentOrganizationResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->resolver->resolve() !== null) {
            return $next($request);
        }

        return $this->handleMissing($request);
    }

    private function handleMissing(Request $request): Response
    {
        $mode = config('authkit.organization.middleware.on_missing', 'abort');

        if ($mode === 'redirect') {
            $route = config('authkit.organization.middleware.redirect_route');

            abort_if(
                $route === null || $route === '',
                500,
                'authkit.organization.middleware.redirect_route must be set when authkit.organization.middleware.on_missing is "redirect".',
            );

            return redirect()->route($route);
        }

        abort(403, 'This action requires an organization context.');
    }
}
```

**Key decisions**:

- **`abort()` for the misconfigured-redirect case, not a silent fallback to `abort(403)`.** Silently falling back would hide a real configuration mistake behind a plausible-looking 403 — the same "fail fast, name the config key" doctrine this project applies everywhere else (Phase 2's config-validation Failure Mode, Phase 8's `MissingModelConfigurationException`).
- **Depends on `CurrentOrganizationResolver` directly (constructor injection), not `Authkit::currentOrganization()`.** A middleware class resolved by the container gets normal constructor injection for free; going through the facade here would add an indirection with no benefit.

**Implementation steps**:

1. `vendor/bin/testbench make:middleware RequireOrganizationContext`, relocate to `src/Http/Middleware/`, implement per above.
2. Register the alias in `AuthkitServiceProvider::boot()`: `$this->app['router']->aliasMiddleware('authkit.org', RequireOrganizationContext::class);`.

**Feedback loop**:

- **Playground**: `tests/Feature/RequireOrganizationContextTest.php`, registering a throwaway route behind `authkit.org` inside the test.
- **Experiment**: authenticated request with a resolvable current org → 200 (route runs); authenticated request with no current org, `on_missing = 'abort'` (default) → 403; same, `on_missing = 'redirect'` + `redirect_route` set to a real named route → 302 to that route; same, `on_missing = 'redirect'` + `redirect_route` unset → 500 naming the config key.
- **Check command**: `vendor/bin/pest --filter=RequireOrganizationContext`

### 11. Org-Switch Route (`authkit.switch-org`)

**Pattern to follow**: `src/Http/Requests/AuthKitLoginRequest.php` and `AuthKitController` (Phase 2) — this component both calls into one and extends the other, per the additive signature change in File Changes.

**Overview**: `SessionManager::refresh()` already accepts an `organizationId` hint at the SDK level (confirmed signature). This controller calls it directly against the current sealed cookie; when refresh can't satisfy the switch (no active membership in the target org, an already-rotated refresh token, or any other rejection), it falls back to a full re-authorize redirect carrying the same org hint, reusing Phase 2's `AuthKitLoginRequest::redirect()` rather than duplicating PKCE handling.

```php
declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Http\Requests\AuthKitLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Cookie;

final class SwitchOrganizationController extends Controller
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function __invoke(Request $request, string $organizationId, AuthKitLoginRequest $loginRequest): RedirectResponse
    {
        $cookieName = (string) config('authkit.session.cookie');
        $sealed = $request->cookie($cookieName);

        if (! is_string($sealed) || $sealed === '') {
            return redirect()->route('authkit.login');
        }

        $result = $this->clients->client()->sessionManager()->refresh(
            sessionData: $sealed,
            cookiePassword: (string) config('authkit.cookie_password'),
            clientId: (string) config('authkit.client_id'),
            organizationId: $organizationId,
        );

        if (! $result['authenticated']) {
            return $loginRequest->redirect(
                intendedUrl: $request->input('return_to'),
                organizationId: $organizationId,
            );
        }

        return redirect()->to((string) $request->input('return_to', '/'))->withCookie(new Cookie(
            name: $cookieName,
            value: $result['sealed_session'],
            expire: 0,
            secure: (bool) config('session.secure', true),
            httpOnly: true,
            sameSite: (string) config('authkit.session.same_site', 'lax'),
        ));
    }
}
```

Route registration (`routes/authkit-laravel.php`, inside Phase 2's existing `web`-grouped, `routes.enabled`-gated block):

```php
Route::post(
    '/'.trim((string) config('authkit.routes.prefix'), '/').'/'.config('authkit.routes.paths.switch_organization'),
    SwitchOrganizationController::class,
)->name('authkit.switch-org');
```

**Key decisions**:

- **Calls `SessionManager::refresh()` directly, bypassing Phase 2's `SessionRefresher` single-flight lock.** `SessionRefresher` exists to coordinate *automatic, near-expiry* refreshes that multiple concurrent requests might all trigger at once (Phase 2 Failure Modes: "Concurrent refresh race"). An explicit, user-initiated org switch is a single request the user deliberately triggered — there is nothing to coordinate against. Routing it through the shared single-flight lock would add complexity (a lock keyed on session ID, shared with the background-refresh path) for a scenario that doesn't have the race the lock exists to prevent. See Failure Modes for the one narrow window this simplification leaves open, and why it self-heals via the same fallback this component already has.
- **The fallback reuses `AuthKitLoginRequest::redirect()` rather than duplicating PKCE.** Building a second "generate PKCE, stash state in session, redirect to WorkOS" code path here would create two independently-maintained implementations of the exact same handshake — the additive `?string $organizationId = null` parameter (File Changes) is the entire cost of reuse.
- **`return_to` travels as a request input, not session state.** Unlike the login flow's `intendedUrl` (stashed across the OAuth redirect round trip because the browser leaves and comes back), a successful org-switch refresh never leaves this request — there's no redirect-away-and-back to preserve state across, so a plain request parameter is sufficient and simpler.

**Implementation steps**:

1. `vendor/bin/testbench make:controller SwitchOrganizationController --invokable`, relocate to `src/Http/Controllers/`, implement per above.
2. Modify `src/Http/Requests/AuthKitLoginRequest.php`'s `redirect()` signature: add the trailing `?string $organizationId = null` parameter, thread it into the existing `getAuthorizationUrl(...)` call's `organizationId:` argument.
3. Add the route to `routes/authkit-laravel.php`.

**Feedback loop**:

- **Playground**: `tests/Feature/OrganizationSwitchTest.php`, split emulate (happy path) / MockHandler (refresh-rejected fallback).
- **Experiment**: authenticated session, POST to `authkit.switch-org` for an org the user has an active membership in (emulate) → response redirects to `return_to`, a new sealed cookie is set, and re-authenticating against it resolves the new `org_id`; POST for an org with no membership (MockHandler forces `authenticated: false`) → response is a redirect *away* (to WorkOS's authorize endpoint), and its query string contains `organization_id=<the target>`; no session cookie present at all → redirect to `authkit.login`, no SDK call attempted.
- **Check command**: `vendor/bin/pest --filter=OrganizationSwitch`

### 12. Workbench Fixture (`Organization` model, migration, factory)

**Pattern to follow**: `workbench/app/Models/User.php` + `workbench/database/factories/UserFactory.php` (Phase 2's baseline) for the minimal-fixture style.

**Overview**: The concrete, real org model every one of this phase's emulate-backed tests exercises `HasWorkosOrganization` against. Matches Phase 13's already-stated assumption of `Workbench\App\Models\Organization` / table `organizations` exactly — no reconciliation needed here (see Interface Reconciliation).

```php
namespace Workbench\App\Models;

use Authkit\Authkit\Concerns\HasWorkosOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\OrganizationFactory;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasWorkosOrganization;

    protected $guarded = ['id'];
}
```

```php
// workbench/database/migrations/2026_05_01_000002_create_organizations_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('workos_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
```

```php
// workbench/database/factories/OrganizationFactory.php
namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Organization;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            // workos_id is deliberately absent — populated by
            // HasWorkosOrganization's create-observer, never set here.
        ];
    }
}
```

**Key decisions**: `workos_id` is nullable and unique on the workbench migration — nullable because a freshly-created row's remote sync may still be queued/in-flight; unique because it is this phase's own linking key, mirroring Phase 2's `users.workos_id` column exactly.

**Implementation steps**:

1. `vendor/bin/testbench make:model Organization -m` (mirrors Phase 5's identical `make:model Project -m` precedent for workbench fixtures), then edit the generated migration/model per above.
2. `vendor/bin/testbench make:factory OrganizationFactory`, then edit per above.
3. Set `config('authkit.organization.model', \Workbench\App\Models\Organization::class)` in `workbench/app/Providers/WorkbenchServiceProvider.php::register()` — the minimum wiring needed for `composer serve` and this phase's own workbench-touching tests to have a real org model configured, following Phase 2's precedent of doing the minimum viable workbench wiring in its own phase rather than deferring everything to Phase 13.

**Feedback loop**: Skipped — fixture files with no branching logic of their own. Exercised by every emulate-backed test in this phase.

## Data Model

### Schema Changes

```sql
-- workos_organization_domains
CREATE TABLE workos_organization_domains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workos_id VARCHAR(255) NOT NULL,
    organization_id VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    state VARCHAR(255) NULL,
    verification_prefix VARCHAR(255) NULL,
    verification_token VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY workos_organization_domains_workos_id_unique (workos_id),
    KEY workos_organization_domains_organization_id_index (organization_id)
);

-- workos_memberships
CREATE TABLE workos_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workos_id VARCHAR(255) NOT NULL,
    organization_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    role VARCHAR(255) NULL,
    status VARCHAR(255) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY workos_memberships_workos_id_unique (workos_id),
    KEY workos_memberships_organization_id_user_id_index (organization_id, user_id)
);

-- workbench-only, app-owned (illustrative shape apps replicate on their own org table)
ALTER TABLE organizations ADD COLUMN workos_id VARCHAR(255) NULL UNIQUE;
```

### State Shape

No new claims/session state — this phase reads `AccessTokenClaims::$organizationId` (already defined by Phase 2) and never introduces a new decoded-claims shape. `CurrentOrganizationResolver`'s two private fields (`$resolved`, `?Model $organization`) are the only new in-memory state, scoped to one container-singleton lifetime.

## API Design

### New Endpoints

| Method | Path (default prefix `authkit`) | Name | Description |
| --- | --- | --- | --- |
| `POST` | `/authkit/organizations/{organizationId}/switch` | `authkit.switch-org` | Refreshes the sealed session scoped to `{organizationId}`; falls back to a re-authorize redirect on refresh rejection. |

### Request/Response Examples

```
POST /authkit/organizations/org_01H.../switch
Cookie: authkit_session=<sealed>
Body: return_to=/dashboard

  → 302 Found (success path)
    Set-Cookie: authkit_session=<newly sealed, org-scoped>; HttpOnly; Secure; SameSite=Lax
    Location: /dashboard

  → 302 Found (fallback path — refresh rejected)
    Location: https://api.workos.com/user_management/authorize?...&organization_id=org_01H...&...
```

## Testing Requirements

### Feature Tests

| Test File | Coverage | Test path |
| --- | --- | --- |
| `tests/Feature/HasWorkosOrganizationTest.php` | Create → remote org created; idempotent re-run; create-vs-create race; delete → configurable remote deletion | emulate (happy path) + MockHandler (race) |
| `tests/Feature/OrganizationSyncFailedTest.php` | WorkOS-down retries/backoff/`failed()`; model-deleted-before-job-runs no-op | MockHandler |
| `tests/Feature/DomainsAndMembershipsProjectionTest.php` | `domains()`/`memberships()`/`organizations()` relations against seeded rows | none (pure Eloquent) |
| `tests/Feature/LoginProjectionUpsertTest.php` | Backfill org+membership from a first-time claim; no-op on no claim; resilient to API failure | emulate (happy path) + MockHandler (failure) |
| `tests/Feature/MembershipProjectionResolverTest.php` | `resolve()` correctness; integration with `Authkit::check()` | none (pure Eloquent) + reuses Phase 5 fixtures |
| `tests/Feature/CurrentOrganizationTest.php` | Facade/macro parity; memoization; null cases | none (claims-only) |
| `tests/Feature/RequireOrganizationContextTest.php` | Pass-through, abort, redirect, misconfigured-redirect | none (claims-only) |
| `tests/Feature/OrganizationSwitchTest.php` | Successful switch; refresh-rejected fallback | emulate + MockHandler |

**Key test cases**: see each component's Feedback Loop "Experiment" entry above — those are this phase's canonical test-case list, not duplicated here.

### Manual Testing

- [ ] `composer serve` against a locally running `npx @workos/emulate`; `php artisan tinker` → `Workbench\App\Models\Organization::create(['name' => 'Acme'])` → confirm (after the queue worker/`--sync` runs) the row gets a `workos_id` and a matching organization now exists in emulate.
- [ ] Log in as a user whose emulate-seeded membership belongs to an organization the local database has never seen; confirm `$user->organizations()->first()` resolves immediately after login, with no manual `authkit:work` run needed.
- [ ] Hit a route behind `authkit.org` with no current org selected; confirm the configured `on_missing` behavior (403 by default) fires exactly as configured.
- [ ] POST to `authkit.switch-org` for a second organization the logged-in user belongs to; confirm the new sealed cookie's `org_id` claim (via `authkit:inspect-token`, Phase 1) reflects the switch.

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| `CreateWorkosOrganization` | **WorkOS down at model-create** | Network partition, WorkOS incident, or a misconfigured `base_url` at job-execution time | Job throws, retries per `backoff()`, and after `tries` (default 5) exhausts, lands in `failed_jobs` | Retries/backoff are explicit job config, not left to a global default; `OrganizationSyncFailed` fires on final failure for operator visibility. Residual: the local org row persists forever with `workos_id = null` — a "poison" org — until a human intervenes or re-dispatches the job; a full reconciliation sweep is explicitly the contract's Stretch-tier "State API reconciliation command," not this phase's job to build. |
| `CreateWorkosOrganization` | **Org create raced twice** | Two processes both dispatch a create job for organizations with the same external_id before either's `getOrganizationByExternalId` lookup can see the other's write | If WorkOS enforces `external_id` uniqueness server-side, the loser's `createOrganization()` call throws `ConflictException`/`UnprocessableEntityException`, caught and resolved by re-running the lookup (winner's record adopted, no duplicate). **Not independently confirmed** whether WorkOS enforces this for organizations specifically — see Open Items — so if it does *not* enforce it, this exact race instead produces two remote organizations sharing one `external_id`, and the loser's local row ends up linked to whichever remote record its own `getOrganizationByExternalId` retry happens to return. | Defensive catch is written broadly and degrades gracefully either way; the residual "no server-side enforcement" case is named, not silently assumed away. |
| `CreateWorkosOrganization` | **Model deleted before job runs** | App creates then immediately deletes a local org row (e.g. a validation-failure rollback pattern, or a test) before the queue worker dequeues the create job | `SerializesModels` can't re-fetch a deleted row by primary key | `deleteWhenMissingModels = true` — job silently drops, zero HTTP calls, no exception, no `OrganizationSyncFailed` event (this is a no-op, not a failure) |
| `HasWorkosOrganization` + `SoftDeletes` | **Soft-delete divergence** | An app's org model uses both `SoftDeletes` and `HasWorkosOrganization` | Eloquent's `deleted` event fires identically for soft and hard deletes, so (with `delete_remote_on_delete = true`, the default) the remote WorkOS organization gets deleted even though the local row is only soft-deleted and potentially restorable | Same class of documented-not-fixed gotcha as Phase 5's identical `HasWorkosResource` + `SoftDeletes` failure mode (consistent handling, not solved twice differently); apps combining both should override the trait's boot hook or set `delete_remote_on_delete = false`. |
| `WorkosOrganizationDomain`/`WorkosMembership` | **Domain verification state strings** | The `organization_domain.verification_failed` event payload has no top-level `state` field at all (confirmed: it carries `reason` + a nested `organizationDomain.state`), structurally different from every other domain event's flat `state` field | A projection-refresh listener (Phase 4) naively reading `$event->payload['state']` for every domain event type would silently write `null` for verification-failure events specifically | This phase's `state` column is a plain opaque string precisely so no schema change is needed regardless of which payload shape arrives; the payload-shape difference itself is named here and in Component 1 so Phase 4's listener implementation reads the correct nested path for this one event type. |
| Login-time upsert | **WorkOS unreachable during backfill** | The extra `getOrganization`/`listOrganizationMemberships` call fails mid-login | Without the try/catch, a WorkOS blip during login-time backfill would turn a successful authentication into a failed one | Blanket `catch (\Throwable)` around the whole sync, logged as a warning; `Login` still dispatches, the user is still authenticated, the projection simply stays stale until the next login or Phase 4's poller catches up |
| Login-time upsert | **Membership not yet visible via the listing endpoint** | `org_id` claim is present but `listOrganizationMemberships(organizationId:, userId:)` returns zero results (a narrow WorkOS-side propagation-lag window) | Org row gets created (from the claim + a direct `getOrganization` call), but no membership row | `Authkit::check()`'s `MembershipNotResolvedException` (Phase 5) surfaces loudly on the very next FGA check attempt, rather than silently returning a wrong `false` — a caller sees an actionable exception, not an inexplicable deny |
| `CurrentOrganizationResolver` | **Stale binding under long-running workers** | A container singleton persists across requests under Octane-style long-running-process deployments unless explicitly reset | A user's current-org resolution could return a *previous* request's cached value | Named risk, not solved this phase — same class of caveat this project already accepts elsewhere (Phase 2's cache-lock-driver note); an app deploying under Octane must register this resolver for request-lifecycle reset via Octane's own hook, outside this package's scope. |
| `RequireOrganizationContext` | **Misconfigured redirect target** | `on_missing = 'redirect'` but `redirect_route` is unset or names a route that doesn't exist | A naive implementation could infinite-loop (redirecting to a route that itself requires an org) or silently 403 | `abort_if(..., 500, ...)` fails loudly, naming the exact config key, the moment the misconfiguration is hit — never a silent fallback or a redirect loop |
| `SwitchOrganizationController` | **Refresh races Phase 2's background single-flight refresh** | An org-switch request and an automatic near-expiry refresh (Phase 2's `RefreshWorkosSession` middleware) both fire `SessionManager::refresh()` for the same session within the same narrow window | Refresh tokens rotate on every use (confirmed, emulate is stricter than confirmed prod but the property holds either way) — whichever call loses the race holds an already-invalidated refresh token | The losing call's `refresh()` returns `authenticated: false`; the org-switch path's own fallback (re-authorize redirect) already covers this — no additional locking added, since the fallback is the mitigation, not a gap needing one |
| `SwitchOrganizationController` | **No session cookie present** | User hits the switch route directly without an active session (bookmarked URL, expired session, stripped cookie) | Nothing to refresh | Redirects to `authkit.login` immediately, no SDK call attempted |

## Validation Commands

```bash
# Static analysis
composer analyse

# Formatting check
composer lint:check

# Type coverage (must stay 100)
composer test:types

# This phase's suites, scoped
vendor/bin/pest --filter=HasWorkosOrganization
vendor/bin/pest --filter=OrganizationSyncFailed
vendor/bin/pest --filter=DomainsAndMembershipsProjection
vendor/bin/pest --filter=LoginProjectionUpsert
vendor/bin/pest --filter=MembershipProjectionResolver
vendor/bin/pest --filter=CurrentOrganization
vendor/bin/pest --filter=RequireOrganizationContext
vendor/bin/pest --filter=OrganizationSwitch

# No env() outside config files (must return no matches -> exit 1)
grep -rn "env(" src/ --include="*.php"

# Full validation chain — must be green before commit
composer test
```

## Rollout Considerations

- **Feature flag**: none — the package ships no runtime feature-flag gate for itself; this phase lands green on `composer test` or it doesn't ship.
- **Monitoring**: `OrganizationSyncFailed` is a Laravel event specifically so a consuming app (or a later phase's audit-log wiring) can observe org-sync failures; this phase adds no bespoke metrics emitter beyond dispatching it.
- **Alerting**: recommend the app's queue-monitoring (Horizon, `queue:failed`, etc.) alert on entries in the failed-jobs table for `CreateWorkosOrganization`/`DeleteWorkosOrganization` specifically — a failure there means a local org exists with no remote counterpart (or vice versa), which is exactly the "poison" state named in Failure Modes.
- **Rollback plan**: `git revert` of this phase's commit(s), or the contract's recorded anchor (`git reset --hard 4d04d0b`) for a full express-run unwind. Both new migrations are additive and cleanly reversible (`down()` drops each table); the one Phase 2 file this phase modifies (`AuthKitLoginRequest::redirect()`) changes via an additive optional parameter, so reverting this phase's commit alone does not risk breaking Phase 2's own already-shipped call sites.

## Open Items

- [ ] **Orchestration: this phase has a real compile-time dependency on Phase 5 that the contract's prereq graph does not encode** (`HasAccessTokenClaims`, `ResolvesOrganizationMembershipId` — see Interface Reconciliation item 9). This phase is made order-independent by duck-typing (Component 9) and conditionally self-defining the interface/binding (Component 7) rather than by editing the contract — but consider whether `contract-data.json`'s `execution.phases` should also gain an explicit prereq edge from this phase to "Authorization (RBAC + FGA)" (or a documented rationale for why it deliberately doesn't), so a future orchestration run doesn't have to rediscover this from the spec text alone.
- [ ] **Confirm whether WorkOS enforces `external_id` uniqueness for organizations server-side**, and if so, which exception class the SDK surfaces for the conflict (`ConflictException` vs `UnprocessableEntityException` vs something else) — `CreateWorkosOrganization::createRemote()`'s catch clause is written defensively across both, but the actual behavior is unconfirmed against live docs. Verify before relying on this as a guaranteed dedup mechanism in package documentation.
- [ ] **Confirm the real `iss`/`aud` values and default claim presence from Phase 1's empirical token audit** (`docs/token-audit-findings.md`) before treating this phase's assumption that `org_id` rides the access token by default, with no dashboard configuration, as settled fact — carried forward from Phase 1/2/5's identical open item, not re-litigated here.
- [ ] **Whether `workos-emulate.config.yaml`'s seed schema supports seeding an organization membership as a top-level primitive** is the same unconfirmed question Phase 5 already flagged for its own emulate-backed suite. This phase's `LoginProjectionUpsertTest.php` and the emulate half of `HasWorkosOrganizationTest.php` depend on it in the same way. If emulate has no way to seed a pre-existing membership, downgrade those specific cases to MockHandler and update the test-path table accordingly — the mechanism under test does not change, only the fixture source.
- [ ] **Reconcile Phase 4's real shipped listener code against this spec's table/model names and column shapes** (Interface Reconciliation items 1-3) once Phase 4 is actually implemented — this spec names the required corrections precisely, but the two phases may be executed in either order depending on the orchestration plan, and whichever lands second should diff against the other's real code, not just this document.
- [ ] **Reconcile Phase 13's `DashboardController` against `$request->organization()`** (Interface Reconciliation item 4) at whichever implementation time Phase 13 actually runs.

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
