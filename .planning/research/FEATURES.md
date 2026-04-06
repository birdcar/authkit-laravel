# Feature Landscape

**Domain:** Laravel authentication package (WorkOS AuthKit integration)
**Researched:** 2026-04-06

## Reference: What Mature Auth Packages Provide

| Package | Scope | Testing Helpers | Install UX | Example App |
|---------|-------|-----------------|------------|-------------|
| Sanctum | API/SPA token auth | `Sanctum::actingAs($user, ['abilities'])` | `php artisan install:api` | No |
| Fortify | Backend-only auth logic | None (application-level testing) | `php artisan fortify:install` | No |
| Breeze | Scaffolding (publishes controllers) | Via standard `actingAs()` | `php artisan breeze:install` | Published to app |
| Jetstream | Full-stack scaffolding + teams | Via standard `actingAs()` | `php artisan jetstream:install` | Published to app |
| laravel/workos | Thin starter-kit bridge | None (no test helpers) | None (env vars only) | No |

---

## Table Stakes

Features users expect. Missing = package feels incomplete or untrustworthy.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| OAuth login/callback/logout | Core AuthKit flow | Low | Already implemented |
| Laravel auth guard integration | Required for `auth()`, `Auth::user()` | Medium | Already implemented |
| Cookie-based session w/ auto-refresh | WorkOS sealed sessions are the standard | Medium | Already implemented |
| Route protection middleware | `EnsureWorkOSAuthenticated` pattern | Low | Already implemented |
| Role/permission middleware + Blade directives | Every enterprise app needs RBAC checks | Low | Already implemented |
| Webhook verification + event dispatch | Required for user/org sync | Medium | Already implemented |
| User model traits (HasWorkOSId, HasOrganization) | Without these, every user has to wire sync manually | Low | Already implemented |
| Publishable config + migrations | Laravel package convention | Low | Already implemented |
| Boot-time config validation | Fail fast with clear error > silent 500 at runtime | Low | Already implemented |
| `workos:install` artisan command | Standard package onboarding UX | Medium | Exists; Smart Install is the active milestone |
| PHPStan + Pest CI coverage | Signals package quality and stability | Low | Exists; CI workflow is the active milestone |
| Comprehensive README | Users abandon packages without docs | Low | Active milestone |

---

## Differentiators

Features that set this package apart. Not universally expected, but high value.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Smart Install: conflict detection | Detects laravel/workos, Breeze, Jetstream, Fortify and adapts — eliminates "I broke my app" installs | High | Active milestone; no other auth package does this |
| `WorkOS::fake()` + `WorkOS::actingAs()` | Sanctum offers this; WorkOS does not. Drops the #1 friction point for testing AuthKit apps | Medium | Active milestone |
| `assertAudited()` / `assertNotAudited()` test assertions | Makes audit logging testable the same way Laravel tests queued jobs | Low | Active milestone; unique to this package |
| Inertia `ShareWorkOSData` middleware | Pre-built Inertia integration for auth, org, impersonation props — saves boilerplate for every Inertia+WorkOS project | Low | Active milestone |
| Organization management routes (switch, invitations, revoke) | laravel/workos has none; this provides full org lifecycle | Medium | Already implemented |
| Impersonation detection + blade directive | Enterprise admin feature with no equivalent in laravel/workos | Low | Already implemented |
| AuditLogger + AuditMiddleware | WorkOS Audit Logs API wired into Laravel middleware — enterprise compliance in one line | Medium | Already implemented |
| `workos:sync-users` bulk backfill command | Handles the "I installed WorkOS on an existing app" migration path | Low | Already implemented |
| Config migration from `services.php` (laravel/workos) | Lets teams upgrading from the official package keep working without manual edits | Medium | Active milestone |
| Full workbench example app | Demonstrates every feature in a running Todo app — rare for auth packages | High | Active milestone |
| Feature tests in workbench example | Shows how to test a WorkOS-authenticated app — answers the "how do I test this?" question | Medium | Active milestone |

---

## Anti-Features

