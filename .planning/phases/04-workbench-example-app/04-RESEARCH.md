# Phase 4: Workbench Example App - Research

**Researched:** 2026-04-06
**Domain:** Laravel workbench app, Livewire 4, Flux Pro, WorkOS::fake(), RBAC middleware
**Confidence:** HIGH

## Summary

The workbench is substantially built. Models, Livewire components, views, routes, controllers, and test files all exist. This is a polish-and-complete phase, not a build-from-scratch phase.

The two mechanical compliance gaps are simple: the PHP constraint in `workbench/composer.json` is `^8.2` but WORK-07 requires `^8.3`, and `/auth.json` is already gitignored in `workbench/.gitignore` but not in the root `.gitignore`.

The substantive gaps are: no RBAC middleware on routes (D-09), no `WorkOS::fake()` usage in existing feature tests (they still call `$this->actingAs($user, 'workos')` directly rather than the fake), and the `ExampleTest.php` is a stub that needs to become the RBAC test or be removed.

**Primary recommendation:** Fix the two mechanical gaps first (WORK-06 root gitignore, WORK-07 PHP constraint), then wire RBAC middleware to routes, then convert existing tests to use `WorkOS::fake()` / `actingAs()` patterns shown in `WorkOSFakeExampleTest.php`.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Keep all 6 intents in AdminPortalLinks (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification, Certificate Renewal). Exceeds the 5-intent roadmap requirement — no reason to remove working code.
- **D-02:** Admin Portal links render in organization settings page. This is the natural home since features are per-org.
- **D-03:** Primary audit UI is the Audit Logs Admin Portal intent (already one of the 6 intents). WorkOS hosts the real audit log viewer — no need to build a custom one.
- **D-04:** Optionally add a local Livewire audit log viewer component that shows recent events. This is a nice-to-have, not blocking.
- **D-05:** All user actions trigger audit log entries: Todo CRUD, login, logout, org switch, settings view, admin portal access. Comprehensive logging to demonstrate the full audit capability.
- **D-06:** Key flows tested with WorkOS::fake()/actingAs(): login redirect, todo CRUD (create, complete, delete), org switching, permission checks. Remove any tests that hit real APIs.
- **D-07:** WorkOSFakeExampleTest stays as standalone reference file for package consumers learning how to test. Separate from app-specific tests.
- **D-08:** Showcase quality — loading states, transitions, empty states, responsive design. Higher demo value since this is the primary way developers evaluate the package.
- **D-09:** RBAC demonstrated with CheckRole/CheckPermission middleware on routes. Admins can delete any todo, members can only delete their own. Demonstrates real-world authorization patterns.

### Claude's Discretion
- Exact Flux Pro component choices and layout details
- Loading state implementation (Livewire wire:loading vs skeleton screens)
- Empty state messaging and illustrations
- Which specific routes get CheckRole vs CheckPermission middleware
- Audit event naming conventions (e.g., "todo.created" vs "todo.create")

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| WORK-01 | Todo app with create, complete, and delete functionality | TodoList + TodoItem Livewire components exist and are functional; addTodo, toggle, confirmDelete/delete are all wired |
| WORK-02 | Organization switching with separate todo lists per org | OrganizationSwitcher component exists; todos scoped by org_id; existing test covers this |
| WORK-03 | All Admin Portal intents (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification) | AdminPortalLinks has all 6 intents, view is complete — nothing to add |
| WORK-04 | Audit log shows user actions | audit() calls exist in TodoList and TodoItem; AuditMiddleware registered as `workos.audit`; missing from login/logout/org-switch flows |
| WORK-05 | Basic Pest feature tests for key flows | Test files exist but use direct actingAs not WorkOS::fake(); need conversion per D-06 |
| WORK-06 | auth.json excluded from git (credential protection) | workbench/.gitignore has /auth.json; root .gitignore does NOT — needs root gitignore entry |
| WORK-07 | workbench/composer.json PHP constraint aligned to ^8.3 | Currently `^8.2`; needs one-line change |
</phase_requirements>

