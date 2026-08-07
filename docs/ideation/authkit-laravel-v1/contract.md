# AuthKit Laravel v1 Contract

**Created**: 2026-08-06
**Readiness**: All 5 gates ready
**Status**: Approved
**Approval**: Express — single consolidated confirmation, no per-artifact review
**Supersedes**: None

## Problem Statement

Laravel developers adopting WorkOS get laravel/workos: roughly 400 lines of form-request helpers with no service provider, no routes, no config file, and no coverage of Organizations, RBAC, FGA, Audit Logs, Admin Portal, Feature Flags, Vault, Connect, or the Events API. It is tightly coupled to the official starter kit and is not independently installable. Every team that wants real WorkOS depth hand-rolls SDK plumbing per application.

The cost lands twice. Laravel teams either leave WorkOS platform features unused, or they reach for ecosystem substitutes — spatie/laravel-permission for authorization, other Pennant drivers for flags, Passport for API auth — which entrenches non-WorkOS paths inside WorkOS customers' own apps. Nick (WorkOS) wants a Fortify-style headless plumbing package that makes the WorkOS-native path the paved one for every feature area, and will build a starter kit on top of it as a separate later project.

## Goals

1. Time-to-integrated: on a fresh Laravel app, composer require + php artisan authkit:install + env keys yields working AuthKit login with organizations and RBAC live, in ≤10 minutes and ≤5 quickstart steps.
2. Laravel-native maximalism (governs the shape and depth of areas the scope table admits, not scope membership): every in-scope WorkOS area is surfaced through the Laravel extension vocabulary — guards, middleware, form requests, traits, casts, route macros, Gate/Blade directives, generators, Pennant driver, filesystem driver — with every mechanism mapping to a real WorkOS capability; consumer app code never references WorkOS SDK classes directly (verified by grep over the workbench example app).
3. Quality bar (governs everything in scope): composer test green across the full CI matrix (PHP 8.3–8.5 × Laravel 12/13 × prefer-lowest/prefer-stable × ubuntu/windows), PHPStan clean, 100% type coverage, and an emulate- or fake-backed Pest feature suite per in-scope area.
4. WorkOS stays canonical (governs everything in scope, arch-test enforced): local state is limited to declared projections (user link, org model, org domains, memberships) plus sync bookkeeping (events cursor), each projection with an events-driven refresh path; no other WorkOS state is duplicated locally.

## Success Criteria