Features to explicitly NOT build.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| UI components / auth views | Package is headless; WorkOS hosts auth UI externally. Building views duplicates WorkOS's work and creates maintenance burden | Document the callback/redirect pattern; workbench example shows integration |
| Vue/React/Svelte component library | Frontend framework is app author's choice; tying to one creates fragmentation | Provide Inertia middleware; let app authors build their own components |
| Custom SSO configuration UI | Configuration happens in WorkOS Dashboard; duplicating it adds surface area with no value | Link to WorkOS Dashboard docs |
| MFA / Passwordless flows | AuthKit handles these via hosted UI; they're not backend concerns | Document that AuthKit inherits these from WorkOS |
| Directory Sync webhook handling | WorkOS recommends using the Events API for SCIM; implementing here duplicates concerns | Document the Events API approach in README |
| Multi-tenancy database separation | Database-per-tenant is an application architecture concern, not an auth package concern | Provide HasOrganization trait; let app authors build tenancy on top |
| GraphQL integration | REST-only; GraphQL auth is application-specific middleware, not package scope | `WorkOS::fake()` enables testing GraphQL mutations the same way |
| Deployment configuration | Infrastructure is app author's responsibility | README note on cookie security, HTTPS requirement |
| Rate limiting logic | Laravel's `ThrottleRequests` and `RateLimiter` handle this better than package-level reimplementation | Document using Laravel's built-in rate limiting on `/auth/callback` |
| Password management | AuthKit is passwordless/SSO; no passwords to manage | N/A |

---

## Feature Dependencies

```
Smart Install (conflict detection)
  └── Config migration assistant (requires laravel/workos detection to be useful)

WorkOS::fake()
  └── WorkOS::actingAs() (actingAs is the primary consumer of fake())
  └── assertAudited() / assertNotAudited() (requires fake() to intercept audit calls)

ShareWorkOSData middleware
  └── Inertia installed in host app (soft dependency; middleware no-ops or errors clearly if Inertia absent)

workbench example app feature tests
  └── WorkOS::fake() (tests in example require fake() to avoid real API calls)

workbench example app (all Admin Portal intents)
  └── workbench app exists and runs
  └── WorkOS account configured in workbench .env
```

---

## MVP Recommendation

The package is already feature-complete for core auth functionality. The active milestone is focused on developer experience and ecosystem maturity. Priority order for the active milestone:

1. **`WorkOS::fake()` and `WorkOS::actingAs()`** — Unblocks all testing scenarios; without it, teams can't write tests against WorkOS-authenticated routes. This is the most common complaint about the official `laravel/workos` package (no test helpers).
2. **Smart Install conflict detection** — Reduces the "I broke my app" support burden; most new installs will be on existing Laravel apps where Breeze or the official package is already present.
3. **CI/CD GitHub Actions** — Table stakes for package credibility; PRs without test runs signal poor maintenance.
4. **`assertAudited()` / `assertNotAudited()`** — High-value for enterprise adopters using the audit feature; builds on `WorkOS::fake()`.
5. **`ShareWorkOSData` Inertia middleware** — Low complexity; most WorkOS+Laravel projects use Inertia (workbench example app does); prevents every team from reinventing the same middleware.
6. **workbench example app** — Demonstrates the full feature set in a running app; doubles as the integration test harness.
7. **Comprehensive README** — Required before any public announcement; currently the package's weakest point vs laravel/workos which has official Laravel docs.

Defer:
- **Config migration from `services.php`** — Useful but narrow audience (teams upgrading from official package); can ship after core DX features.

---

## Sources

- [Laravel Starter Kits (WorkOS section)](https://laravel.com/docs/12.x/starter-kits) — HIGH confidence, official docs
- [laravel/workos GitHub](https://github.com/laravel/workos) — MEDIUM confidence (README sparse, no testing helpers confirmed)
- [Laravel Sanctum Testing](https://laravel.com/docs/12.x/sanctum) — HIGH confidence, official docs
- [Creating Installer Commands for Laravel Packages (Freek.dev)](https://freek.dev/2333-creating-installer-commands-for-laravel-packages) — MEDIUM confidence
- [Inertia.js Shared Data](https://inertiajs.com/shared-data) — HIGH confidence, official docs
- [Pragmatically testing multi-guard auth (Freek.dev)](https://freek.dev/1567-pragmatically-testing-multi-guard-authentication-in-laravel) — MEDIUM confidence