## Current State Inventory

### What Exists and Is Complete (No Changes Needed)

| Asset | Status | Notes |
|-------|--------|-------|
| `workbench/app/Models/Todo.php` | Complete | Auditable contract, HasAuditTrail, org-scoped |
| `workbench/app/Models/User.php` | Complete | HasWorkOSId, HasWorkOSPermissions traits |
| `workbench/app/Models/Organization.php` | Complete | HasWorkOSId, todo relationship |
| `workbench/app/Livewire/AdminPortalLinks.php` | Complete | All 6 intents, portal link generation |
| `workbench/app/Livewire/OrganizationSwitcher.php` | Complete | Switch, auto-select, dispatches OrganizationSwitched event |
| `workbench/app/Livewire/TodoList.php` | Complete | Create, filter, counts, audit call |
| `workbench/app/Livewire/TodoItem.php` | Complete | Toggle, confirm/delete, audit calls |
| `workbench/resources/views/livewire/todo-list.blade.php` | Complete | Loading states, empty states, filters |
| `workbench/resources/views/livewire/todo-item.blade.php` | Complete | Checkbox, delete confirmation modal |
| `workbench/resources/views/livewire/admin-portal-links.blade.php` | Complete | Grid layout, icons, disabled state for null links |
| `workbench/resources/views/organizations/settings.blade.php` | Complete | Org info, admin portal, members table |
| `workbench/resources/views/dashboard.blade.php` | Complete | Stats, quick actions |
| `workbench/resources/views/components/layouts/app.blade.php` | Complete | Sidebar, impersonation banner, org switcher |
| Database migrations | Complete | users, organizations, organization_memberships, todos |
| Factories | Complete | User, Organization, Todo |

### What Needs Work

| Asset | Gap | Requirement |
|-------|-----|-------------|
| `workbench/composer.json` | PHP constraint is `^8.2` | WORK-07 |
| Root `.gitignore` | No entry for `workbench/auth.json` or `auth.json` | WORK-06 |
| `workbench/routes/web.php` | No RBAC middleware on any route | D-09 |
| `workbench/tests/Feature/AuthTest.php` | `authenticated user can logout` uses direct `actingAs` not fake | D-06 |
| `workbench/tests/Feature/TodoTest.php` | All tests use direct `actingAs` not WorkOS::fake() | D-06 |
| `workbench/tests/Feature/OrganizationTest.php` | All tests use direct `actingAs` not WorkOS::fake() | D-06 |
| `workbench/tests/Feature/ExampleTest.php` | Stub test in `Tests\Feature` namespace (wrong), not Pest-style | D-06 |
| Audit logging | login/logout/org-switch flows have no `WorkOS::audit()` calls | D-05 |

## Architecture Patterns

### Registered Middleware Aliases (from WorkOSServiceProvider)
[VERIFIED: src/WorkOSServiceProvider.php:185-192]

| Alias | Class | Use Case |
|-------|-------|---------|
| `auth:workos` | `EnsureWorkOSAuthenticated` | Require authentication |
| `workos.role` | `CheckRole` | Require one of N roles |
| `workos.permission` | `CheckPermission` | Require all listed permissions |
| `workos.audit` | `AuditMiddleware` | Auto-log route access |
| `workos.organization.current` | `SetCurrentOrganization` | Set `current_organization` on request |
| `workos.impersonation` | `DetectImpersonation` | Detect admin impersonation |

### RBAC Middleware Usage Pattern
[VERIFIED: src/Http/Middleware/CheckRole.php, CheckPermission.php]

```php
// Route-level: require any of these roles
Route::delete('/todos/{todo}', ...)->middleware('workos.role:admin,moderator');

// Route-level: require this permission
Route::post('/todos', ...)->middleware('workos.permission:todos.write');

// Group-level
Route::middleware(['auth:workos', 'workos.organization.current'])->group(function () {
    // admin-only sub-group
    Route::middleware('workos.role:admin')->group(function () {
        // ...
    });
});
```

