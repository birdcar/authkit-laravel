# Implementation Spec: AuthKit Laravel v1 - Phase 13 — Integration, Quickstart & Release Readiness

**Contract**: `./contract-data.json`
**Context brief**: `context-brief.md` (canonical facts brief supplied alongside this spec — treat as authoritative for every SDK signature, emulate behavior, and repo convention cited below)
**PRD**: omitted (no PRDs were generated for this project)
**Estimated Effort**: XL

**Prereqs (per contract)**: Events Pipeline & Webhooks (Phase 4), Authorization RBAC+FGA (Phase 5), Audit Logs & Admin Portal (Phase 6), Feature Flags (Phase 7), API Keys Guard (Phase 8), Vault (Phase 9), Connect & MCP Auth (Phase 10), Pipes (Phase 11), Depth Extensions (Phase 12). This is the **last** phase before release; nothing else is blocked on it.

**Unresolved transitive prereq — Organizations & Org Context (Phase 3)**: every prerequisite phase listed above now has a landed spec file in `docs/ideation/authkit-laravel-v1/` — `spec-phase-3.md` does not, even though it is the single most heavily-cited "assumed" dependency in this document (`HasWorkosOrganization`, the org model/table name, `workos_organization_domains`, `workos_memberships`, current-org resolution — see the Standalone-Implementability table below). It underpins the Acceptance suite (Component 6), the ProjectionBoundary whitelist (Component 7), `DashboardController` (Component 2), and the seed factories (Component 1). **Components 1, 2, 6, and 7 are gated on `spec-phase-3.md` landing first** — do not begin implementing them against this spec's Phase 3 assumptions alone; either wait for `spec-phase-3.md` to be generated, or reconcile against Phase 3's real shipped code (whichever exists first) before writing a single line in those four components.

---

## Technical Approach

Phase 13 adds **no new WorkOS-wrapping code**. Every SDK call this package needs was already wrapped by Phases 1–12. This phase's job is to *prove* that wrapping is real, complete, and honest — and to ship the artifacts a release needs to point at as evidence.

Four kinds of work, in dependency order:

1. **Close workbench gaps.** Audit the workbench example app (`workbench/`) against the 16-row scope table. Some feature-area phases already added their own workbench routes/controllers as part of their delta (Pipes/Phase 11 shipped a full `PipesController`; Depth Extensions/Phase 12 appended inline routes for Invitations and Groups). Others did not (Audit Logs & Admin Portal/Phase 6 shipped only the model/migration/factory side, no route). Phase 13 fills every remaining gap so that **every one of the 16 scope-table areas has at least one workbench route, and Feature Flags additionally has a console command**, all calling package APIs only. It also adds the one unifying piece nothing else owns: a `DashboardController` that surfaces the logged-in user's full claim set (org, role, permissions, feature flags) in one place — the thing a human doing the quickstart trial actually looks at to confirm "orgs + RBAC live."
2. **Prove the doctrines mechanically.** Three new test files turn three of the contract's four unfalsifiable-until-now promises into `vendor/bin/pest --filter=X` commands: the Acceptance suite (the whole login→link→org→can() promise), the ProjectionBoundary test (the WorkOS-canonical-state promise), and the IdiomCoverage test (the Laravel-native-maximalism promise). A fourth test enforces the workbench zero-SDK-reference grep as a repeatable Pest check instead of a one-off manual grep.
3. **Wire the release pipeline.** `.github/workflows/tests.yml` gains an emulate-boot step so the full CI matrix (PHP 8.3–8.5 × Laravel 12/13 × both stability lanes × ubuntu/windows) exercises the same emulate-backed suites `composer test:unit` runs locally. `docs/quickstart.md` gets written and mechanically bounded to ≤5 top-level numbered steps. `feature_list.json`'s placeholder features are replaced with the real 13-phase roadmap.
4. **Ship the release story.** `README.md` is rewritten to the package's actual promises (not the skeleton boilerplate it still carries). The bundled Boost skill (`resources/boost/skills/authkit-laravel-development/SKILL.md`) is regenerated from the real implementation per the repo's own `package-generate-skill` instructions. A release checklist is added carrying the one criterion nothing in CI can check: a recorded human timing trial.

None of this touches `src/` in a way that changes runtime behavior — the only `src/` scenario is *if* the ProjectionBoundary/IdiomCoverage audit (component 7/8) finds a real gap left by an earlier phase (e.g., a mechanism named in the contract that never got wired into `AuthkitServiceProvider`). That is a **found bug**, not new scope; fix it minimally, cite the missing scope-table row, and record it in Deviations.

## Standalone-Implementability: Assumed Interfaces from Prior Phases

This spec is written to be actioned by an agent who has not read every Phase 1–12 spec (some — Phases 1–5, 7–10 — did not exist yet when this document was written; only Phases 6, 11, and 12 had landed specs). Everywhere this phase calls into another phase's surface, the assumed name/path/signature is stated below. **At implementation time, open the real code first and reconcile** — the design intent (what gets tested, what gets demonstrated, what gets whitelisted) does not change if a name differs; only the call site does.