- [ ] Fresh-app acceptance flow passes against emulate: login → local user linked (workos_id stored, external_id set in WorkOS) → org auto-created via trait → $user->can() honors JWT permission claims — check: `vendor/bin/pest --filter=Acceptance — exits 0 (suite runs the workbench app against workos/emulate)`
- [ ] Full validation suite green (static analysis, format check, 100% type coverage, unit + feature tests) — check: `composer test — exits 0`
- [ ] CI matrix green on the release commit across PHP 8.3–8.5 × Laravel 12/13 × both stability lanes × ubuntu/windows — check: `gh run watch --exit-status <run-id for tests.yml on release commit> — all matrix jobs pass`
- [ ] Consumer code needs no direct SDK usage: the workbench example app exercises every scope area through package APIs only — neither use-imports nor fully-qualified WorkOS\ references — check: `grep -rE '(use |\\)WorkOS\\' workbench/ — exits 1 (zero matches)`
- [ ] Every scope area has a dedicated Pest feature suite — emulate-backed where covered (auth, users, orgs, RBAC/FGA checks, events, webhooks, portal links) and MockHandler-backed where not (Vault, audit export, user API keys, flags, Connect/MCP, Pipes) — check: `ls tests/Feature/*Test.php | wc -l — ≥16 (one suite per scope row); composer test:unit — exits 0`
- [ ] Events sidecar durability: worker killed and restarted mid-stream resumes from the persisted cursor with no missed and no duplicate Laravel event dispatches — check: `vendor/bin/pest --filter=EventsWorkerResume — exits 0 (test drives emulate-seeded event stream)`
- [ ] Sealed-session guard rejects tampered, expired, wrong-issuer, and wrong-audience tokens (iss/aud checks added on top of the SDK, which skips them; canonical values confirmed by the Phase 1 token audit) — check: `vendor/bin/pest --filter=SessionSecurity — exits 0 (forged/expired/wrong-claim JWT cases)`
- [ ] authkit:install is idempotent: running it twice produces no duplicate config entries, routes, or migrations — check: `vendor/bin/pest --filter=InstallIdempotent — exits 0`
- [ ] Package boots with config:cache enabled and src/ contains no runtime env() reads — credentials come from config only — check: `vendor/bin/pest --filter=ConfigCache — exits 0; grep -rn 'env(' src/ --include='*.php' — exits 1`
- [ ] Projection boundary holds: local WorkOS-shaped state is limited to the declared projections (user link columns, org model columns, org domains, memberships) plus the events cursor — any additional WorkOS-shaped table or model fails the arch test — check: `vendor/bin/pest --filter=ProjectionBoundary — exits 0 (arch test with explicit whitelist)`
- [ ] Vault usable core works end-to-end: Vaulted cast round-trips a model attribute, the vault filesystem driver round-trips a file on a wrapped disk, and the KV facade CRUDs a secret — envelope-encryption asserted against MockHandler fakes (emulate has zero Vault coverage) — check: `vendor/bin/pest --filter=Vault — exits 0`
- [ ] Feature flags resolve in both contexts: from JWT claims inside an authenticated HTTP request, and via the WorkOS API fallback in a queued job / console context with no session — check: `vendor/bin/pest --filter=FeatureFlags — exits 0`
- [ ] Idiom coverage: each promised Laravel mechanism exists and is registered — workos guard driver, middleware aliases, form requests, Vaulted cast, route macro, Gate/Blade directives, generator commands, Pennant driver, vault filesystem driver — check: `vendor/bin/pest --filter=IdiomCoverage — exits 0 (arch/glob assertions per mechanism)`
- [ ] Quickstart doc contains at most 5 numbered top-level steps — check: `grep -cE '^[0-9]+\.' docs/quickstart.md — ≤5`
- [ ] A recorded human trial at release reproduces the quickstart end-to-end on a fresh Laravel app in ≤10 minutes with orgs + RBAC live; the trial result (who, date, elapsed time) is logged in the release notes — judgment call

## Scope Boundaries

### In Scope