CheckRole throws `MissingRoleException` if the user lacks the role. CheckPermission throws `MissingPermissionException`. Both extend the base exception class — they are NOT silent redirects.

### AuditMiddleware Application Pattern
[VERIFIED: src/Audit/AuditMiddleware.php]

The middleware infers action names from HTTP method + route name:
- GET → `{route.name}.read`
- POST → `{route.name}.create`
- DELETE → `{route.name}.delete`

Apply to route groups or individual routes:
```php
// On a route group
Route::middleware(['auth:workos', 'workos.audit'])->group(function () { ... });

// With explicit action name override
Route::get('/todos', ...)->middleware('workos.audit:todos.viewed');
```

For Livewire component actions (addTodo, toggle, delete), `WorkOS::audit()` is called directly inside the component method — the middleware is for page-level navigation audit events (settings viewed, dashboard accessed, org switched).

### WorkOS::fake() Test Pattern
[VERIFIED: workbench/tests/Feature/WorkOSFakeExampleTest.php, src/Testing/WorkOSFake.php]

Two patterns available:

**Pattern 1: Direct fake with explicit restore**
```php
test('user can create a todo', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake()->actingAs($user);

    session(['current_organization_id' => $org->id]);

    Livewire::test(TodoList::class)
        ->set('newTodo', 'My new task')
        ->call('addTodo');

    $this->assertDatabaseHas('todos', ['title' => 'My new task']);
    $fake->assertAudited('todo.created');
})->afterEach(fn () => WorkOS::restore());
```

**Pattern 2: InteractsWithWorkOS trait (auto-teardown)**
```php
uses(InteractsWithWorkOS::class);

test('admin can delete any todo', function () {
    $admin = User::factory()->create();
    $this->actingAsWorkOS($admin, roles: ['admin']);

    // test route with workos.role:admin middleware
    $this->delete("/todos/{$todo->id}")->assertOk();
});
```

Key detail: `WorkOS::actingAs($user)` (static shortcut on WorkOS facade) vs `$fake->actingAs($user)` (on the fake instance). The static shortcut calls `WorkOS::fake()` internally if no fake is active. [VERIFIED: existing test patterns in AuthTest.php show `WorkOS::actingAs($user)` works directly]

### RBAC Demonstration Pattern (D-09)
[VERIFIED: CONTEXT.md D-09 + middleware source]

The decision: "Admins can delete any todo, members can only delete their own."

This requires a route-based check, not just a Livewire component check. Options:
1. Add a DELETE route for todos protected with `workos.role:admin` — admins delete via route; members delete via Livewire component with user-scoped authorization check
2. Handle entirely in TodoItem component: check `auth()->user()->hasWorkOSRole('admin')` to decide if delete is allowed

Option 1 is more authentic for demonstrating middleware — creates a second code path that exercises CheckRole. Option 2 is simpler but only exercises blade directives, not the `workos.role` alias. The planner should implement Option 1 to satisfy D-09's "RBAC demonstrated with CheckRole/CheckPermission middleware on routes."

### Audit Event Gaps (D-05)
[VERIFIED: reading source files]

Currently audited (in Livewire components):
- `todo.created` — in TodoList::addTodo()
- `todo.completed` / `todo.uncompleted` — in TodoItem::toggle()
- `todo.deleted` — in TodoItem::delete()

Not yet audited:
- Login — `AuthController::callback()` handles login; needs `WorkOS::audit('user.login', ...)`
- Logout — `AuthController::logout()` handles logout; needs `WorkOS::audit('user.logout', ...)`
- Org switch — `OrganizationSwitcher::switch()` already dispatches `OrganizationSwitched` event; could add direct audit call or listen to that event
- Settings viewed — `OrganizationController::settings()` — add `workos.audit` middleware or explicit call
- Dashboard accessed — `DashboardController::index()` — same

