# WorkOS Platform Parity Contract

**Created**: 2026-04-11
**Confidence Score**: 95/100
**Status**: Draft
**Supersedes**: None

## Problem Statement

AuthKit Laravel provides strong authentication, organization management, RBAC, webhooks/Events API, audit logs, and Livewire widgets. But it has significant gaps compared to the full WorkOS platform and the official authkit-nextjs SDK.

The authkit-nextjs SDK extracts `feature_flags`, `entitlements`, and custom claims from the JWT session token — authkit-laravel ignores these fields entirely. WorkOS offers entire product lines (FGA, Feature Flags, Vault, Pipes, Radar) that have zero integration in authkit-laravel. Directory Sync events arrive but have no typed event classes or default listeners. There's no API key validation helper despite B2B SaaS apps routinely needing customer-facing API authentication.

A Laravel developer building a B2B SaaS app on WorkOS should be able to use every WorkOS product through Laravel-native patterns — Gates for FGA, Blade directives for feature flags, middleware for access checks, Eloquent-friendly helpers for Vault. Currently they have to drop down to the raw WorkOS PHP SDK for most of these, losing the "it feels like Laravel" experience.

## Goals

1. **Extract all JWT claims** — feature flags, entitlements, and custom claims from the session token, accessible via `WorkOSSession` and helper methods, matching authkit-nextjs parity
2. **Integrate FGA with Laravel's authorization system** — Gate/Policy support backed by WorkOS FGA access checks, plus middleware and Blade directives for resource-level permissions
3. **Integrate Feature Flags with Laravel patterns** — middleware, Blade directives, and helper methods that check WorkOS feature flags from the session token and the Feature Flags API
4. **Add API Key validation** — middleware and helper for validating WorkOS-issued API keys on customer-facing API routes
5. **Add Vault helpers** — Laravel-friendly encrypt/decrypt/store interface backed by WorkOS Vault
6. **Add typed Directory Sync events** — event classes for all `dsync.*` event types with default listeners for syncing directory users/groups to local models
7. **Add Pipes integration** — helpers for initiating and managing third-party OAuth connections via WorkOS Pipes
8. **Add Radar helpers** — middleware and service for bot/fraud attempt reporting via WorkOS Radar
9. **Add Domain Verification helpers** — service methods for managing organization domain verification
10. **Support `screenHint` and `loginHint`** on auth URL generation, matching authkit-nextjs

## Success Criteria

### Session / Token Parity
- [ ] `WorkOSSession` exposes `featureFlags()`, `entitlements()`, and `customClaims()` from the decoded JWT
- [ ] `WorkOS::hasFeatureFlag(string)` and `WorkOS::hasEntitlement(string)` work like `hasRole()`/`hasPermission()`
- [ ] `HasWorkOSPermissions` trait gains `hasFeatureFlag()` and `hasEntitlement()` methods
- [ ] Blade directives `@workosFeature('flag')` and `@workosEntitlement('plan')` exist

### FGA
- [ ] `WorkOS::fga()` returns a service wrapping the WorkOS FGA API (resources, role assignments, access checks)
- [ ] `FGAServiceProvider` or integration in `WorkOSServiceProvider` registers a Laravel Gate that delegates to FGA access checks
- [ ] `workos.fga` middleware gates routes by resource-level permissions
- [ ] Blade directive `@workosAccess('permission', $resource)` for template-level checks
- [ ] FGA access check results are cacheable per-request to avoid redundant API calls

### Feature Flags
- [ ] `WorkOS::flags()` returns a service wrapping the WorkOS Feature Flags API
- [ ] `workos.feature` middleware gates routes by feature flag (`workos.feature:new-dashboard`)
- [ ] `@workosFeature('flag')` Blade directive (same as session-level, but also works via API for server-side checks)
- [ ] Feature flags from JWT are preferred (no API call); API fallback for flags not in the token

### API Key Validation
- [ ] `WorkOS::validateApiKey(string)` wraps the WorkOS API key validation endpoint
- [ ] `workos.apikey` middleware validates `Authorization: Bearer` header against WorkOS and injects the org context
- [ ] Works with Laravel's `auth:api` guard pattern

### Vault
- [ ] `WorkOS::vault()` returns a service wrapping WorkOS Vault CRUD + encrypt/decrypt
- [ ] Helper methods: `store(name, data)`, `get(name)`, `update(name, data)`, `delete(name)`, `encrypt(plaintext)`, `decrypt(ciphertext)`
- [ ] Integrates with Laravel's encryption contract as an optional driver

### Directory Sync Events
- [ ] Typed event classes for all 10 `dsync.*` event types (`WorkOSDsyncUserCreated`, `WorkOSDsyncGroupUpdated`, etc.)
- [ ] Each event has typed accessors matching the WorkOS event payload
- [ ] Default listeners that sync directory users to a configurable local model
- [ ] EVENT_MAP updated to include all dsync event types
- [ ] `workos:make-listener` command includes dsync events in its selection

### Pipes
- [ ] `WorkOS::pipes()` returns a service for managing connected accounts
- [ ] Helper to generate authorization URLs for third-party connections
- [ ] Typed events for pipe-related webhooks

