# FGA Integration Contract

**Created**: 2026-04-08
**Confidence Score**: 96/100
**Status**: Approved
**Supersedes**: None

## Problem Statement

The authkit-laravel package currently supports only organization-level RBAC: roles and permissions are read from JWT access tokens and checked locally via middleware, traits, and blade directives. This is fast but flat — it answers "can this user manage billing?" but not "can this user edit *this* project?"

WorkOS has shipped Fine-Grained Authorization (FGA), which adds hierarchical, resource-scoped access control: resources, role assignments on resources, permission checks against resource hierarchies, and resource discovery queries. None of this is integrated into authkit-laravel.

Laravel developers using this package who need resource-scoped authorization (workspaces, projects, repositories, dashboards) must currently bypass the package entirely and make raw API calls. This defeats the purpose of a drop-in integration.

## Goals

1. **Expose the full FGA API surface** through `WorkOS::authorization()` — resources (CRUD + external ID variants), role assignments, access checks, and resource discovery — mirroring the Node SDK's `Authorization` class shape so the integration is ready for the PHP SDK to drop in when it ships.
2. **Provide a model trait (`SyncsWithFGA`)** that auto-creates/updates/deletes FGA resources when Eloquent models are saved or deleted, with a configurable opt-out (`$autoSyncFGA = false`), plus explicit `syncToFGA()` / `removeFromFGA()` methods for manual control.
3. **Add resource-scoped middleware** (`workos.check:{resource_type},{permission}`) that resolves resources from route model binding and delegates to the authorization service, alongside service/trait methods (`$user->canOnResource('edit', $project)`) for programmatic checks.
4. **Extend blade directives** (`@canOnResource` / `@endcanOnResource`) for resource-scoped permission checks in templates.
5. **Extend testing helpers** so `WorkOS::actingAs()` accepts resource-scoped permissions, enabling developers to test FGA flows without hitting the API.
6. **Add a workbench demo section** showing resource hierarchy, assignments, and resource-scoped checks in action.

## Success Criteria

- [ ] `WorkOS::authorization()` returns an `Authorization` service with full CRUD for resources (by ID and external ID), check, assignRole, removeRole, listRoleAssignments, listResourcesForMembership, listMembershipsForResource
- [ ] All authorization methods call `/authorization/*` endpoints via `WorkOS\Client::request()` (raw API), designed so the implementation can be swapped to PHP SDK methods when PR #351 ships
- [ ] `SyncsWithFGA` trait auto-creates FGA resources on Eloquent `created` event with configurable `$resourceTypeSlug`, `$fgaExternalId`, and parent resolution
- [ ] `SyncsWithFGA` auto-updates FGA resource name on `updated` event and auto-deletes on `deleted` event
- [ ] `$autoSyncFGA = false` disables observer-based sync; `syncToFGA()` and `removeFromFGA()` remain available
- [ ] `CheckResourcePermission` middleware accepts `workos.check:{resource_type},{permission}` and resolves the resource from route model binding
- [ ] `$user->canOnResource('edit', $project)` method on the `HasWorkOSPermissions` trait makes an API check
- [ ] `@canOnResource('permission', $resource)` / `@endcanOnResource` blade directives work
- [ ] `WorkOS::actingAs($user, resourcePermissions: [...])` stubs resource-scoped checks in tests
- [ ] `config/workos.php` has `features.fga` toggle (default false) to gate FGA functionality
- [ ] Workbench app has a demo page exercising resource CRUD, assignment, and checks
- [ ] PHPStan level 8 passes, Pest tests pass, Pint formatting passes
- [ ] All new service methods have corresponding unit tests with mocked HTTP responses

## Scope Boundaries

### In Scope

- `Authorization` service class wrapping all `/authorization/*` FGA endpoints (resources, assignments, checks, discovery)
- Passthrough of existing RBAC methods (roles, permissions) through the same `authorization()` accessor, eventually replacing direct `RBAC` usage
- `SyncsWithFGA` Eloquent model trait with configurable auto-sync via model observers
- `CheckResourcePermission` middleware with route model binding integration
- `canOnResource()` method on `HasWorkOSPermissions` trait
- `@canOnResource` / `@endcanOnResource` blade directives
- Extended `WorkOS::actingAs()` and `WorkOSFake` for FGA test scenarios
- `features.fga` config toggle
- Workbench demo page for FGA
- Unit tests for all new code