Check what `AuthController` looks like: [VERIFIED: package routes load from `src/routes/web.php` → `src/Http/Controllers/AuthController.php`]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Role checks in middleware | Custom middleware | `workos.role:admin` alias | Already registered in ServiceProvider |
| Permission checks in middleware | Custom middleware | `workos.permission:todos.write` alias | Same |
| Audit event recording | Custom logger | `WorkOS::audit()` facade method | Goes to WorkOS API via AuditLogger |
| Test authentication | `$this->actingAs($user, 'workos')` | `WorkOS::fake()->actingAs($user)` | Fake isolates from real API, enables audit assertions |
| Auth state in Blade | Custom session checks | `@workosRole('admin')`, `@workosPermission('todos.write')` | Blade directives already registered |

## Common Pitfalls

### Pitfall 1: actingAs Guard Mismatch
**What goes wrong:** Tests use `$this->actingAs($user, 'workos')` which works for HTTP routing but bypasses the WorkOS session layer. `WorkOS::audit()` calls inside Livewire components won't be captured for assertion.
**Why it happens:** `actingAs` with the guard name authenticates at the Laravel level but doesn't activate WorkOSFake.
**How to avoid:** Use `WorkOS::fake()->actingAs($user)` or `WorkOS::actingAs($user)` instead. Then call `$fake->assertAudited('todo.created')` after the component action.
**Warning signs:** `assertAudited()` always fails even when the code clearly calls `WorkOS::audit()`.

### Pitfall 2: Livewire Test Auth vs HTTP Test Auth
**What goes wrong:** `Livewire::actingAs($user, 'workos')` and `$this->actingAs($user, 'workos')` behave differently inside Livewire component tests. The guard name must match the configured guard.
**How to avoid:** After activating `WorkOS::fake()`, both `Livewire::test()` and `$this->get()` will resolve auth via the fake. No need to pass guard name to Livewire::actingAs separately.

### Pitfall 3: ExampleTest.php Namespace
**What goes wrong:** `ExampleTest.php` uses `namespace Tests\Feature` (PHPUnit class style) but all other tests are Pest-style closures without namespace.
**How to avoid:** Delete or replace ExampleTest.php. The WORK-05 tests should be Pest-style like the other feature tests.

### Pitfall 4: PHP Constraint Change Won't Auto-Update
**What goes wrong:** Changing `^8.2` to `^8.3` in composer.json doesn't update the lockfile automatically.
**How to avoid:** Run `composer update` inside `workbench/` after the change, or instruct the developer to do so. The lockfile is not committed per `.gitignore`.

### Pitfall 5: Root .gitignore vs workbench/.gitignore
**What goes wrong:** `workbench/.gitignore` has `/auth.json` (the Flux Pro Composer credential file). But git evaluates gitignore relative to the file's location — `/auth.json` in `workbench/.gitignore` covers `workbench/auth.json`. The ROOT `.gitignore` does not have an entry, so if `auth.json` is accidentally placed in the repo root it won't be ignored.
**How to avoid:** Add `workbench/auth.json` to root `.gitignore` as a belt-and-suspenders measure. WORK-06 can be satisfied by confirming `workbench/.gitignore` coverage, but adding root entry is defensively correct.