| Symbol | Assumed path | Assumed shape | Source |
|---|---|---|---|
| `Authkit\Authkit\WorkosClientManager` | `src/WorkosClientManager.php` | `client(): \WorkOS\WorkOS`; config-driven; base-URL override for emulate; injectable Guzzle handler | Phase 1 (confirmed by contract notes; file not yet written) |
| `config/authkit.php` | — | Renamed from `config/authkit-laravel.php`; merged in `AuthkitServiceProvider::register()` | Phase 1 |
| `php artisan authkit:install` | `src/Console/Commands/` | Idempotent installer: publishes config/migrations, appends `WORKOS_*` env keys, registers routes + guard | Phase 1 |
| Emulate test-harness boot helper | assumed `Tests\Support\EmulateServer` or a Pest `beforeAll`/global hook | Starts `workos/emulate`, points `WorkosClientManager` base URL at it, for **local** `composer test:unit` runs | Phase 1 — **this phase does not depend on the exact mechanism**, only that emulate is reachable at `config('authkit.base_url')` by the time a Pest test runs; see Component 9 |
| Root `workos-emulate.config.yaml` | repo root | Seed file: users, organizations(+domains), webhookEndpoints, connections, invitations, apiKeys, roles/permissions, jwtTemplate, connectApplications | Phase 1 (skeleton); **this phase expands it** — see Component 1 |
| `workos` guard driver | assumed `Authkit\Authkit\Auth\WorkosGuard`, registered via `Auth::extend('workos', ...)` | Wraps `SessionManager`; adds `iss`/`aud` checks | Phase 2 |
| `authkit-key` guard driver | assumed registered via `Auth::extend('authkit-key', ...)` | Validates via `ApiKeys::createValidation` / user-key equivalent | Phase 8 |
| Middleware aliases `authkit.session`, `authkit.org`, `authkit.mcp` | registered in `AuthkitServiceProvider::boot()` | Session refresh / tenant context / MCP bearer | Phases 2, 3, 10 — names are **canonical** per the project's shared-conventions table, not guessed |
| Route names `authkit.login`, `authkit.logout`, `authkit.callback`, `authkit.switch-org` | `routes/authkit-laravel.php` (renamed by Phase 1/2) | Thin wrappers over public form requests | Phase 2, 3 — names canonical |
| `Authkit\Authkit\Concerns\HasWorkosUser` | `src/Concerns/HasWorkosUser.php` | `workos_id` column; first-login sets `external_id` in WorkOS via `updateUser(externalId: ...)` | Phase 2 — confirmed path used verbatim by the already-written Phase 11 spec |
| `Authkit\Authkit\Concerns\HasWorkosOrganization` | `src/Concerns/HasWorkosOrganization.php` | Observer auto-creates org via `createOrganization(externalId: ...)`; `workos_id` ↔ `external_id` | Phase 3 — canonical trait name |
| Org model in workbench | assumed `Workbench\App\Models\Organization` | App-owned model carrying `HasWorkosOrganization`; table name assumed `organizations` | Phase 3 — **reconcile table/model name**; see Component 7 |
| `workos_organization_domains` table | — | `workos_id`, `domain`, `state`, `verification_prefix`, `verification_token` (per Phase 6's own Open Items) | Phase 3 — contract-fixed table name |
| `workos_memberships` table | — | Local org-membership projection, `workos_id`/`external_id` present | Phase 3/4 — contract-fixed table name; column list assumed |
| Current-org resolution | assumed `Authkit::currentOrganization()` or an `$request->organization()` binding from `authkit.org` middleware | Claims-resolved current org | Phase 3 |
| `php artisan authkit:work` | `src/Console/Commands/` | Cursor-persisted `Events::listEvents()` poller | Phase 4 |
| `workos_event_cursor` table | — | Sync bookkeeping row(s) | Phase 4 — contract-fixed table name |
| `Authkit\Authkit\Events\Workos\*` + `GenericWorkosEvent` | `src/Events/Workos/` | Typed events for projection-feeding + audit/domain-verification types; generic fallback for everything else | Phase 4 |
| `php artisan make:workos-listener` | `src/Console/Commands/` | Generator scaffolding a listener for a named WorkOS event type | Phase 4 |
| Webhook route + signature middleware | `routes/authkit-laravel.php` | `WebhookVerification::verifyHeader/verifyEvent` | Phase 4 |
| RBAC facade surface | assumed `Authkit::roles()` / `Authkit::permissions()` (or equivalent) + `Gate::before` claims wiring | Zero-HTTP `$user->can()` from `role`/`roles`/`permissions` session claims | Phase 5 |
| `Authkit\Authkit\Authorization\FgaChecker` (`Authkit::check()`) | `src/Authorization/FgaChecker.php` | Top-level `Authkit::check(string $permissionSlug, string $resourceExternalId, string $resourceTypeSlug, ?string $organizationMembershipId = null, ?Authenticatable $user = null, ?string $organizationId = null, ?RequestOptions $options = null): bool` | Phase 5 (**confirmed, not assumed** — full Phase 5 spec now exists, §4.4) |
| `Authkit\Authkit\Authorization\ResourceTarget` | `src/Authorization/ResourceTarget.php` | `byId()` / `byExternalId()` named constructors — internal to `FgaChecker`'s SDK-boundary conversion only; never a public parameter on `Authkit::check()` or any workbench call site | Phase 5 (**confirmed, not assumed** — §4.4/§4.6) |
| `Authkit\Authkit\Concerns\HasWorkosResource` | `src/Concerns/HasWorkosResource.php` | `workosResourceType(): string` (abstract), `workosResourceOrganizationId(): string` (overridable, defaults to `$this->organization->workos_id`); external ID is implicitly `$model->getKey()` — never an overridable method | Phase 5 (**confirmed, not assumed** — full Phase 5 spec now exists, §4.5) |
| `src/AuditLogManager.php`, `Authkit\Authkit\Facades\AuditLog`, `HasAuditLogs`, `#[AuditActions]` | `src/AuditLogManager.php`, `src/Facades/AuditLog.php`, `src/Concerns/HasAuditLogs.php`, `src/Attributes/AuditActions.php` | Confirmed — full Phase 6 spec exists | Phase 6 (**confirmed, not assumed**) |
| `Authkit::portalLink()` + `PortalIntent` enum | `src/Enums/PortalIntent.php` | 7-intent enum | Phase 6 (**confirmed**) |
| Pennant driver `workos` | registered via `Feature::extend('workos', ...)` in `AuthkitServiceProvider::boot()` | Claim-first, WorkOS-API fallback outside HTTP | Phase 7 |
| `HasApiKeys` trait | assumed `src/Concerns/HasApiKeys.php` | issue/revoke on user + org models | Phase 8 — canonical trait name per shared conventions |
| `Authkit\Authkit\Casts\Vaulted` | `src/Casts/Vaulted.php` | `CastsAttributes` implementation, envelope encryption via Vault data keys | Phase 9 |
| Vault filesystem driver | assumed registered disk driver name `vault`, via `Storage::extend('vault', ...)` | Wraps any configured disk with BYOK data-key encryption | Phase 9 |
| `Authkit\Authkit\Facades\Vault` | `src/Facades/Vault.php` | KV facade | Phase 9 |
| Connect application registry (`Authkit::connect()`) | assumed `src/Connect/ConnectManager.php` | OAuth/M2M application registry | Phase 10 |
| `/.well-known/oauth-protected-resource` route | `routes/authkit-laravel.php` | MCP resource-indicator metadata | Phase 10 |
| `src/Pipes/PipesManager.php` (`Authkit::pipes()`), `PipesController`, `HasWorkosUser::connectedAccounts()/pipe()` | `src/Pipes/`, `workbench/app/Http/Controllers/PipesController.php` | Confirmed — full Phase 11 spec exists | Phase 11 (**confirmed, not assumed**) |
| `Authkit::invitations()/jwtTemplate()/corsOrigins()/groups()`, `InvalidateFgaCache` listener | `src/Invitations/`, `src/JwtTemplates/`, `src/CorsOrigins/`, `src/Groups/`, `src/Authorization/Listeners/InvalidateFgaCache.php` | Confirmed — full Phase 12 spec exists | Phase 12 (**confirmed, not assumed**) |

Any symbol above marked "assumed" that turns out not to exist at all by the time Phase 13 executes (as opposed to existing under a different name) is a **blocking finding**: stop and report it — Phase 13 cannot prove a promise whose implementation never shipped.

## Decisions Considered and Rejected

_Carried from the contract in full — this is the release-readiness phase, and every doctrine below is either directly enforced by a new test/artifact in this phase or is load-bearing context for one that is._

- **RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API** — rejected: sync WorkOS roles/permissions into local spatie-style tables. Claims already ride the access token so checks are free; local tables duplicate canonical WorkOS state and drift. *Directly exercised by the Acceptance suite's `$user->can()` assertion and by ProjectionBoundary's refusal to allow a local roles/permissions table.*
- **Breadth-complete v1: all 16 scope areas ship in the first version at usable-core depth; phases are build order, not releases** — rejected: release-tiered rollout. Ecosystem-substitution logic and Nick's explicit "literally all of the features I listed are our MVP" ruling. *This is why the workbench must demonstrate all 16 areas, not a subset — the release-readiness bar is breadth-complete, not MVP-complete.*
- **Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state; app's Laravel session stays free for app state** — rejected: exchange code then hydrate Laravel's standard session guard. WorkOS must remain the session source of truth for authn and authz. *The Acceptance suite authenticates through this guard, not a fake session.*
- **Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage** — rejected: SDK fakes only. Wire fidelity where possible. *Directly names this phase's CI change: emulate must actually boot in the GitHub Actions matrix, not just locally.*
- **Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id` ↔ `external_id` linking, refreshed by the events pipeline** — rejected: no local state / read-through API calls per request. Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events. *This is the exact promise ProjectionBoundary makes falsifiable.*
- **Feature Flags ship as a first-party laravel/pennant driver (claim-first, API fallback)** — rejected: standalone `AuthkitFeature` facade. Pennant is Laravel's paved path. *IdiomCoverage checks the Pennant driver is registered; the workbench console command proves the console/queue fallback path live.*
- **Directory Sync: prefer WorkOS-managed provisioning; events-pipeline listener recipes for custom mapping; no dedicated module** — rejected: full dsync provisioning module. Most apps need zero dsync code. *Confirms the workbench has no dsync-specific demo route to build — not a gap.*
- **Full org context in v1: claims-resolved current org, org-switch route via AuthKit re-auth, tenant middleware** — rejected: read-only org context. Multi-org ergonomics are table stakes for B2B apps. *The Acceptance suite's "org auto-created" step and the workbench dashboard's current-org display both depend on this.*
- **Stay on Pest 4 with PHP ^8.3 floor** — rejected: Pest 5. PHP 8.3 support runs through Dec 2027; Paratest friction on 8.5 handled by non-parallel runs. *Every new test file in this phase is written against Pest 4 conventions already in `tests/Pest.php`; the CI matrix this phase touches still spans PHP 8.3–8.5.*
- **Credentials read from config only; `env()` is never read outside config files** — rejected: runtime `env()` reads. `config:cache` empties env at runtime. *The new CI emulate-boot step sets `WORKOS_BASE_URL`/`WORKOS_API_KEY` as real environment variables consumed only by `config/authkit.php` — never a raw `env()` call in any file this phase adds under `src/` or `workbench/`.*
- **Events API sidecar is the primary sync transport; webhooks are optional low-latency triggers sharing the same Laravel event objects** — rejected: webhooks-primary sync. *The workbench events demo exercises the sidecar path; the `LogWorkosEvents` listener this phase adds must handle both a typed event and the `GenericWorkosEvent` fallback identically to prove "one listener story."*
- **Auth flows exposed both as registered routes and as form-request helpers, with routes as thin wrappers** — rejected: routes-only surface. *IdiomCoverage asserts the form-request classes exist independently of the routes.*
- **Wire the Events worker and emulate into `php artisan dev`** — rejected: `composer dev` script only. *Referenced in the quickstart doc's "what running locally looks like" framing, though the mechanism itself is Phase 4's, not this phase's, to build.*
- **Widgets are excluded from v1 entirely — no token-minting facade** — rejected: widget token minting in MVP or Full tier. Widgets are UI surface; the starter kit owns UI. *Directly bounds the workbench: no widget demo route exists or should exist — its absence is correct, not a gap, and the README/quickstart must not imply otherwise.*
- **Phase 1 ends with an empirical AuthKit token audit confirming canonical `iss`/`aud` and default claim presence** — rejected: assume the SDK's TODO values. *The Acceptance suite's `$user->can()` assertion is only meaningful if `role`/`permissions` claims are confirmed present by default — this phase's Acceptance suite is the first place that audit's assumption gets exercised end-to-end against a real (emulated) login.*
- **API Keys Guard and Connect & MCP phases depend on Organizations & Org Context** — rejected: original auth-core-only prereq graph. *Reflected in this phase's `feature_list.json` dependency graph (Component 11) and in why the API Keys / Connect workbench demos need an organization fixture from the seed file.*
- **FGA ships without caching — direct Check API per check; opt-in caching with events-driven invalidation is Full tier** — rejected: default per-check cache in MVP. *The workbench FGA demo route calls `Authkit::check()` directly; IdiomCoverage does not require a cache to exist, only that the check mechanism and (Full-tier) the opt-in cache config keys exist.*
- **Typed sidecar events are bounded to types feeding the declared projections + audit/domain-verification; everything else dispatches a generic `WorkosEvent`** — rejected: a typed class per event type. *`LogWorkosEvents` (Component 4) must demonstrably handle both branches — this is the one thing the workbench listener recipe exists to prove.*
- **Quickstart criterion split into a mechanical ≤5-step doc check plus a recorded human timing trial** — rejected: single judgment-only quickstart criterion. *This is Components 10 and 12 of this phase, verbatim.*
- **v1 targets the Full tier: MVP's 16 areas plus the 5 depth extensions** — rejected: MVP-only v1 with depth extensions deferred. *Sets the workbench's true completeness bar: Invitations and Groups need workbench visibility too, not just the MVP 16 — both already have a workbench demo route from Phase 12. JWT templates, CORS origins, and FGA resource-graph conveniences (including opt-in FGA caching) are deliberately Pest-suite-only with no workbench route, per spec-phase-12.md §9 (Deviations #3)'s explicit, already-reasoned decision — this phase does not add a workbench route for any of those three and does not reverse that decision.*
- **Express run executes directly on `main` (no isolation branch)** — rejected: isolation branch. Process-level; not relevant to this phase's design, but relevant to how it is committed (see Rollout).

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest --filter=Acceptance` (or `--filter=ProjectionBoundary` / `--filter=IdiomCoverage` / `--filter=WorkbenchZeroSdkReference` for the specific check being iterated) — each runs in seconds against a Testbench-booted app; no full suite needed while iterating on one artifact.

**Playground**: `composer serve` (Testbench workbench server) for exercising the new workbench routes/console commands by hand against a locally-booted `workos/emulate`; `vendor/bin/pest --filter={Suite}` for everything else, including the two new architectural tests.

**Why this approach**: every component in this phase is either (a) a workbench HTTP/console entry point best poked at with a running server, or (b) a Pest assertion against the already-booted Testbench app — there is no build step, no compiled asset pipeline, and no external service other than emulate to wait on.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `workbench/app/Http/Controllers/DashboardController.php` | Post-login hub: current user, current org, role/permissions/feature-flags claims, links to every demo route. The single page a human trial looks at. |
| `workbench/app/Http/Controllers/AuditLogDemoController.php` | Closes the Phase 6 gap — exercises `HasAuditLogs`/`AuditLog::log()` via package APIs only |
| `workbench/app/Http/Controllers/AdminPortalDemoController.php` | Closes the Phase 6 gap — exercises `Authkit::portalLink()` across intents |
| `workbench/app/Http/Controllers/AuthorizationDemoController.php` | RBAC (`$user->can()`) + FGA (`Authkit::check()`) demo, in one controller since both answer "can this user do this" |
| `workbench/app/Http/Controllers/FeatureFlagDemoController.php` | HTTP-context Pennant check (`Feature::active()`), claim-first path |
| `workbench/app/Http/Controllers/ApiKeyDemoController.php` | Issue/validate/revoke demo on the workbench user + org models |
| `workbench/app/Http/Controllers/VaultDemoController.php` | Vaulted-cast round trip + KV facade + filesystem-driver round trip |
| `workbench/app/Http/Controllers/ConnectMcpDemoController.php` | Lists Connect applications; links to the package's own `/.well-known/oauth-protected-resource` route |
| `workbench/app/Console/Commands/CheckFeatureFlagsForUser.php` | `demo:feature-flags {user}` — proves the WorkOS-API fallback path (no HTTP session, no claim to read from) |
| `workbench/app/Console/Commands/TriggerWorkosEvent.php` | `demo:trigger-event` — makes a package-API write that produces a real WorkOS event, for manually observing the sidecar + `LogWorkosEvents` round trip |
| `workbench/app/Listeners/LogWorkosEvents.php` | Generated via `php artisan make:workos-listener`; logs both a typed projection event and the `GenericWorkosEvent` fallback — the generator's own worked example |
| `workbench/database/factories/OrganizationFactory.php` | Factory for the workbench org model, needed by the seeder and the Acceptance suite |
| `tests/Feature/AcceptanceTest.php` | Login → link → org-create → `$user->can()` end-to-end against emulate |
| `tests/Feature/ProjectionBoundaryTest.php` | Explicit-whitelist local-state boundary check |
| `tests/Feature/IdiomCoverageTest.php` | Existence + registration check for every promised Laravel mechanism |
| `tests/Feature/WorkbenchZeroSdkReferenceTest.php` | Pest-wrapped version of the G2 FQCN grep, runnable in CI without a shell script |
| `docs/quickstart.md` | ≤5 numbered top-level steps, composer require → login with orgs+RBAC live |
| `docs/release-checklist.md` | Release gate list + the human quickstart-trial log template |

### Modified Files

| File Path | Changes |
|---|---|
| `workbench/routes/web.php` | Register routes for every controller/command above; add a `demo.dashboard` index route. Audit and, if missing, add route names for whichever of `authkit.login`/`callback`/`logout`/`switch-org` the workbench needs to *link to* (it does not re-register these — they're package routes) |
| `workbench/routes/console.php` | Register `demo:feature-flags` and `demo:trigger-event` if Console Kernel-style auto-discovery isn't already picking up `app/Console/Commands/*` (Laravel auto-discovers by default; confirm and only add explicit registration if needed) |
| `workbench/app/Models/Post.php` | Add a `secret_note` attribute to the `casts()` array using `Authkit\Authkit\Casts\Vaulted::class` — reuses the existing Phase 6 demo model for the Vault cast demo instead of introducing a new one |
| `workbench/database/migrations/{new-timestamp}_add_secret_note_to_posts_table.php` | Adds the nullable `secret_note` column backing the cast above |
| `workbench/database/factories/PostFactory.php` | Add a faker value for `secret_note` |
| `workbench/database/seeders/DatabaseSeeder.php` | Orchestrate factories across users, organizations, and posts so the workbench and the Acceptance suite share one seed path |
| `workos-emulate.config.yaml` (repo root) | Expand seed content: roles/permissions covering the Acceptance suite's `$user->can()` case, a JWT template exercising default claim presence, at least one organization + domain, one invitation, one connect application, feature-flag targets — enough breadth that every new workbench route has real backing data |
| `.github/workflows/tests.yml` | Add OS-conditional "Boot workos/emulate" steps before the two "Test Suite" steps; export `WORKOS_BASE_URL`/`WORKOS_API_KEY` for those steps only |
| `feature_list.json` | Replace `feat-002`..`feat-005` placeholders with `feat-002`..`feat-014`, one per contract phase, dependency graph mirroring `execution.phases[].prereqs` |
| `README.md` | Rewrite past the skeleton boilerplate to the package's actual promises: what it replaces (`laravel/workos`), the quickstart, the 16-area feature list, links to `docs/quickstart.md` |
| `resources/boost/skills/authkit-laravel-development/SKILL.md` | Regenerate per the repo's own `package-generate-skill` instructions, from the now-real implementation |
| `progress.md` / `session-handoff.md` | Standard end-of-session update per `CLAUDE.md`'s harness convention — not a Phase 13-specific deliverable, but do not skip it |
| `tests/ArchTest.php` | No new `arch()` rules added here (see Component 7/8 — these two checks need a booted app, not static analysis, so they live in `tests/Feature/` instead); leave this file's existing three rules untouched |

### Deleted Files

None. This phase closes gaps and proves promises; nothing from Phases 1–12 is removed.

## Implementation Details

### Component 1 — Workbench seed data: factories, seeder, and the emulate config

**Gated on `spec-phase-3.md`**: this component's org factory and seeder both depend on Phase 3's real org model/table name. Do not start until `spec-phase-3.md` exists or Phase 3's real code has shipped — see the top-of-document note on the unresolved transitive prereq.

**Pattern to follow**: `workbench/database/factories/UserFactory.php` (existing), `workbench/database/seeders/DatabaseSeeder.php` (existing, currently empty)

**Overview**: One coherent seed story feeds three consumers — local `composer serve`, the CI emulate boot, and the Acceptance suite — so a fixture used in one place is guaranteed to exist in the others.

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
        ];
        // workos_id / external_id are populated by HasWorkosOrganization's
        // creation observer against emulate — do not set them here.
    }
}
```

**Key decisions**:

- The Eloquent-side factories create **local** rows only; `workos_id`/`external_id` linkage happens through the traits' own observers hitting emulate, never through factory state — otherwise the seed data would desync from what WorkOS actually has, which is exactly what ProjectionBoundary and the projection-boundary doctrine forbid.
- `workos-emulate.config.yaml` is expanded, not replaced — Phase 1's skeleton entries (whatever they are) stay; this phase only adds what the new workbench routes and the Acceptance suite need.

**Implementation steps**:

1. Confirm the real workbench org model name/table from Phase 3's shipped code (assumed `Workbench\App\Models\Organization` / `organizations` above); adjust the factory's namespace/model reference if different.
2. `php artisan make:factory OrganizationFactory` inside the workbench context (`composer serve`'s Testbench app), then relocate/rename per the existing `workbench/database/factories/UserFactory.php` convention if the generator places it elsewhere.
3. Update `workbench/database/seeders/DatabaseSeeder.php` to create: 3 users (one designated "primary" for manual/CI login), 1 organization with the primary user as a member, 3 posts owned by the primary user (one with `secret_note` set, for the Vault demo).
4. Expand `workos-emulate.config.yaml`: add a role (`admin`) and permission (`posts.manage`) assigned to the primary user's membership so the Acceptance suite's `$user->can('posts.manage')` has something real to assert against; add one organization domain, one invitation, one connect application (per the emulate seed schema in the context brief).
5. Cross-check the emulate seed's user/org identifiers against whatever `.env.testing` or Phase 1's test harness already uses as canonical fixture IDs — do not invent a second set of IDs.

**Feedback loop**:

- **Playground**: `composer serve`, then `php artisan db:seed` against the workbench SQLite database with emulate running locally (`npx @workos/emulate`).
- **Experiment**: seed with emulate cold (no prior state) and again with emulate already holding data from a previous run — the observer-driven `workos_id` linkage must not throw on either.
- **Check command**: `php artisan db:seed --class="Workbench\\Database\\Seeders\\DatabaseSeeder"` exits 0; `sqlite3 workbench/database/database.sqlite ".tables"` shows the expected rows populated.

### Component 2 — Workbench demo: Dashboard (unifying hub)

**Gated on `spec-phase-3.md`**: the current-organization attribute reads below are placeholders pending Phase 3's real accessor names. Do not start until `spec-phase-3.md` exists or Phase 3's real code has shipped — see the top-of-document note on the unresolved transitive prereq.

**Pattern to follow**: none in-repo yet — this is the first workbench controller; follow the confirmed `workbench/app/Http/Controllers/PipesController.php` (Phase 11) for structure once it exists.

**Overview**: A single authenticated JSON endpoint that answers "did the login actually work, with orgs and RBAC live" — the literal thing a human quickstart trial checks.

```php
namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user('workos');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'workos_id' => $user->workos_id,
            ],
            // Exact accessor names depend on Phases 2/3/5 — see Assumed Interfaces.
            'organization' => $request->attributes->get('current_organization'),
            'claims' => [
                'role' => $request->attributes->get('workos_role'),
                'permissions' => $request->attributes->get('workos_permissions'),
                'feature_flags' => $request->attributes->get('workos_feature_flags'),
            ],
            'demo_routes' => array_keys(
                array_filter(
                    app('router')->getRoutes()->getRoutesByName(),
                    fn ($name) => str_starts_with($name, 'demo.'),
                    ARRAY_FILTER_USE_KEY,
                )
            ),
        ]);
    }
}
```

**Key decisions**:

- Returns JSON, not a Blade view. The package is headless-plumbing by design (out-of-scope: "UI, views, or starter-kit scaffolding") — extending a UI-free posture to the workbench example keeps this phase's footprint small and every assertion `assertJson()`-testable, rather than needing view files that add nothing to the proof.
- Reads claims via whatever request-attribute or accessor Phases 2/3/5 actually expose — the exact accessor names in the snippet above are placeholders to reconcile; the *shape* of the response (user/org/claims/route-index) is what matters and is fixed by this spec.

**Implementation steps**:

1. Once Phases 2/3/5 are inspected, replace the placeholder attribute reads with their real accessors (e.g., a `CurrentOrganization` facade, a `$user->workosClaims()` method, or whatever actually shipped).
2. Register `Route::get('/dashboard', DashboardController::class)->middleware(['workos'])->name('demo.dashboard');` in `workbench/routes/web.php`.
3. Verify no `use WorkOS\` or `\WorkOS\` reference is needed to build this controller — if reading claims requires one, that is an IdiomCoverage-relevant gap in the phase that was supposed to expose them, not something this controller should work around by reaching into the SDK directly.

**Feedback loop**:

- **Playground**: `composer serve` + a real emulate-backed login flow through `/login` → `/callback`.
- **Experiment**: hit `/dashboard` unauthenticated (expect a redirect/401 via the `workos` guard, not a crash) and authenticated (expect the full JSON shape).
- **Check command**: `vendor/bin/pest --filter=Acceptance` (the Acceptance suite in Component 6 exercises this same path end-to-end)

### Component 3 — Workbench demo: Audit Logs & Admin Portal (closing the Phase 6 gap)

**Pattern to follow**: `src/AuditLogManager.php` / `src/Enums/PortalIntent.php` (Phase 6, confirmed)

**Overview**: Phase 6 shipped the model/migration/facade side (`HasAuditLogs` on `Post`, `AuditLog::log()`, `Authkit::portalLink()`) but no workbench route. This closes that.

```php
namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Models\Post;