- Install & config: authkit:install publishes config/migrations, appends env keys, registers routes + guard; idempotent — The composer require → integrated app story starts here
- Auth core: login/logout/callback routes as thin wrappers delegating to public form-request classes (one implementation, two entry points — Fortify's pattern); workos guard wrapping SDK SessionManager (sealed cookie canonical, adds iss/aud validation); refresh middleware; impersonation surfaced via act claim — Sealed-session doctrine; form requests keep custom-controller apps first-class
- Users: workos_id migration + user trait; first-login link sets external_id in WorkOS; projection refreshed by events pipeline — Every Laravel user connected to a WorkOS user, WorkOS canonical
- Organizations: HasWorkosOrganization trait (observer auto-creates org, workos_id ↔ external_id); domains projection; current-org from claims; org-switch route via AuthKit re-auth; tenant middleware — Org-per-model automation is a headline feature; full org context is table stakes for B2B
- RBAC: Gate::before reads permissions/roles claims — $user->can(), @can, policies work with zero HTTP; role/permission management via facade — Zero-HTTP authorization doctrine from JWT claims
- FGA: explicit resource checks via direct Check API calls (no cache — a stale cache is a stale permission decision) + Gate integration for resource-model policies; HasWorkosResource trait syncs models as FGA resources — The escalation path beyond role-shaped authz; caching is opt-in Full-tier with events-driven invalidation
- Events sidecar: authkit:work cursor-persisted Events API poller; typed Laravel events for the types feeding declared projections + audit/domain-verification, and a generic WorkosEvent fallback for all other types (no typed mapping for out-of-scope products); php artisan dev integration; make:workos-listener generator — Primary sync transport keeping all projections fresh, bounded to declared scope
- Webhooks: route registrar + signature-verify middleware; same Laravel event objects as the sidecar — One listener story across both transports
- Audit Logs: HasAuditLogs trait (lifecycle actions like post.created, metadata method, per-action opt-in via attribute/method); auto actor/org context; manual AuditLog facade; export + retention passthrough — Dead-simple audit logging is a stated headline feature
- Feature Flags: first-party laravel/pennant driver — JWT feature_flags claim inside authenticated requests, WorkOS API fallback for queued jobs / console (Pennant checks must work everywhere Laravel runs them) — Pennant is Laravel's paved path for flags; both contexts carry success criteria
- Admin Portal: portal-link facade covering all 7 intents (sso, dsync, audit_logs, log_streams, domain_verification, certificate_renewal, bring_your_own_key); domain-verification events update the domains projection — Full-intent portal support is a stated requirement
- Vault: Vaulted Eloquent cast (attribute envelope encryption); vault filesystem driver wrapping any disk with BYOK data-key encryption; Vault facade for KV — Attribute + file + KV are the three storage shapes the brain dump explicitly requested; each carries a success criterion
- API Keys: authkit-key guard driver validating via the validate endpoint and loading key permissions into Gate; issue/revoke on user and org models — In the founder's literal MVP list; user and org API keys rolled into the same authorization system
- Connect + MCP: facade for OAuth/M2M application registry; MCP bearer-token middleware (aud = resource indicator, JWKS-verified) + /.well-known/oauth-protected-resource route + laravel/mcp integration recipe (laravel/mcp pinned as workbench dev dependency at implementation time) — In the founder's literal MVP list; MCP auth composes from Connect + JWKS since no SDK helper exists
- Pipes: $user->connectedAccounts(), access-token fetch with auto-refresh, org provider-config passthrough — In the founder's literal MVP list; provider config stays in the WorkOS dashboard
- emulate DX: auto-point base URL in local/testing when configured; publishable seed file; php artisan dev process; CI boots emulate via npx @workos/emulate (pinned ^0.6) with /health readiness gate and seeded workos-emulate.config.yaml on ubuntu + windows runners — Local dev and CI truth bar depend on it; mechanism named so the CI recipe has a real target
- Invitations flows surfaced as facade/form-request helpers — Depth extension — starter kit will want it, not needed to make WorkOS-native the paved path
- JWT template + CORS origin management passthroughs — Dashboard-adjacent management APIs, rarely touched at runtime
- Groups API surface (org groups CRUD, group role assignments) — Depth beyond usable-core RBAC
- FGA resource-graph conveniences (parent hierarchies via nested traits, resource discovery helpers) — Beyond usable-core FGA checks
- Opt-in FGA check caching with events-driven invalidation (role/membership/resource events bust the cache) — Moved from MVP by the over-engineering review: no latency requirement stated, and stale cache = stale permission decision without invalidation wiring
- State API reconciliation command (full-state diff to catch missed deletions)
- Upstream SDK contribution: iss/aud verification in SessionManager
- Session-claim macro/typed DTO layer for custom JWT template claims

### Out of Scope

- UI, views, or starter-kit scaffolding — This is Fortify-style headless plumbing; the starter kit is a separate later project consuming this package
- Widgets (including widget token minting) — Widgets are UI surface — the starter kit owns UI (stakeholder ruling resolving the scope-creep review)
- Radar, MFA enrollment APIs, Magic Auth internals, legacy Passwordless — AuthKit's hosted UI owns these flows; nothing for a plumbing package to add
- Dedicated Directory Sync provisioning module — WorkOS-managed directory provisioning means apps may need no dsync handling at all; events-pipeline listener recipes cover custom mapping
- Standalone (non-AuthKit) WorkOS usage — Standalone users should use workos-php directly; this package assumes AuthKit
- Laravel <12, PHP <8.3, Pest 5 migration — Support only officially supported versions; Pest 5 requires PHP 8.4+ which would drop supported PHP 8.3 users

### Future Considerations

- Starter kit built on this package (views, UI, Volt/Inertia variants) — includes widget token minting + Blade/Livewire widget wrappers
- Deeper dsync provisioning module if demand appears
- Pest 5 + PHP 8.4 floor when PHP 8.3 leaves support (Dec 2027)

## Decisions Considered and Rejected

- **RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API** — rejected: Sync WorkOS roles/permissions into local spatie-style tables. Claims already ride the access token so checks are free; local tables duplicate canonical WorkOS state and drift
- **Breadth-complete v1: all 16 scope areas ship in the first version at usable-core depth; phases are build order, not releases** — rejected: Release-tiered rollout (v0.1 auth core, features in v0.2+). Dual basis recorded per scope-creep review: ecosystem-substitution logic covers RBAC (spatie), Feature Flags (other Pennant drivers), and API auth (Passport); the remaining areas are MVP by explicit stakeholder decision (Nick: 'literally all of the features I listed are our MVP')
- **Custom workos guard with the AuthKit sealed session cookie as canonical auth state; app's Laravel session stays free for app state** — rejected: Exchange code then hydrate Laravel's standard session guard (laravel/workos approach). WorkOS must remain the session source of truth for both authn and authz; the SDK's SessionManager already does unseal/refresh/JWKS heavy lifting
- **Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage (Vault 0%, audit export, user-scoped API keys, flags verb-mismatch, Connect/MCP, Pipes)** — rejected: SDK fakes only. Wire fidelity where possible; emulate v0.6.0 covers ~62% of endpoints and the SDK's base-URL override plus injectable Guzzle handler make both paths clean
- **Local Eloquent rows are declared projections (user, org, domains, memberships) with workos_id ↔ external_id linking, refreshed by the events pipeline** — rejected: No local state / read-through API calls per request. Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events (confirmed by Nick)
- **Feature Flags ship as a first-party laravel/pennant driver (claim-first, API fallback)** — rejected: Standalone AuthkitFeature facade. Pennant is Laravel's paved path — Feature::active(), @feature, and middleware come free, matching the Laravel-native doctrine
- **Directory Sync: prefer WorkOS-managed directory provisioning (no app-side dsync handling); ship events-pipeline listener recipes for custom group/attribute mapping; no dedicated module** — rejected: Full dsync provisioning module mapping directory users/groups to Eloquent models. WorkOS can provision directory users natively so most apps need zero dsync code (Nick's correction); recipes cover the rest without a subsystem
- **Full org context in v1: claims-resolved current org, org-switch route via AuthKit re-auth, tenant middleware** — rejected: Read-only org context, apps build their own switcher. Multi-org ergonomics are table stakes for the Team/Workspace apps the org trait targets
- **Stay on Pest 4 with PHP ^8.3 floor** — rejected: Pest 5 (requires PHP 8.4+). PHP 8.3 is officially supported until Dec 2027 and Laravel 13 supports it; dropping it violates the support-matrix requirement. Revisit at 8.3 EOL. Paratest friction on PHP 8.5 handled by non-parallel runs where needed
- **Credentials read from config only; env is never read outside config files** — rejected: Runtime env() reads like the SDK's own fallback does. php artisan config:cache empties env at runtime (laravel/framework#55028 class of bug); config-only is the Laravel paved path
- **Events API sidecar is the primary sync transport; webhooks are optional low-latency triggers sharing the same Laravel event objects** — rejected: Webhooks-primary sync. WorkOS docs recommend the Events API as primary (durable cursor, backfill/reconcile); shared event objects mean one listener story across transports
- **Auth flows exposed both as registered routes and as form-request helpers, with routes as thin wrappers delegating to the form requests** — rejected: Routes-only surface. Apps with custom controllers keep every nicety — parity with the one thing laravel/workos got right (Nick: provide as much Laravel nicety as WorkOS supports); one implementation, two entry points
- **Wire the Events worker and emulate into php artisan dev** — rejected: composer dev script only. php artisan dev is a first-class command in the latest Laravel (Nick's correction of Claude's stale knowledge)
- **Widgets are excluded from v1 entirely — no token-minting facade** — rejected: Widget token minting in MVP (original table row), or demoting it to Full tier. Nick's ruling on the scope-creep blocker: widgets are UI surface and the starter kit owns UI; token minting lands with the starter kit work
- **Phase 1 ends with an empirical AuthKit token audit: decode a real AuthKit-issued token to confirm canonical iss/aud values and default presence of role/permissions/feature_flags claims, recorded in the decision log before Phase 2 starts** — rejected: Assume the SDK's TODO values and default-populated claims. Hidden-dependency blocker: SessionManager's own source defers iss/aud as unconfirmed, and the zero-HTTP RBAC + claim-first flags + quickstart goals all silently depend on claims being present without dashboard setup
- **API Keys Guard and Connect & MCP phases depend on Organizations & Org Context** — rejected: Original prereq graph (auth-core only). Hidden-dependency blockers: ApiKeys::createOrganizationApiKey and Connect::createM2MApplication both take a required organizationId at the SDK-signature level
- **FGA ships without caching — direct Check API per check; opt-in caching with events-driven invalidation is Full tier** — rejected: Default per-check cache in MVP. Over-engineering review: no stated latency requirement, and a stale cache entry is a stale permission decision; caching only earns its keep with invalidation wiring, which is Full-tier work
- **Typed sidecar events are bounded to types feeding the declared projections + audit/domain-verification; everything else dispatches a generic WorkosEvent** — rejected: A typed Laravel event class per WorkOS event type. Over-engineering review: unbounded typed mapping would cover out-of-scope products (Radar, MFA, Passwordless); the generic fallback keeps every type listenable without owning the full catalog
- **Quickstart criterion split into a mechanical ≤5-step doc check plus a recorded human timing trial logged in release notes; projection-boundary arch test added** — rejected: Single judgment-only quickstart criterion; canonical-state goal with no verifying criterion. Success-criteria critic blockers: the ≤5-step half is mechanically checkable so leaving it blank was omission, and the WorkOS-canonical promise was unfalsifiable without an enforcing arch test
- **v1 targets the Full tier: MVP's 16 areas plus the 5 depth extensions (invitations, JWT templates + CORS, groups API, FGA resource-graph conveniences, opt-in FGA cache), folded in as a dedicated Depth Extensions phase** — rejected: MVP-only v1 with depth extensions deferred post-starter-kit. Stakeholder tier selection at contract approval — Nick chose Full over the recommended MVP cut
- **Express run executes directly on main (no isolation branch); recovery anchor recorded: git reset --hard 4d04d0b** — rejected: ideation/authkit-laravel-v1 isolation branch (express default). Stakeholder choice, re-confirmed after correcting the premise (repo does exist on GitHub, local main 3 commits ahead unpushed): nothing auto-pushes, and reset-based recovery is acceptable

## Execution Plan

_Added during Phase 5 handoff. Pick up this contract cold and know exactly how to execute._

### Dependency Graph

```
Foundation & Client Binding
  ├── Auth Core & Sealed Sessions  (blocked by Foundation & Client Binding)
        ├── Organizations & Org Context  (blocked by Auth Core & Sealed Sessions)
              ├── Events Pipeline & Webhooks  (blocked by Organizations & Org Context)
                    ├── Audit Logs & Admin Portal  (blocked by Events Pipeline & Webhooks)
                    └── Integration, Quickstart & Release Readiness  (blocked by Events Pipeline & Webhooks, Authorization (RBAC + FGA), Audit Logs & Admin Portal, Feature Flags (Pennant Driver), API Keys Guard, Vault, Connect & MCP Auth, Pipes, Depth Extensions (Full Tier))
              └── Pipes  (blocked by Organizations & Org Context)
        ├── Authorization (RBAC + FGA)  (blocked by Auth Core & Sealed Sessions)
              ├── API Keys Guard  (blocked by Authorization (RBAC + FGA), Organizations & Org Context)
              └── Depth Extensions (Full Tier)  (blocked by Authorization (RBAC + FGA), Organizations & Org Context, Events Pipeline & Webhooks)
        ├── Feature Flags (Pennant Driver)  (blocked by Auth Core & Sealed Sessions)
        └── Connect & MCP Auth  (blocked by Auth Core & Sealed Sessions, Organizations & Org Context)
  └── Vault  (blocked by Foundation & Client Binding)
```

### Execution Steps

**Run the project** (recommended) — autopilot reads this contract, plans dependency waves, runs independent phases in parallel, and gates on failure:

```bash
/ideation:autopilot docs/ideation/authkit-laravel-v1/contract.md
```

**Or run phases manually** in dependency order:

**Strategy**: Hybrid

1. **Phase 1** — Foundation & Client Binding _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-1.md
   ```

2. **Phase 2** — Auth Core & Sealed Sessions _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-2.md
   ```

3. **Phase 3** — Organizations & Org Context _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-3.md
   ```

4. **Phase 4** — Events Pipeline & Webhooks _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-4.md
   ```

5. **Phase 5** — Authorization (RBAC + FGA) _(blocked by Auth Core & Sealed Sessions)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-5.md
   ```

6. **Phase 6** — Audit Logs & Admin Portal _(blocked by Events Pipeline & Webhooks)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-6.md
   ```

7. **Phase 7** — Feature Flags (Pennant Driver) _(blocked by Auth Core & Sealed Sessions)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-7.md
   ```

8. **Phase 8** — API Keys Guard _(blocked by Authorization (RBAC + FGA), Organizations & Org Context)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-8.md
   ```

9. **Phase 9** — Vault _(blocked by Foundation & Client Binding)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-9.md
   ```

10. **Phase 10** — Connect & MCP Auth _(blocked by Auth Core & Sealed Sessions, Organizations & Org Context)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-10.md
   ```

11. **Phase 11** — Pipes _(blocked by Organizations & Org Context)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-11.md
   ```

12. **Phase 12** — Depth Extensions (Full Tier) _(blocked by Authorization (RBAC + FGA), Organizations & Org Context, Events Pipeline & Webhooks)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-12.md
   ```

13. **Phase 13** — Integration, Quickstart & Release Readiness _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/authkit-laravel-v1/spec-phase-13.md
   ```

### Agent Team Prompt

```
Execute the authkit-laravel v1 phases with a lead + teammates over the dependency graph (specs in docs/ideation/authkit-laravel-v1/). Sequential core chain first: Phase 1 (Foundation) → Phase 2 (Auth Core) → Phase 3 (Organizations) → Phase 4 (Events Pipeline). Parallel group A after Phase 2: Phase 5 (Authorization), Phase 7 (Feature Flags), Phase 9 (Vault, needs only Phase 1). Parallel group B after Phases 3+4: Phase 6 (Audit+Portal, needs 4), Phase 8 (API Keys, needs 5+3), Phase 10 (Connect+MCP, needs 2+3), Phase 11 (Pipes, needs 3). Then Phase 12 (Depth Extensions, needs 5+3+4), then Phase 13 (Integration) last. COORDINATION: nearly every phase modifies src/AuthkitServiceProvider.php and config/authkit.php — only one teammate may modify those shared files at a time; sequence provider/config edits through the lead. More than 5 phases are parallelizable in the middle — start with the highest-priority batch (5, 6, 7) before (8, 9, 10, 11). Every phase must end with composer test green before its commit.
```

---

_This contract was generated from brain dump input. Review and approve before proceeding to specification._
