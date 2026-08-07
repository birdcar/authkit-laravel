# Spec Template: WorkOS Feature Area (authkit-laravel)

_Shared template for the feature-area phases (5–12). Each `spec-phase-{n}.md` delta references this template and fills in the phase-specific inputs. Phases 1–4 and 13 have full standalone specs and do not use this template._

**How to consume (execute-spec):** read this template fully, then the phase delta. The delta's sections override or fill this template's placeholders. Conventions here are canonical — deviations must be named in the delta's "Deviations" section.

---

## Shared Technical Approach

Every feature area wraps one or more `workos/workos-php` v9.1 SDK services in Laravel-native surface, following the same pattern:

1. **SDK access** goes through the package's `WorkosClientManager` (bound in Phase 1) — never instantiate `WorkOS\WorkOS` directly in feature code. The manager owns config-driven construction (API key, client ID, base URL for emulate, injectable Guzzle handler for tests).
2. **Laravel mechanism first**: expose behavior through the mechanism the delta names (trait, guard, cast, facade method, middleware, generator, driver). The mechanism must map to a real WorkOS capability — no speculative abstraction.
3. **WorkOS stays canonical**: no new local WorkOS-shaped state beyond the declared projections (user link, org model, org domains, memberships) + events cursor. If a feature seems to need a new table, it is wrong — re-read the contract's projection-boundary decision.
4. **Registration** happens in `AuthkitServiceProvider` (config merge already exists; add bindings/macros/directives/commands in the appropriate `register()`/`boot()` sections, console-only where applicable).
5. **Consumer never touches the SDK**: any workbench example added for this area must compile without `use WorkOS\...` or `\WorkOS\` references.

## Shared Conventions (canonical)

| Concern | Convention |
|---|---|
| Namespace | `Authkit\Authkit\` (skeleton namespace retained; rename is a pre-release Open Item owned by Phase 1) |
| Config | `config/authkit.php`, read via `config('authkit.*')` only — `env()` never appears in `src/` |
| Publish tags | `authkit-config`, `authkit-migrations`, `authkit-routes` (if applicable) |
| Facades | `Authkit` (primary manager), `Vault`, `AuditLog` only; other areas hang off `Authkit` accessors (e.g. `Authkit::connect()`, `Authkit::pipes()`, `Authkit::portalLink()`) |
| Guards | `workos` (sealed session), `authkit-key` (API keys) |
| Middleware aliases | `authkit.session` (refresh), `authkit.org` (tenant context), `authkit.mcp` (MCP bearer) |
| Route names | `authkit.login`, `authkit.logout`, `authkit.callback`, `authkit.switch-org` |
| Traits | `HasWorkosUser` (User), `HasWorkosOrganization` (org model), `HasWorkosResource` (FGA), `HasAuditLogs`, `HasApiKeys` |
| Events | Typed under `Authkit\Authkit\Events\Workos\*` (bounded set); `GenericWorkosEvent` fallback carries `type` + payload |
| Env var names (config file only) | `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_REDIRECT_URI`, `WORKOS_COOKIE_PASSWORD`, `WORKOS_BASE_URL` |
| Migrations | Generated with `php artisan make:migration` conventions; anonymous class style; publishable |
| Code style | `declare(strict_types=1);` everywhere (arch test enforces); Pint default preset; PHPStan (larastan) clean; 100% type coverage |
| Tests | Pest 4, `tests/Feature/{Area}Test.php` per area + unit tests as needed; feature tests boot Testbench with the package provider |

## Test-Path Selection

- **emulate-backed** (default when covered): boot `workos/emulate` (helper from Phase 1: `EmulateServer::start()` / seeded via `workos-emulate.config.yaml`), point base URL at it. Covered areas: auth flows, users, orgs, memberships, RBAC/authorization checks (partial — no group endpoints), events, webhooks, invitations, portal-link mint, feature-flag reads (verb mismatch — verify before relying), API-key org endpoints.
- **MockHandler-backed** (when emulate lacks the API): inject `GuzzleHttp\Handler\MockHandler` through `WorkosClientManager`'s handler hook (Phase 1). Required for: Vault (zero emulate coverage), audit-log export, user-scoped API keys, Connect/MCP, Pipes, JWT templates/CORS.
- Never mix paths within one test; name the path in the test file's top comment.

## Shared Feedback Strategy

**Inner-loop command**: `vendor/bin/pest --filter={AreaSuite}` (seconds; scoped to the area's suite)
**Playground**: Pest feature suite against emulate or MockHandler (per test-path above); `composer serve` (Testbench workbench app) for route/middleware areas.
**Why**: every area is API-wrapping logic — the tightest loop is a scoped feature suite with a fake or emulated wire.

## Delta Must Fill (per phase)

1. **Phase header**: phase number/title, estimated effort (S/M/L/XL), prereq phases.
2. **Scope rows implemented** (verbatim from contract) + any Full-tier items.
3. **Decisions Considered and Rejected** — carry contract decisions relevant to the phase (all of them when unclear). Never omit.
4. **Components**: for each — Laravel mechanism, SDK methods wrapped (exact names), key design (interfaces/signatures in PHP), implementation steps, feedback loop (playground/experiment/check) for iterative components, skip for trivial ones.
5. **File changes**: exhaustive New/Modified tables (paths under `src/`, `config/`, `database/`, `routes/`, `tests/`, `workbench/`).
6. **Service provider registration diff**: what gets added to register()/boot().
7. **Testing requirements**: suite file(s), key cases incl. edge/error cases, test path (emulate/MockHandler), any seed data.
8. **Failure modes table**: named failures per non-trivial component (data shadows, WorkOS-down paths, stale claims, concurrency).
9. **Deviations** from this template (if none: "None").
10. **Validation commands** (standard block below plus area filter).

## Standard Validation Commands

```bash
composer analyse          # PHPStan (larastan)
composer lint:check       # Pint check-only
composer test:types       # Pest type coverage --min=100
vendor/bin/pest --filter={AreaSuite}   # area suite
composer test             # full chain — must be green before commit
```

## Shared Failure-Mode Prompts (address in every delta)

- WorkOS API unreachable / 5xx mid-operation (SDK retries 429/5xx with backoff — what happens after retries exhaust?)
- Stale JWT claims between refreshes (bounded staleness doctrine — acknowledge, don't engineer around)
- emulate drift vs production behavior (e.g. refresh tokens ALWAYS rotate in emulate — stricter than prod)
- Config missing/empty (fail fast with actionable exception naming the config key, not a WorkOS 401 deep in a request)
- Idempotency: any operation a retry or double-dispatch could run twice

## Rollout

No feature flags for the package itself; every phase lands green on `composer test` and is releasable. Rollback = `git revert` of the phase commit (or reset to the recorded anchor).