class AuditLogDemoController extends Controller
{
    public function log(Request $request): JsonResponse
    {
        $post = Post::factory()->for($request->user('workos'))->create();
        $post->update(['title' => 'Updated via audit demo']); // HasAuditLogs dispatches post.updated

        AuditLog::log('demo.manual_action', targets: [], metadata: ['source' => 'workbench']);

        return response()->json(['post_id' => $post->id, 'status' => 'logged']);
    }
}

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Enums\PortalIntent;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPortalDemoController extends Controller
{
    public function link(Request $request, string $intent): JsonResponse
    {
        $portalIntent = PortalIntent::from($intent); // throws ValueError on an invalid intent — allowed to surface

        return response()->json([
            'intent' => $portalIntent->value,
            'url' => Authkit::portalLink($request->attributes->get('current_organization'), $portalIntent),
        ]);
    }
}
```

**Key decisions**:

- Two controllers, not one — Audit Logs and Admin Portal are two distinct scope-table rows in the same contract phase; keeping them separate keeps each route's failure mode legible in test output.
- The Admin Portal route takes the intent as a route parameter so all 7 `PortalIntent` cases are reachable without 7 separate routes.

**Implementation steps**:

1. `Route::post('/demo/audit-log', [AuditLogDemoController::class, 'log'])->middleware('workos')->name('demo.audit.log');`
2. `Route::get('/demo/portal/{intent}', [AdminPortalDemoController::class, 'link'])->middleware('workos')->name('demo.portal.link');`
3. Confirm the real signature of `Authkit::portalLink()` (Phase 6's spec gives `$organization` + `PortalIntent` as inputs, string URL as output) before wiring the second controller.

**Feedback loop**:

- **Playground**: `composer serve`, authenticated session, `POST /demo/audit-log` then check emulate's own audit-log listing (if covered) or the queued job's dispatched state via `Queue::fake()` in a scoped test.
- **Experiment**: call `/demo/portal/{intent}` for all 7 `PortalIntent::cases()` values plus one invalid string — the 7 succeed, the invalid one throws (not silently returns null).
- **Check command**: `vendor/bin/pest --filter=WorkbenchZeroSdkReference` (confirms these two new files stay import-clean) and manual `curl` against `composer serve`.

### Component 4 — Workbench demo: Events sidecar & the `make:workos-listener` generator

**Pattern to follow**: Phase 4's (assumed) generator; Laravel's own `make:listener`

**Overview**: This is the one workbench artifact that proves the generator idiom itself, not just a wrapped SDK call — and the one place the "typed event vs. `GenericWorkosEvent` fallback" doctrine gets a worked example.

```php
namespace Workbench\App\Listeners;