### Out of Scope

- **Resource type management** — resource types are Dashboard-only per WorkOS design, no API needed
- **Batch resource writes** — the Node SDK has `batchWriteResources` but the docs don't surface it prominently; defer until demand is clear
- **Warrant/legacy FGA endpoints** (`/fga/v1/*`) — these are deprecated in favor of `/authorization/*`
- **Real-time event streaming for FGA changes** — use existing webhook infrastructure if needed
- **Policy engine / rule builder UI** — out of scope for a PHP package

### Future Considerations

- Swap raw `WorkOS\Client` calls for native PHP SDK methods when PR #351 (or equivalent) ships
- Laravel Gate/Policy integration (`Gate::define('edit-project', ...)` backed by FGA checks)
- Caching layer for frequently-checked resource permissions (TTL-based, invalidated by webhooks)
- Artisan command for bulk-syncing existing Eloquent models to FGA resources
- FGA-aware Livewire widget for managing resource assignments (similar to existing UserManagement widget)

## Execution Plan

_Added during Phase 5 handoff. Pick up this contract cold and know exactly how to execute._

### Dependency Graph

```
Phase 1: Authorization Service Layer (blocking)
  ├── Phase 2: Model Trait & Eloquent Integration (blocked by 1)
  └── Phase 3: Middleware, Trait Methods & Blade (blocked by 1)
              └── Phase 4: Testing Helpers (blocked by 1 & 3)
                        └── Phase 5: Workbench Demo (blocked by all)
```

### Execution Steps

**Strategy**: Hybrid (Phase 1 sequential, then 2 & 3 parallel, then 4 & 5 sequential)

1. **Phase 1** — Authorization Service Layer _(blocking)_
   ```bash
   /execute-spec docs/ideation/fga-integration/spec-phase-1.md
   ```

2. **Phases 2 & 3** — parallel after Phase 1
   See agent team prompt below, or run sequentially:
   ```bash
   /execute-spec docs/ideation/fga-integration/spec-phase-2.md
   /execute-spec docs/ideation/fga-integration/spec-phase-3.md
   ```

3. **Phase 4** — Testing Helpers _(blocked by Phase 3)_
   ```bash
   /execute-spec docs/ideation/fga-integration/spec-phase-4.md
   ```

4. **Phase 5** — Workbench Demo _(blocked by all)_
   ```bash
   /execute-spec docs/ideation/fga-integration/spec-phase-5.md
   ```

### Agent Team Prompt

For step 2 (Phases 2 & 3 in parallel):

```
Execute two specs in parallel for the authkit-laravel FGA integration.

Teammate 1 — Model Trait (Phase 2):
  Spec: docs/ideation/fga-integration/spec-phase-2.md
  Creates SyncsWithFGA trait and FGAResourceObserver.
  Depends on Phase 1 (Authorization service must exist in src/Authorization/).

Teammate 2 — Middleware & Blade (Phase 3):
  Spec: docs/ideation/fga-integration/spec-phase-3.md
  Creates CheckResourcePermission middleware, MembershipResolver, canOnResource() trait method, and blade directives.
  Depends on Phase 1 (Authorization service must exist in src/Authorization/).

Coordinate on shared files (src/WorkOSServiceProvider.php, src/Models/Concerns/HasWorkOSPermissions.php) to avoid merge conflicts — only one teammate should modify a shared file at a time. Phase 3 modifies both; Phase 2 only modifies WorkOSServiceProvider.php. Have Teammate 1 finish WorkOSServiceProvider.php changes first, then Teammate 2 edits it.

After both complete: run composer test && composer analyse && composer format to verify integration.
```

---

_This contract was generated from brain dump input. Review and approve before proceeding to specification._