### Radar
- [ ] `WorkOS::radar()` wraps the Radar attempts API
- [ ] Optional middleware that reports request attempts to Radar

### Domain Verification
- [ ] `WorkOS::domains()` wraps organization domain CRUD and verification
- [ ] Typed events for `organization_domain.*` webhook events

### Auth Flow Enhancements
- [ ] `loginUrl()` accepts `screenHint` (`'sign-up'` | `'sign-in'`) and `loginHint` (email) parameters
- [ ] `WorkOS::signUpUrl()` convenience method (calls `loginUrl` with `screenHint: 'sign-up'`)

### Cross-Cutting
- [ ] PHPStan level 8 passes on all new code
- [ ] All existing tests continue to pass
- [ ] New features have unit and feature tests
- [ ] Workbench app exercises each new feature

## Scope Boundaries

### In Scope

- Session token claim extraction (feature flags, entitlements, custom claims)
- FGA service, Gate integration, middleware, Blade directive
- Feature Flags service, middleware, Blade directive (JWT-first, API fallback)
- API key validation middleware and helper
- Vault service with Laravel-friendly API
- Typed directory sync events and default listeners
- Pipes service helpers
- Radar service and optional middleware
- Domain verification helpers
- Auth flow enhancements (screenHint, loginHint, signUpUrl)
- Tests for every new feature
- Workbench demonstrations

### Out of Scope

- WorkOS Dashboard UI features (managed in WorkOS directly)
- WorkOS Connect / OAuth application management (server-to-server config)
- Observability / Datadog streaming (infrastructure, not SDK)
- Custom JWT template configuration (done via WorkOS Dashboard)
- CORS origin management (done via WorkOS Dashboard)
- Webhook endpoint management (done via WorkOS Dashboard)
- Changes to the WorkOS PHP SDK itself

### Future Considerations

- Laravel Pennant driver backed by WorkOS Feature Flags
- FGA relationship-based authorization (beyond role assignments — full Warrant/Zanzibar model)
- Vault as a Laravel cache/session driver
- Pipes as a Laravel Socialite driver
- Real-time feature flag updates via Server-Sent Events or polling

## Execution Plan

### Dependency Graph

```
Phase 1: Session Token Parity ──────────┐
  │                                      │
  ├── Phase 2: Auth Flow Enhancements    │ (independent)
  ├── Phase 3: API Key Validation        │ (independent)
  ├── Phase 4: Feature Flags ────────────┤ (blocked by Phase 1)
  ├── Phase 5: FGA                       │ (independent)
  ├── Phase 6: Directory Sync Events     │ (independent)
  ├── Phase 7: Vault                     │ (independent)
  ├── Phase 8: Radar                     │ (independent)
  ├── Phase 9: Pipes                     │ (independent)
  └── Phase 10: Domain Verification      │ (independent)
```

### Execution Steps

**Strategy**: Hybrid — Phase 1 sequential (blocker), then agent team for independent phases.

1. **Phase 1** — Session Token Parity _(blocking)_
   ```bash
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-1.md
   ```

2. **Phases 2, 3, 5, 6** — First parallel batch after Phase 1
   ```bash
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-2.md
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-3.md
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-5.md
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-6.md
   ```

3. **Phase 4** — Feature Flags _(blocked by Phase 1, uses session flag data)_
   ```bash
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-4.md
   ```

4. **Phases 7, 8, 9, 10** — Second parallel batch (service wrappers)
   ```bash
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-7.md
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-8.md
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-9.md
   /execute-spec docs/ideation/workos-platform-parity/spec-phase-10.md
   ```

### Agent Team Prompt

```
You are coordinating the WorkOS Platform Parity project for authkit-laravel.

Phase 1 (Session Token Parity) is complete. Execute the remaining phases
in parallel where possible.

**Batch 1 (parallel — no shared files between these):**
- Teammate 1: /execute-spec docs/ideation/workos-platform-parity/spec-phase-2.md
- Teammate 2: /execute-spec docs/ideation/workos-platform-parity/spec-phase-3.md
- Teammate 3: /execute-spec docs/ideation/workos-platform-parity/spec-phase-5.md
- Teammate 4: /execute-spec docs/ideation/workos-platform-parity/spec-phase-6.md

**After Batch 1 completes:**
- Teammate 5: /execute-spec docs/ideation/workos-platform-parity/spec-phase-4.md

**Batch 2 (parallel — service wrappers, no shared files):**
- Teammate 6: /execute-spec docs/ideation/workos-platform-parity/spec-phase-7.md
- Teammate 7: /execute-spec docs/ideation/workos-platform-parity/spec-phase-8.md
- Teammate 8: /execute-spec docs/ideation/workos-platform-parity/spec-phase-9.md
- Teammate 9: /execute-spec docs/ideation/workos-platform-parity/spec-phase-10.md

Coordinate on shared files (src/WorkOS.php, src/Facades/WorkOS.php,
src/WorkOSServiceProvider.php, config/workos.php) — only one teammate
should modify a shared file at a time. Sequential phases that touch
these files should run after parallel phases complete their unique files.

Each teammate runs composer analyse && composer test after their phase.
```