use Authkit\Authkit\Events\Workos\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated; // exact class TBD — see Assumed Interfaces
use Illuminate\Support\Facades\Log;

class LogWorkosEvents
{
    public function handleMembershipCreated(OrganizationMembershipCreated $event): void
    {
        Log::info('workos membership created', ['payload' => $event->payload ?? $event]);
    }

    public function handleGeneric(GenericWorkosEvent $event): void
    {
        Log::info('workos generic event', ['type' => $event->type, 'payload' => $event->payload]);
    }
}
```

```php
namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;

class TriggerWorkosEvent extends Command
{
    protected $signature = 'demo:trigger-event';
    protected $description = 'Makes a package-API write that produces a real WorkOS event, for observing the sidecar round trip.';

    public function handle(): int
    {
        // e.g. $organization->addDomain(...) or Authkit::organizations()->update(...) —
        // whichever package-level call Phase 3/4 actually exposes for a mutating,
        // event-producing write. Must not touch \WorkOS\... directly.
        $this->info('Triggered a WorkOS write; run `php artisan authkit:work` (or wait for the running worker) to observe LogWorkosEvents fire.');

        return self::SUCCESS;
    }
}
```

**Key decisions**:

- The listener has two handler methods, not one `__invoke`, because it needs two different event-type parameters — the same reasoning Phase 12's `InvalidateFgaCache` listener already used for the identical structural problem.
- `demo:trigger-event` is deliberately thin: it makes ONE real write and prints guidance, rather than orchestrating a full poll-and-assert cycle — that full cycle already belongs to Phase 4's own `EventsWorkerResume` suite; this command exists for a human to watch happen, not for CI to assert against.

**Implementation steps**:

1. `php artisan make:workos-listener LogWorkosEvents` from the workbench context (proves the generator works as documented); if the generator places the file under `app/Listeners` with a different starting shape, edit into the two-method form above rather than accepting an `--invokable` single-method stub.
2. Register both handler methods in `AuthkitServiceProvider`'s or the workbench provider's event map — confirm whether Phase 4's generator auto-registers listeners or whether `WorkbenchServiceProvider::boot()` needs an explicit `Event::listen()` pair, matching whatever convention Phase 4 established.
3. Add `TriggerWorkosEvent` under `workbench/app/Console/Commands/`; Laravel's default command auto-discovery (`app/Console/Commands/*`) should pick it up without touching `workbench/routes/console.php`, but verify against the actual `bootstrap/app.php` console configuration.

**Feedback loop**:

- **Playground**: `composer serve` in one terminal, `php artisan authkit:work` in another (per Phase 4), emulate running.
- **Experiment**: run `php artisan demo:trigger-event`, confirm `LogWorkosEvents::handleMembershipCreated` (or whichever typed handler applies) fires; separately force an event type outside the bounded typed set (e.g., via emulate's `/_emulate/hooks`) and confirm `handleGeneric` fires instead — proving both branches of the bounded-typing doctrine.
- **Check command**: `tail -f storage/logs/laravel.log` while running the two experiments above; no scripted Pest assertion is required for this one (it is a manual/observational demo, not a CI gate) — CI coverage of the underlying mechanism belongs to Phase 4's own suite.

### Component 5 — Workbench demo: Authorization (RBAC + FGA), Feature Flags, API Keys, Vault, Connect/MCP

**Pattern to follow**: `workbench/app/Http/Controllers/PipesController.php` (Phase 11, confirmed) for controller shape; `src/Authorization/FgaChecker.php` / `Authkit\Authkit\Casts\Vaulted` for the calls being wrapped.

**Overview**: Five remaining scope-table areas, each getting exactly one small controller (or, for Feature Flags, a controller *and* a console command, since the contract's success criterion for flags is explicitly about both an HTTP context and a no-session context).

```php
namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationDemoController extends Controller
{
    public function rbac(Request $request): JsonResponse
    {
        return response()->json([
            'can_manage_posts' => $request->user('workos')->can('posts.manage'),
        ]);
    }

    public function fga(Request $request, string $resourceExternalId): JsonResponse
    {
        // No fga()/ResourceTarget wrapper at the call site — Authkit::check()
        // is a top-level method and resolves organizationMembershipId itself
        // from the authenticated guard user + current-org claim when omitted.
        $authorized = Authkit::check(
            permissionSlug: 'posts.manage',
            resourceExternalId: $resourceExternalId,
            resourceTypeSlug: 'document',
        );

        return response()->json(['authorized' => $authorized]);
    }
}
```

```php
namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class FeatureFlagDemoController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        return response()->json([
            'active' => Feature::for($request->user('workos'))->active('demo-flag'),
        ]);
    }
}
```

```php
namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyDemoController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $key = $request->user('workos')->issueApiKey(); // exact method name assumed — see Assumed Interfaces

        return response()->json(['raw_value_shown_once' => $key->value]);
    }

    public function revoke(Request $request, string $apiKeyId): JsonResponse
    {
        $request->user('workos')->revokeApiKey($apiKeyId);

        return response()->json(['revoked' => $apiKeyId]);
    }
}
```

```php
namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Post;

class VaultDemoController extends Controller
{
    public function roundTrip(Request $request): JsonResponse
    {
        $post = Post::factory()->for($request->user('workos'))->create(['secret_note' => 'demo-secret']);
        $castRoundTrip = $post->refresh()->secret_note === 'demo-secret';

        Storage::disk('vault-demo')->put('demo.txt', 'demo-file-contents');
        $fileRoundTrip = Storage::disk('vault-demo')->get('demo.txt') === 'demo-file-contents';

        Vault::put('demo-kv-key', 'demo-kv-value');
        $kvRoundTrip = Vault::get('demo-kv-key') === 'demo-kv-value';

        return response()->json(compact('castRoundTrip', 'fileRoundTrip', 'kvRoundTrip'));
    }
}
```

```php
namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;

class ConnectMcpDemoController extends Controller
{
    public function applications(): JsonResponse
    {
        return response()->json([
            'applications' => Authkit::connect()->listApplications(),
            'protected_resource_metadata_url' => route('authkit.mcp.protected-resource'), // name assumed — reconcile against Phase 10
        ]);
    }
}
```

**Key decisions**:

- FGA and RBAC share one controller because they answer the same question ("can") at two different granularities — grouping them makes the RBAC-vs-FGA distinction legible in one file instead of scattered across two.
- The API Keys demo issues a real key and immediately shows its raw value (matching WorkOS's own "shown once at creation" behavior) rather than pretending to persist it — this is a demo of the guard's issuance path, not a key-management UI.
- Vault's three sub-mechanisms (cast, filesystem driver, KV) get one combined round-trip endpoint rather than three, because the contract's own success criterion for Vault is phrased as a single combined assertion ("the Vaulted cast round-trips... the vault filesystem driver round-trips... and the KV facade CRUDs").

**Implementation steps**:

1. Add `config()->set('filesystems.disks.vault-demo', ['driver' => 'vault', 'wraps' => 'local', ...])` (exact config shape assumed — reconcile against Phase 9) inside `WorkbenchServiceProvider::boot()`.
2. Register routes: `demo.rbac.check` (GET `/demo/rbac`), `demo.fga.check` (GET `/demo/fga/{resourceExternalId}`), `demo.flags.check` (GET `/demo/flags`), `demo.api-keys.issue`/`demo.api-keys.revoke` (POST `/demo/api-keys`, DELETE `/demo/api-keys/{apiKeyId}`), `demo.vault.round-trip` (GET `/demo/vault`), `demo.connect.applications` (GET `/demo/connect`) — all under the `workos` guard middleware.
3. Before wiring `FeatureFlagDemoController`, confirm `laravel/pennant` is declared as a dependency (composer.json currently has no such requirement — Phase 7 should have added it; if it hasn't by the time this phase executes, that is a Phase 7 gap to flag, not something to silently add here outside scope).
4. Register `demo-flag` in the emulate seed (Component 1) so the check has a real target.

**Feedback loop**:

- **Playground**: `composer serve`, authenticated session, `curl` against each route in turn.
- **Experiment**: for RBAC/FGA — same user against a permission slug they do and do not hold; for Feature Flags — a user with the flag targeted on vs. off in the seed; for API Keys — issue then immediately validate then revoke then re-validate (expect failure); for Vault — round-trip with an empty string and a >4KB payload (the cast/KV path should not silently truncate; the filesystem path is unbounded).
- **Check command**: `vendor/bin/pest --filter=WorkbenchZeroSdkReference` after adding each controller, plus manual `curl` against `composer serve` for the functional behavior (these controllers are demonstration surface, not independently unit-tested — their correctness is proven transitively by each area's own Phase 5/7/8/9/10 test suite already exercising the same underlying manager/facade methods).

### Component 6 — Acceptance Suite

**Gated on `spec-phase-3.md`**: the org-creation step, the org-relation name, and the membership/permission seed this suite asserts on are all Phase 3 surface. Do not start until `spec-phase-3.md` exists or Phase 3's real code has shipped — see the top-of-document note on the unresolved transitive prereq.

**Pattern to follow**: none yet in-repo (first true end-to-end suite); structurally similar to how Phase 6's `AuditLogsTest.php` chains emulate-backed calls.

**Overview**: The literal contract promise, as one test: *login → local user linked (`workos_id` stored, `external_id` set in WorkOS) → org auto-created via trait → `$user->can()` honors JWT permission claims.*

```php
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

test('Acceptance: login links the user, auto-creates the org, and RBAC reads from claims', function () {
    // Arrange: emulate seed (Component 1) provides a user with a known email
    // and a permission ('posts.manage') assigned via role/membership.

    $response = $this->get(route('authkit.login'));
    $response->assertRedirect(); // to the WorkOS-hosted authorization URL

    // Follow the emulate-backed authorization + callback round trip.
    // Exact mechanics (state param, PKCE, code exchange) depend on Phase 2's
    // form-request implementation — this is the one place this phase's test
    // must be adjusted once that shape is confirmed, per the Phase 2 spec.
    $callback = $this->get(route('authkit.callback', ['code' => 'emulate-issued-code']));
    $callback->assertRedirect();

    $user = User::where('email', 'acceptance-trial@example.test')->first();

    expect($user)->not->toBeNull();
    expect($user->workos_id)->not->toBeNull();

    // External ID linkage happened server-side against emulate — confirm by
    // re-fetching the user from emulate through the package, not the SDK directly.
    $linked = Authkit\Authkit\Facades\Authkit::users()->getUserByExternalId((string) $user->id);
    expect($linked->id)->toBe($user->workos_id);

    $organization = $user->organizations()->first(); // exact relation name assumed — see Assumed Interfaces
    expect($organization)->not->toBeNull();
    expect($organization->workos_id)->not->toBeNull();

    $this->actingAs($user, 'workos');
    expect($user->can('posts.manage'))->toBeTrue();
    expect($user->can('nonexistent.permission'))->toBeFalse();
});
```

**Key decisions**:

- This test is emulate-backed, not MockHandler-backed — the contract's own check is explicit ("suite runs the workbench app against `workos/emulate`"). A MockHandler version would prove the package calls the right SDK methods but not that a real (emulated) WorkOS round trip produces a working session — the whole point of an acceptance suite.
- The test does not assert on FGA (`Authkit::check()`) — the contract's Acceptance criterion is scoped to RBAC (`$user->can()` from claims) specifically, not FGA. Adding an FGA assertion here would be scope creep into a criterion the contract didn't write; FGA has its own coverage via Phase 5's suite and Component 5's workbench route.

**Implementation steps**:

1. Confirm the real login→callback mechanics against Phase 2's shipped code — the code-exchange step above is a placeholder for whatever emulate's authorization-URL/callback contract actually requires (PKCE challenge, `state` round trip, etc.).
2. Confirm the real relation name from the workbench user model to its organization(s) — Phase 3's actual naming wins over `organizations()` above.
3. Seed a permission (`posts.manage`) on the acceptance-trial user's membership in `workos-emulate.config.yaml` (Component 1) before this test can pass.
4. Name the test file `tests/Feature/AcceptanceTest.php` with the description containing the literal word "Acceptance" so `--filter=Acceptance` matches.

**Feedback loop**:

- **Playground**: `vendor/bin/pest --filter=Acceptance` against a freshly booted `workos/emulate` (start it manually while iterating: `npx @workos/emulate --config workos-emulate.config.yaml`).
- **Experiment**: run once against a cold emulate instance (no prior runs) and once against a warm one that already has the seeded org from a previous test run — the org-auto-create step must be idempotent either way (first-login-creates vs. already-linked-finds), not error on a second run.
- **Check command**: `vendor/bin/pest --filter=Acceptance`

### Component 7 — ProjectionBoundary test

**Gated on `spec-phase-3.md`**: the whitelist below names `organizations` and `workos_memberships` on assumed table names. Do not start until `spec-phase-3.md` exists or Phase 3's real code has shipped — see the top-of-document note on the unresolved transitive prereq.

**Pattern to follow**: none yet — first schema-introspection test in the suite.

**Overview**: Makes the "WorkOS stays canonical" doctrine mechanically falsifiable: any table carrying a `workos_id` or `external_id` column that isn't on an explicit whitelist fails the build, in either direction (an undeclared new table, or a whitelisted table gone missing).

```php
use Illuminate\Support\Facades\Schema;

test('ProjectionBoundary: local WorkOS-shaped state is limited to the declared whitelist', function () {
    // Explicit whitelist per the contract's phase-13 binding direction.
    // Table names for the org model and memberships projection are assumed —
    // reconcile against Phase 3/4's actual migrations before trusting a failure here.
    $whitelist = [
        'users',                          // user link columns (workos_id, external_id)
        'organizations',                  // org model (workos_id, external_id) — Phase 3, assumed table name
        'workos_organization_domains',    // domains projection
        'workos_memberships',             // org-membership projection
        'workos_event_cursor',            // sync bookkeeping — not itself claims-shaped, but explicitly declared allowed
    ];

    $offenders = [];

    foreach (Schema::getTables() as $tableInfo) {
        $table = $tableInfo['name'];
        $columnNames = collect(Schema::getColumns($table))->pluck('name');

        $isWorkosShaped = $columnNames->contains('workos_id') || $columnNames->contains('external_id');

        if ($isWorkosShaped && ! in_array($table, $whitelist, true)) {
            $offenders[] = $table;
        }
    }

    expect($offenders)
        ->toBeEmpty("Undeclared WorkOS-shaped table(s) found, violating the projection boundary: " . implode(', ', $offenders));

    foreach ($whitelist as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Declared projection table [$table] is missing — a promised projection was never migrated.");
    }
});
```

**Key decisions**:

- Detection is column-name-based (`workos_id`/`external_id` presence), not model-reflection-based. The contract's own check description says "arch test with **explicit whitelist**" — a fixed table-name list is the most literal, most auditable reading of that, and sidesteps needing to know exact Phase 3/5/8 model class names to work.
- This is a plain Pest `test()` against a booted Testbench app with real migrations run, not a Pest `arch()` rule — `arch()` (via the underlying static-analysis plugin) cannot introspect a runtime database schema. The file still lives under `tests/Feature/` for this reason, not `tests/ArchTest.php`.
- The whitelist is bidirectional on purpose: it catches both scope creep (a new undeclared table) and silent regression (a promised projection quietly dropped by a later refactor).

**Implementation steps**:

1. Before trusting a red result, confirm the whitelist's two assumed entries (`organizations`, `workos_memberships`) against Phase 3/4's real migrations; update the array if the names differ — the test's *logic* does not change.
2. Ensure the Testbench app actually runs both the package's publishable migrations and the workbench's own (e.g., `posts`) — `posts` has neither `workos_id` nor `external_id` so it is correctly invisible to this check without needing to be listed.
3. If Phases 8/9 (API Keys, Vault) introduce any WorkOS-shaped local table not named above (e.g., a local API-key cache), that is new information this test should catch — do not pre-emptively whitelist something not in the contract's five named entries; let the test fail and investigate.

**Feedback loop**:

- **Playground**: `vendor/bin/pest --filter=ProjectionBoundary` against the Testbench app's migrated SQLite database.
- **Experiment**: temporarily add a throwaway migration creating a table with a `workos_id` column not on the whitelist, confirm the test fails and names that exact table; remove the migration, confirm green again — proves the negative case actually fires before trusting the positive case.
- **Check command**: `vendor/bin/pest --filter=ProjectionBoundary`

### Component 8 — IdiomCoverage test

**Pattern to follow**: none yet — first container/registration-introspection test.

**Overview**: Makes the "Laravel-native maximalism" doctrine mechanically falsifiable: every mechanism the contract names (guard, middleware, form requests, cast, route macro, Gate/Blade directives, generators, Pennant driver, filesystem driver) must exist **and be registered**, not merely exist as an unwired class.

```php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

test('IdiomCoverage: workos guard driver is registered', function () {
    config()->set('auth.guards.workos-idiom-probe', ['driver' => 'workos', 'provider' => 'users']);
    expect(fn () => Auth::guard('workos-idiom-probe'))->not->toThrow(\InvalidArgumentException::class);
});

test('IdiomCoverage: authkit-key guard driver is registered', function () {
    config()->set('auth.guards.authkit-key-idiom-probe', ['driver' => 'authkit-key', 'provider' => 'users']);
    expect(fn () => Auth::guard('authkit-key-idiom-probe'))->not->toThrow(\InvalidArgumentException::class);
});

test('IdiomCoverage: middleware aliases are registered', function () {
    $aliases = app(\Illuminate\Routing\Router::class)->getMiddleware();
    expect($aliases)->toHaveKeys(['authkit.session', 'authkit.org', 'authkit.mcp']);
});

test('IdiomCoverage: auth form requests exist', function () {
    foreach ([
        \Authkit\Authkit\Http\Requests\AuthKitLoginRequest::class,
        \Authkit\Authkit\Http\Requests\AuthKitAuthenticationRequest::class,
        \Authkit\Authkit\Http\Requests\AuthKitLogoutRequest::class,
    ] as $class) {
        expect(class_exists($class))->toBeTrue("$class does not exist.");
        expect(is_subclass_of($class, \Illuminate\Foundation\Http\FormRequest::class))->toBeTrue();
    }
});

test('IdiomCoverage: Vaulted cast exists and implements CastsAttributes', function () {
    expect(class_exists(\Authkit\Authkit\Casts\Vaulted::class))->toBeTrue();
    expect(
        in_array(
            \Illuminate\Contracts\Database\Eloquent\CastsAttributes::class,
            class_implements(\Authkit\Authkit\Casts\Vaulted::class),
        )
    )->toBeTrue();
});

// No Gate/Blade-directive test and no route-macro test are included here.
// Both mechanisms are named in the contract's idiom list, but no real
// directive name or macro name is confirmed by any existing spec — a
// fabricated name would give false confidence, and a fake-passing tautology
// (e.g. `expect($x || true)->toBeTrue()`) is worse than no test at all for a
// suite whose entire purpose is making promises mechanically falsifiable.
// Both gaps are tracked in Open Items, not silently dropped or faked:
// add a real `test('IdiomCoverage: Gate/Blade directive is wired', ...)`
// asserting `Blade::getCustomDirectives()` has the confirmed key, and a real
// `test('IdiomCoverage: route macro is registered', ...)` asserting
// `Route::hasMacro('{realName}')`, once Phase 2/3/5's actual names are known.
// This file's merge should not be blocked on resolving them — it should ship
// with these two named gaps open rather than a placeholder that lies green.

test('IdiomCoverage: generator commands are registered', function () {
    expect(Artisan::all())->toHaveKey('make:workos-listener');
});

test('IdiomCoverage: Pennant driver is registered', function () {
    config()->set('pennant.default', 'workos-idiom-probe');
    config()->set('pennant.stores.workos-idiom-probe', ['driver' => 'workos']);
    expect(fn () => Feature::driver('workos-idiom-probe'))->not->toThrow(\InvalidArgumentException::class);
});

test('IdiomCoverage: vault filesystem driver is registered', function () {
    config()->set('filesystems.disks.idiom-probe', ['driver' => 'vault', 'wraps' => 'local']);
    expect(fn () => Storage::disk('idiom-probe'))->not->toThrow(\InvalidArgumentException::class);
});
```

**Key decisions**:

- Each mechanism gets its own `test()`, not one giant assertion — a failing IdiomCoverage run must name *which* mechanism regressed, not just that "idiom coverage" broke.
- Registration is checked by attempting to **resolve** the driver/guard/store through Laravel's own manager classes (`Auth::guard()`, `Feature::driver()`, `Storage::disk()`) with a throwaway probe config, rather than reaching into `Manager::$customCreators` via reflection — this proves the *contract* Laravel expects (a resolvable driver) rather than an implementation detail of how it got registered.
- The Gate/Blade-directive and route-macro mechanisms named in the contract's idiom list have **no assertion at all** in the code above — for the same reason: no directive name or macro name is confirmed by any existing spec. A fabricated name would give false confidence, and a fake-passing tautology (e.g. the `|| true` shape this component's own review caught and removed) is strictly worse than an honest gap, since it makes a green suite claim coverage it doesn't have. Both are tracked in Open Items and named in Failure Modes, not silently dropped and not faked.

**Implementation steps**:

1. Run this file as an early diagnostic *before* the rest of the phase's workbench work — a red IdiomCoverage result here is the fastest way to discover which earlier phase left a mechanism unregistered.
2. Add the missing Gate/Blade-directive and route-macro assertions as real checks once their names are confirmed against the shipped Phase 2/3/5 code — do not merge this file with either gap left unresolved past a documented Open Item.
3. Do not add a test for a mechanism not named in the contract's idiom list (goal #2) or the phase's own scope-table rows — this file proves promised coverage, it does not grow the promise.

**Feedback loop**:

- **Playground**: `vendor/bin/pest --filter=IdiomCoverage` against the Testbench app.
- **Experiment**: temporarily comment out one registration line in `AuthkitServiceProvider` (e.g., the `Auth::extend('workos', ...)` call), confirm the corresponding test — and only that one — goes red; restore it.
- **Check command**: `vendor/bin/pest --filter=IdiomCoverage`

### Component 9 — Workbench zero-SDK-reference enforcement

**Pattern to follow**: none — this wraps a shell grep in a Pest assertion for repeatability.

**Overview**: Turns the contract's literal grep check into something CI runs the same way it runs every other test, instead of a manual step someone forgets.

```php
test('WorkbenchZeroSdkReference: workbench never references the WorkOS SDK directly', function () {
    $workbenchPath = base_path('workbench');
    // Mirrors: grep -rE '(use |\\)WorkOS\\' workbench/
    $process = new Symfony\Component\Process\Process([
        'grep', '-rE', '(use |\\\\)WorkOS\\\\', $workbenchPath,
    ]);
    $process->run();

    // grep exits 1 when there are zero matches — that is the PASSING state here.
    expect($process->getExitCode())
        ->toBe(1, "Found direct WorkOS SDK reference(s) in workbench/:\n" . $process->getOutput());
});
```

**Key decisions**:

- Shells out to the real `grep` with the contract's exact pattern rather than reimplementing the regex in PHP — any drift between "what the test checks" and "what the release-gate success criterion checks" is a bug; using the identical command removes that entire class of drift.
- `Symfony\Component\Process\Process` is already a transitive dependency (via Laravel's console component) — no new `require` needed.
- Asserts exit code `1` (no matches), not output emptiness — matches grep's own documented exit-code contract exactly, including on the rare case of a `workbench/` file with no readable permission (`grep` returns 2 in that case, and this test should — correctly — also fail, since exit code `1` is the only passing state).

**Implementation steps**:

1. Add the test to `tests/Feature/WorkbenchZeroSdkReferenceTest.php`.
2. Run it *after* every other component in this phase lands its workbench files — this is the final gate before considering the workbench build-out complete.
3. If it ever goes red, the fix is almost always in the offending workbench file (route a claim through a package accessor instead of the SDK class) — not in this test.

**Feedback loop**:

- **Playground**: `grep -rE '(use |\\)WorkOS\\' workbench/` run directly in a terminal while iterating on any workbench file, for instant feedback without booting Pest at all.
- **Experiment**: temporarily add a `use WorkOS\UserManagement;` line to any workbench file, confirm the test fails with that exact file named in the output; remove it, confirm green.
- **Check command**: `vendor/bin/pest --filter=WorkbenchZeroSdkReference` (or the raw grep above for the sub-second version)

### Component 10 — `docs/quickstart.md`

**Overview**: The mechanically-checked half of the contract's split quickstart criterion — ≤5 numbered top-level steps, composer require → working login with orgs+RBAC live.

```markdown
# Quickstart

Get AuthKit Laravel running in a fresh Laravel app in under 10 minutes.

1. **Require the package.**

   ```bash
   composer require birdcar/authkit-laravel
   ```

2. **Install AuthKit.** This publishes config and migrations, appends placeholder `WORKOS_*` keys to your `.env`, and registers the auth routes and `workos` guard.

   ```bash
   php artisan authkit:install
   ```

   Open `.env` and paste your real `WORKOS_CLIENT_ID`, `WORKOS_API_KEY`, `WORKOS_REDIRECT_URI`, and `WORKOS_COOKIE_PASSWORD` from your [WorkOS Dashboard](https://dashboard.workos.com).

3. **Run migrations.**

   ```bash
   php artisan migrate
   ```

4. **Log in.** Visit `/login` in your browser (or `route('authkit.login')` from your own controller). Organizations and role-based authorization are live immediately from the JWT claims on your first login — no additional setup.

That's it. See [the full documentation](README.md) for RBAC, FGA, Feature Flags, Vault, Audit Logs, and everything else the package wraps.
```

**Key decisions**:

- Four top-level numbered steps, one under the contract's ≤5 ceiling — the margin is deliberate: it survives a reviewer later deciding one step needs splitting without immediately breaking the mechanical check.
- The dashboard-key-pasting instruction lives as an unnumbered sub-detail under step 2, not its own numbered step — it's part of "install," not a separate action.

**Implementation steps**:

1. Write the file above, adjusting the exact `authkit:install` output description once Phase 1's real command output is confirmed.
2. Time an actual walkthrough by someone who has not run it before (this is also Component 12's human trial — the two can be the same session).
3. Do not add a 6th numbered step later without re-running the grep check below.

**Feedback loop**:

- **Playground**: a scratch Laravel app (`laravel new authkit-quickstart-trial`) with the package installed from a local path repository, or from the release tag once tagged.
- **Experiment**: count steps after every edit; time the walkthrough once the wording stabilizes.
- **Check command**: `grep -cE '^[0-9]+\.' docs/quickstart.md` — must print `4` or lower (≤5 per the contract; this spec targets 4)

### Component 11 — `feature_list.json`: real 13-phase roadmap

**Overview**: Replaces the generic scaffolder placeholders (`feat-002`..`feat-005`) with one feature entry per contract phase, dependency graph mirroring `contract-data.json`'s `execution.phases[].prereqs` exactly.

```json
{
  "features": [
    { "id": "feat-001", "name": "Project Setup", "description": "Confirm the project can install dependencies, run verification, and start from a clean checkout", "dependencies": [], "status": "done", "evidence": "2026-08-03 ./init.sh passed" },
    { "id": "feat-002", "name": "Foundation & Client Binding", "description": "WorkOS client container binding, config/authkit.php, authkit:install skeleton, emulate/MockHandler test harness, Phase 1 token audit", "dependencies": ["feat-001"], "status": "not-started", "evidence": "" },
    { "id": "feat-003", "name": "Auth Core & Sealed Sessions", "description": "workos guard, sealed-session iss/aud checks, refresh middleware, user projection, impersonation", "dependencies": ["feat-002"], "status": "not-started", "evidence": "" },
    { "id": "feat-004", "name": "Organizations & Org Context", "description": "HasWorkosOrganization trait, domains projection, current-org resolution, org-switch route, tenant middleware", "dependencies": ["feat-003"], "status": "not-started", "evidence": "" },
    { "id": "feat-005", "name": "Events Pipeline & Webhooks", "description": "authkit:work poller, typed + generic events, webhook registrar, make:workos-listener, php artisan dev wiring", "dependencies": ["feat-004"], "status": "not-started", "evidence": "" },
    { "id": "feat-006", "name": "Authorization (RBAC + FGA)", "description": "Gate::before claims integration, role/permission facade, FGA Check API wrapper, HasWorkosResource trait", "dependencies": ["feat-003"], "status": "not-started", "evidence": "" },
    { "id": "feat-007", "name": "Audit Logs & Admin Portal", "description": "HasAuditLogs trait, AuditLog facade, export/retention passthrough, portal-link facade", "dependencies": ["feat-005"], "status": "not-started", "evidence": "" },
    { "id": "feat-008", "name": "Feature Flags (Pennant Driver)", "description": "workos Pennant driver: claim-first in HTTP, WorkOS API fallback in queue/console", "dependencies": ["feat-003"], "status": "not-started", "evidence": "" },
    { "id": "feat-009", "name": "API Keys Guard", "description": "authkit-key guard, key permissions into Gate, issue/revoke on user + org models", "dependencies": ["feat-006", "feat-004"], "status": "not-started", "evidence": "" },
    { "id": "feat-010", "name": "Vault", "description": "Vaulted cast, vault filesystem driver, Vault KV facade", "dependencies": ["feat-002"], "status": "not-started", "evidence": "" },
    { "id": "feat-011", "name": "Connect & MCP Auth", "description": "Connect application registry facade, MCP bearer middleware, /.well-known/oauth-protected-resource route", "dependencies": ["feat-003", "feat-004"], "status": "not-started", "evidence": "" },
    { "id": "feat-012", "name": "Pipes", "description": "connectedAccounts relations, access-token fetch with auto-refresh, org provider-config passthrough", "dependencies": ["feat-004"], "status": "not-started", "evidence": "" },
    { "id": "feat-013", "name": "Depth Extensions (Full Tier)", "description": "Invitations, JWT template + CORS passthroughs, Groups API, FGA resource-graph conveniences, opt-in FGA cache", "dependencies": ["feat-006", "feat-004", "feat-005"], "status": "not-started", "evidence": "" },
    { "id": "feat-014", "name": "Integration, Quickstart & Release Readiness", "description": "Workbench build-out, Acceptance/ProjectionBoundary/IdiomCoverage suites, quickstart doc, CI emulate step, README, Boost skill, release checklist", "dependencies": ["feat-005", "feat-006", "feat-007", "feat-008", "feat-009", "feat-010", "feat-011", "feat-012", "feat-013"], "status": "in-progress", "evidence": "" }
  ]
}
```

**Key decisions**:

- IDs are sequential (`feat-002`..`feat-014`), not phase-numbered (`feat-phase-1`) — matches the existing `feat-001` convention already in the file rather than introducing a second ID scheme.
- Dependencies are copied directly from `contract-data.json`'s `execution.phases[].prereqs`, translated from phase titles to feature IDs — this is a mechanical translation, not a judgment call, and should be re-derived from the contract if it and this table ever disagree.
- Statuses above are written as of *this spec's authoring* (before Phases 2–12 have executed). **Whoever executes this phase must update every status to reflect actual, evidence-backed completion at that time** — do not copy the `"not-started"` placeholders verbatim if Phases 2–12 are in fact done by then; conversely, do not mark this file `"done"` for any phase without that phase's own `composer test` evidence to cite, per this repo's own Definition of Done.

**Implementation steps**:

1. At execution time, for each of `feat-002`..`feat-013`, check that phase's own evidence (its `composer test` run, its spec's Validation Commands) before writing `"status": "done"`. If any prerequisite phase is not actually done, **stop this phase's remaining work and report it** — Phase 13 cannot honestly claim readiness on top of an unfinished prerequisite.
2. Overwrite `feature_list.json` with the full structure above (adjusted statuses/evidence).
3. Update `progress.md` to reflect the new roadmap replacing the old placeholder note.

Feedback loop intentionally omitted — this is a data file, not iterative logic; the only "check" is that it stays valid JSON, matches the contract's dependency graph, and the pre-write cross-check in step 1 above is done honestly.

### Component 12 — README, Boost skill regeneration & release checklist

**Overview**: Three documentation deliverables, each with a distinct audience: `README.md` (anyone browsing the package), the Boost skill (an AI agent integrating the package into a consuming app), `docs/release-checklist.md` (whoever cuts the release).

**README.md** — rewrite past the current skeleton boilerplate (`<!-- Add a basic usage example here. -->`) to:
- Lead with what it replaces (`laravel/workos`) and why (headless AuthKit plumbing: orgs, RBAC, FGA, events, audit logs, feature flags, vault, API keys, Connect/MCP, Pipes — one package).
- Link `docs/quickstart.md` prominently instead of duplicating install steps.
- List the 16 scope-table areas as a scannable feature table.
- Keep the existing publish-tag documentation (still accurate) and Credits/License/Security sections as-is.

**Boost skill regeneration** — follow `.agents/skills/package-generate-skill/SKILL.md`'s own workflow verbatim:
1. Inspect the real implementation: service provider, facades, public classes, commands, config, routes, migrations, events, views, publish tags, tests (i.e., everything Phases 1–12 actually shipped, not this spec's assumptions about it).
2. Inspect `README.md` (once rewritten) and `docs/quickstart.md`.
3. Update `resources/boost/skills/authkit-laravel-development/SKILL.md`'s Workflow/References/Examples/Anti-patterns sections with the real install command, the real facades/guards/traits, and one practical integration example per major area — replacing every placeholder line currently in that file (`"Document how to integrate Authkit Laravel here..."`, `"no additional resource files for this skill"`, etc.).
4. Do not document workbench-only routes or internal manager classes as consumer-facing API — the anti-pattern list in that same skill file already names this.

**Release checklist** (`docs/release-checklist.md`) — new file:

```markdown
# Release Checklist

Run in order. Do not tag until every item is checked.

- [ ] `composer test` passes locally
- [ ] CI matrix green on the release commit: `gh run watch --exit-status <run-id>` for `tests.yml` — all cells (PHP 8.3–8.5 × Laravel 12/13 × prefer-lowest/prefer-stable × ubuntu/windows)
- [ ] `vendor/bin/pest --filter=Acceptance` — exits 0
- [ ] `vendor/bin/pest --filter=ProjectionBoundary` — exits 0
- [ ] `vendor/bin/pest --filter=IdiomCoverage` — exits 0
- [ ] `vendor/bin/pest --filter=WorkbenchZeroSdkReference` — exits 0
- [ ] `grep -cE '^[0-9]+\.' docs/quickstart.md` ≤ 5
- [ ] `ls tests/Feature/*Test.php | wc -l` ≥ 16
- [ ] `grep -rn 'env(' src/ --include='*.php'` — exits 1 (no matches)
- [ ] `feature_list.json` reflects true, evidence-backed status for every phase
- [ ] CHANGELOG.md / release notes drafted

## Human Quickstart Trial Log

A recorded human trial reproducing `docs/quickstart.md` end-to-end on a **fresh** Laravel app, timed, with orgs + RBAC confirmed live. Required before tagging; the result goes in the release notes verbatim.

| Trialist | Date | Fresh app? | Elapsed time | Orgs + RBAC live? | Notes |
|---|---|---|---|---|---|
| _(name)_ | _(YYYY-MM-DD)_ | Y/N | _(mm:ss)_ | Y/N | _(anything that snagged)_ |

_If elapsed time exceeds 10 minutes or Orgs+RBAC live = N, the release is blocked — fix the quickstart or the underlying behavior, then re-trial._
```

**Key decisions**:

- The release checklist duplicates several already-scripted checks as checkboxes rather than assuming "CI is green" implies all of them — a release can be cut from a commit CI hasn't finished checking yet, so the checklist is the actual human gate, not a restatement of automation.
- The human trial table's columns are exactly what the contract's criterion names: who, date, elapsed time — plus two pass/fail columns this spec adds because "≤10 minutes" alone doesn't capture "but did orgs/RBAC actually work."

**Implementation steps**:

1. Write `docs/release-checklist.md` as above.
2. Rewrite `README.md`.
3. Run the Boost-skill regeneration workflow and update `resources/boost/skills/authkit-laravel-development/SKILL.md`.
4. Do not perform the actual human trial as part of implementing this spec unless a real trialist is available — leave the log table's row blank/template until someone runs it; recording a fabricated trial would violate the entire point of the criterion.

Feedback loop intentionally omitted for README/Boost-skill prose — these are documentation, not logic; their "check" is the cross-reference against real implementation named in each workflow step above, not a runnable command.

## Data Model

No new WorkOS-shaped tables (would violate ProjectionBoundary by construction). One workbench-only, app-owned schema change:

### Schema Changes

```sql
-- Modifies the existing workbench `posts` table (introduced by Phase 6)
ALTER TABLE posts ADD COLUMN secret_note TEXT NULL;
```

No new indexes required — `secret_note` is never queried on, only cast through `Vaulted` on read/write.

## Workbench Route Map (demonstration surface only — not package API)

These routes exist purely to prove package behavior from a real HTTP context; they are not part of the package's public contract and consuming apps do not install them.

| Method | Path | Route name | Demonstrates |
|---|---|---|---|
| `GET` | `/dashboard` | `demo.dashboard` | Post-login state: user, org, claims (Component 2) |
| `POST` | `/demo/audit-log` | `demo.audit.log` | `HasAuditLogs` + `AuditLog::log()` (Component 3) |
| `GET` | `/demo/portal/{intent}` | `demo.portal.link` | `Authkit::portalLink()`, all 7 intents (Component 3) |
| `GET` | `/demo/rbac` | `demo.rbac.check` | `$user->can()` from claims (Component 5) |
| `GET` | `/demo/fga/{resourceExternalId}` | `demo.fga.check` | `Authkit::check()` (Component 5) |
| `GET` | `/demo/flags` | `demo.flags.check` | Pennant claim-first check (Component 5) |
| `POST` | `/demo/api-keys` | `demo.api-keys.issue` | Issue an API key (Component 5) |
| `DELETE` | `/demo/api-keys/{apiKeyId}` | `demo.api-keys.revoke` | Revoke an API key (Component 5) |
| `GET` | `/demo/vault` | `demo.vault.round-trip` | Cast + filesystem + KV round trip (Component 5) |
| `GET` | `/demo/connect` | `demo.connect.applications` | Connect application registry (Component 5) |
| — | (already exists) | `pipes.index`/`connect`/`disconnect` | Pipes (Phase 11, confirmed — unmodified) |
| — | (already exists) | (inline, Phase 12) | Invitations, Groups (Phase 12, confirmed — unmodified) |

Console commands added by this phase:

| Command | Purpose |
|---|---|
| `php artisan demo:feature-flags {user}` | Pennant check with no HTTP session — proves the WorkOS-API fallback path |
| `php artisan demo:trigger-event` | Package-API write producing a real WorkOS event, for observing the sidecar round trip |

## Testing Requirements

### Feature Tests

| Test File | Coverage | Test path |
|---|---|---|
| `tests/Feature/AcceptanceTest.php` | Login → link → org-create → `$user->can()` end-to-end | emulate |
| `tests/Feature/ProjectionBoundaryTest.php` | Local schema contains only whitelisted WorkOS-shaped tables, and all of them | none (schema introspection) |
| `tests/Feature/IdiomCoverageTest.php` | Every promised Laravel mechanism exists and resolves | none (container/registration introspection) |
| `tests/Feature/WorkbenchZeroSdkReferenceTest.php` | `workbench/` contains zero `use WorkOS\`/`\WorkOS\` references | none (filesystem grep) |

**Key test cases**:

- Acceptance: cold-emulate first login (creates user+org) and warm-emulate second login by the same user (finds existing user+org, does not duplicate) — both must pass.
- ProjectionBoundary: negative case (inject an undeclared WorkOS-shaped table via a throwaway migration, confirm failure) proven once during implementation, then removed — see Component 7's feedback loop.
- IdiomCoverage: each of the ~9 mechanisms gets its own assertion; a regression in one must not mask a regression in another (no early-return / no combined boolean).
- WorkbenchZeroSdkReference: negative case (inject a throwaway `use WorkOS\...;` line, confirm failure naming the file) proven once, then removed.

### Manual Testing

- [ ] Run the full quickstart on a genuinely fresh `laravel new` app, timed, per `docs/release-checklist.md`'s human trial log
- [ ] `composer serve` the workbench app, log in through emulate, click/curl through every route in the Workbench Route Map above
- [ ] Confirm the Boost skill, opened fresh, gives an AI agent enough to integrate the package without reading `src/` directly

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| Workbench build-out (Components 2–5) | **Demo drift** — a workbench route calls a package method whose real signature changed after this spec was written | Phase 2/3/5/etc.'s actual implementation lands with different names than this spec's Assumed Interfaces table | Workbench fails to boot / 500s on the demo route, not caught by ProjectionBoundary or IdiomCoverage (which check existence/registration, not call-site correctness) | Reconcile every "assumed" row in the Standalone-Implementability table against real code *before* wiring the corresponding controller — this is explicit, numbered work in each component's implementation steps, not optional cleanup |
| Acceptance suite | **Stale claim assumption** — the Phase 1 token audit's assumed default presence of `role`/`permissions` claims turns out wrong for this WorkOS environment/JWT template | The Dashboard's JWT template was edited (Phase 12's `JwtTemplateUpdated` warning) or the audit's finding was environment-specific | `$user->can()` silently returns `false` for a permission the seed data actually grants — test fails, but could be misread as an RBAC bug rather than a claims-presence regression | Assert on `session claims are non-null` as a precondition inside the Acceptance test before asserting on `can()`'s boolean result, so a claims-absence failure is distinguishable from a real RBAC-logic failure |
| Acceptance suite | **Emulate's stricter-than-prod refresh-token rotation** breaks a second-login-by-same-user assertion | Emulate always rotates refresh tokens on every `authenticate` call (documented drift vs. production) | A naive "log in twice, expect the same session artifacts" assertion could flake even though production would behave differently | Assert only on durable identifiers (`workos_id`, `external_id`, org linkage) across the two logins, never on token values themselves |
| ProjectionBoundary | **False negative from an app-owned table that happens to reuse the column name `external_id` for something unrelated to WorkOS** | A future workbench (or consumer-app) table names its own foreign-key-ish column `external_id` for an unrelated integration | The test flags a table that is not actually a WorkOS projection, blocking an unrelated feature | Acceptable as a deliberate false-positive-favoring design — the doctrine's cost of a rare false alarm is lower than the cost of a real silent projection leak; document this trade-off inline in the test file, don't "fix" it by narrowing the column check |
| ProjectionBoundary | **Whitelist rot** — a later phase (post-release) adds a genuinely new declared projection and updates the contract but not this test | Package evolves after v1 | The new legitimate table fails the build forever until someone notices | Not mitigated by this phase — flagged as a maintenance responsibility for whoever owns the next contract revision; this spec only guarantees the whitelist is correct as of the 16-area v1 scope |
| IdiomCoverage | **False positive — a mechanism is "registered" but non-functional** (e.g., a guard driver that resolves without throwing but returns a broken `Authenticatable`) | The test only proves resolvability, not correctness | A real behavioral bug in, say, the `workos` guard ships past IdiomCoverage undetected | Deliberate scope boundary, not a gap: IdiomCoverage answers "does the idiom exist," the Acceptance suite and each area's own Phase N suite answer "does it work" — see Component 8's Key Decisions |
| IdiomCoverage | **Two named idioms ship with no assertion at all** — the Gate/Blade-directive and route-macro checks are open gaps, not stubbed-in tests | Implementer treats the file as "done" once the other seven mechanisms are green, without adding the two missing assertions once their real names are confirmed | Two of the contract's named idioms (Gate/Blade directives, route macro) are never actually verified, silently passing a green suite that proves less than it claims | Named explicitly in this spec's Component 8 and Open Items; code review for this phase should treat either gap left open past the point where Phase 2/3/5's real names are known as a blocking finding, not a nit |
| Zero-SDK-reference grep | **Comment/docblock false positive** — a workbench file's comment mentions `\WorkOS\UserManagement` descriptively, not as an import | A developer writes an explanatory comment referencing the SDK class by name | The grep (correctly, per the contract's literal pattern) fails the build on prose, not just code | Working as specified — the contract's check has no comment-exclusion clause; write workbench comments about "the underlying WorkOS concept" without spelling out the FQCN, same discipline as the code itself |
| CI emulate boot step | **Windows background-process flakiness** — `Start-Process`-launched emulate isn't ready when the health poll starts, or orphans past job completion | Windows process-group semantics differ from POSIX `&`/`disown` | Windows matrix cells intermittently fail on the emulate-backed suites, unrelated to any real regression | OS-conditional boot steps (bash `&`+`disown` on Linux, `Start-Process` + `Invoke-WebRequest` polling loop on Windows per Component design) with a 30-second bounded retry and log-dump-on-failure, so a flake is diagnosable rather than a silent timeout |
| CI emulate boot step | **emulate never becomes healthy** (bad seed file, port collision, npx resolution failure) | Malformed `workos-emulate.config.yaml`, or a future emulate major version breaking the pinned `^0.6` range | Every emulate-backed suite in that matrix cell fails, potentially masking unrelated regressions as "environment broken" | Bounded retry loop dumps captured stdout/stderr on timeout (both OS branches); pin stays at `^0.6` deliberately, not `latest`, so a breaking emulate release doesn't surprise CI |
| Quickstart doc | **Step-count regression** — a future doc edit (post-v1) adds a 6th numbered step without anyone re-running the grep | Ordinary doc editing drift | The mechanical success criterion silently breaks | `docs/release-checklist.md` includes the grep check as a release gate, not just a one-time authoring check — catches drift at every future release, not only this one |
| Human trial log | **Fabricated or stale trial entry** | Pressure to "just fill in the table" without actually running the trial | The one criterion nothing in CI can verify becomes worthless if faked | Explicit implementation-step instruction (Component 12) not to fill the log unless a real trialist runs it; the release checklist blocks tagging on elapsed time / orgs+RBAC columns being honestly Y |
| `feature_list.json` rewrite | **Status dishonesty** — marking `feat-002`..`feat-013` `"done"` without checking their real evidence | Time pressure to "just finish Phase 13" | Undermines the entire harness's Definition-of-Done discipline; a later session trusts a `"done"` status that was never earned | Component 11's implementation steps require citing each phase's own evidence before writing `"done"`; if any phase isn't actually complete, stop and report rather than paper over it |
| Boost skill regeneration | **Skill drifts from implementation again immediately after this phase**, same failure the `package-generate-skill` skill's own anti-patterns section already names | Phase 13 regenerates it once; a later patch changes a facade/command without re-running the workflow | Consuming-app AI agents get bad integration guidance | Not mitigated by this phase beyond doing the regeneration correctly once — recurring discipline is process, not code, and belongs to whoever ships the next change |

## Validation Commands

```bash
# Full validation (must be green before this phase is considered done)
composer test

# The three release-readiness checks this phase owns
vendor/bin/pest --filter=Acceptance
vendor/bin/pest --filter=ProjectionBoundary
vendor/bin/pest --filter=IdiomCoverage
vendor/bin/pest --filter=WorkbenchZeroSdkReference

# The mechanical grep checks named by the contract directly
grep -rE '(use |\\)WorkOS\\' workbench/          # must exit 1 (zero matches)
grep -cE '^[0-9]+\.' docs/quickstart.md          # must print ≤5
ls tests/Feature/*Test.php | wc -l               # must be ≥16
grep -rn 'env(' src/ --include='*.php'           # must exit 1 (zero matches)

# CI matrix, once pushed
gh run watch --exit-status <run-id-for-tests.yml-on-the-release-commit>

# Local emulate boot, for manually reproducing the CI step
npx --yes @workos/emulate@^0.6 --config workos-emulate.config.yaml --port 4100 &
curl -sf http://localhost:4100/health
```

## Rollout Considerations

- **Feature flag**: none — this phase has no runtime behavior to gate; it is proof and documentation.
- **Monitoring**: the CI matrix badge in `README.md` (already wired to `tests.yml`) is the ongoing signal; no new monitoring infrastructure.
- **Alerting**: none needed beyond normal CI failure notifications.
- **Rollback plan**: per the contract's express-run decision, this work lands directly on `main` with no isolation branch. If any component in this phase needs to be rolled back, `git revert` the specific commit(s); the recorded recovery anchor for the whole project is `git reset --hard 4d04d0b` (pre-Phase-1 state) if a full restart is ever needed — not expected to be used for this phase alone.
- **Release gate**: `docs/release-checklist.md` (Component 12) is the actual go/no-go artifact — this phase is not "done" until every item on that checklist can be checked honestly, including the human trial.

## Open Items

- [ ] Confirm the real workbench org model name/table (assumed `Workbench\App\Models\Organization` / `organizations`) against Phase 3's shipped code before trusting ProjectionBoundary's whitelist or the Acceptance suite's org-relation call.
- [ ] Confirm `workos_memberships`'s real column shape against Phase 3/4's shipped migrations.
- [ ] Confirm the real login→callback mechanics (state/PKCE handling) against Phase 2's shipped form requests before trusting the Acceptance suite's callback step as written.
- [ ] Identify the real Gate/Blade custom directive name(s) (Phase 2/5) and add the missing assertion to `IdiomCoverageTest.php` — this gap must not ship unresolved.
- [ ] Identify the real route macro name (contract's idiom list names "route macros" but no spec has confirmed one yet) and add the missing IdiomCoverage assertion for it.
- [ ] Confirm whether Phase 1's local test-harness already auto-boots `workos/emulate` for `composer test:unit`; if so, decide whether the new CI step (Component design) is redundant-but-harmless or should be simplified to reuse that same mechanism instead of a second bespoke boot.
- [ ] Confirm emulate's default `WORKOS_CLIENT_ID` value (or whichever client ID Phase 1's test harness already standardized on) for use in the CI environment variables — the context brief confirms `WORKOS_API_KEY=sk_test_default` but not a client ID.
- [ ] Confirm the exact method name for user-scoped API key issuance/revocation (assumed `issueApiKey()`/`revokeApiKey()` on the user model via `HasApiKeys`) against Phase 8's shipped trait.
- [ ] Confirm the vault filesystem driver's exact config shape (assumed `['driver' => 'vault', 'wraps' => 'local']`) against Phase 9's shipped `Storage::extend()` registration.
- [ ] Decide whether `laravel/mcp` needs a workbench-only dev-dependency pin (per the contract's Connect & MCP phase notes) for the `ConnectMcpDemoController`'s `/.well-known/oauth-protected-resource` link to be meaningfully demoable, or whether linking to the package's own route is sufficient without it.
- [ ] Run the actual human quickstart trial and fill in `docs/release-checklist.md`'s log table before tagging a release — not implementation work for this spec, but a hard release blocker.

---

_This spec is ready for implementation. Follow the patterns and validate at each step — and reconcile every row in the Standalone-Implementability table against real Phase 1–12 code before wiring the corresponding call site._