### Pitfall 6: AuditMiddleware on GET Routes
**What goes wrong:** Applying `workos.audit` to all routes including the todo list GET creates noise in the audit log (every page load = audit event).
**How to avoid:** Apply `workos.audit` selectively to settings and admin portal routes, not to the todo index. For demo purposes, auditing the settings page and dashboard is sufficient for page-level events; component actions handle the rest.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest PHP ^4.0 |
| Config file | `workbench/tests/Pest.php` |
| Quick run command | `cd workbench && php artisan test --filter=TodoTest` |
| Full suite command | `cd workbench && php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| WORK-01 | Todo create/complete/delete | unit (Livewire) | `php artisan test --filter=TodoTest` | ✅ (needs fake conversion) |
| WORK-02 | Todos scoped per org | integration | `php artisan test --filter=TodoTest` | ✅ (needs fake conversion) |
| WORK-03 | Admin Portal intents render | smoke (view) | `php artisan test --filter=OrganizationTest` | ✅ |
| WORK-04 | User actions appear in audit log | unit (assertion) | `php artisan test --filter=TodoTest` | ❌ needs assertAudited calls |
| WORK-05 | Pest feature tests for key flows | feature | `php artisan test` | ✅ (needs fake conversion) |
| WORK-06 | auth.json not tracked | manual/git | `git check-ignore workbench/auth.json` | ✅ workbench; ❌ root |
| WORK-07 | PHP constraint ^8.3 | manual/verify | `cat workbench/composer.json | grep php` | ❌ currently ^8.2 |

### Wave 0 Gaps
- [ ] No new test files needed — existing files need conversion and new assertions
- [ ] `workbench/tests/Feature/ExampleTest.php` — delete or replace with RBAC test

## Sources

### Primary (HIGH confidence)
- `workbench/app/Livewire/TodoList.php` — confirmed audit calls, filter logic
- `workbench/app/Livewire/TodoItem.php` — confirmed toggle/delete audit calls
- `workbench/app/Livewire/AdminPortalLinks.php` — confirmed 6 intents
- `workbench/routes/web.php` — confirmed no RBAC middleware present
- `workbench/composer.json` — confirmed PHP constraint is `^8.2`
- `workbench/.gitignore` — confirmed `/auth.json` is present
- Root `.gitignore` — confirmed no `auth.json` entry
- `src/WorkOSServiceProvider.php` — confirmed middleware alias registrations
- `src/Http/Middleware/CheckRole.php` — confirmed signature and behavior
- `src/Http/Middleware/CheckPermission.php` — confirmed signature and behavior
- `src/Audit/AuditMiddleware.php` — confirmed action inference and target extraction
- `src/Testing/WorkOSFake.php` — confirmed fake API surface
- `workbench/tests/Feature/WorkOSFakeExampleTest.php` — confirmed test patterns

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `WorkOS::actingAs($user)` (static facade shortcut) auto-activates the fake if none is active | Architecture Patterns | Tests that call static shortcut may not capture audit events if fake must be explicitly activated first. Verify against WorkOS.php facade source before implementing. |
| A2 | AuthController::callback() and logout() are in `src/Http/Controllers/AuthController.php` — they need audit calls added | Current State Inventory | If login/logout audit calls should go in the controller, the plan needs a task targeting that file |

## Open Questions

1. **Login/logout audit calls — package source or workbench override?**
   - What we know: Auth flow routes are registered by the package ServiceProvider pointing to `src/Http/Controllers/AuthController.php`. Workbench doesn't override these.
   - What's unclear: D-05 says "login, logout" should be audited. The workbench can't easily override package controllers without publishing them.
   - Recommendation: Apply `workos.audit` middleware to the `auth:workos` routes group in `workbench/routes/web.php` — this covers dashboard/todos/settings page accesses as navigation audit events. For login/logout specifically, apply `workos.audit` middleware to the package route group via config, or accept that they are covered by the WorkOS SDK's own audit log for auth events.

2. **RBAC route structure for D-09**
   - What we know: D-09 says "Admins can delete any todo, members can only delete their own." The middleware is `workos.role:admin`.
   - What's unclear: There's currently no explicit DELETE todo route — deletion is entirely Livewire-based via `TodoItem::delete()`.
   - Recommendation: Add a `DELETE /todos/{todo}` route protected by `workos.role:admin` for direct admin deletion, AND keep Livewire delete for members (with authorization check that the todo belongs to the authenticated user). This creates the route-level RBAC demonstration D-09 requires.

## Metadata

**Confidence breakdown:**
- Current state audit: HIGH — read every relevant file
- Required changes: HIGH — gaps identified from source comparison
- Test patterns: HIGH — WorkOSFakeExampleTest is a complete verified reference
- RBAC middleware: HIGH — source read and verified
- Audit middleware: HIGH — source read and verified

**Research date:** 2026-04-06
**Valid until:** 2026-06-06 (stable stack, no external API dependency in this phase)
