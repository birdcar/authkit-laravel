# Phase 4: Workbench Example App - Context

**Gathered:** 2026-04-07
**Status:** Ready for planning

<domain>
## Phase Boundary

Developers evaluating the package can run a complete, working Laravel 12 app that demonstrates every package feature with a realistic test suite. The workbench already has substantial structure (Models, Livewire components, routes, views, controllers, tests). This phase completes and polishes what exists.

</domain>

<decisions>
## Implementation Decisions

### Admin Portal Intents
- **D-01:** Keep all 6 intents in AdminPortalLinks (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification, Certificate Renewal). Exceeds the 5-intent roadmap requirement — no reason to remove working code.
- **D-02:** Admin Portal links render in organization settings page. This is the natural home since features are per-org.

### Audit Log Visibility
- **D-03:** Primary audit UI is the Audit Logs Admin Portal intent (already one of the 6 intents). WorkOS hosts the real audit log viewer — no need to build a custom one.
- **D-04:** Optionally add a local Livewire audit log viewer component that shows recent events. This is a nice-to-have, not blocking.
- **D-05:** All user actions trigger audit log entries: Todo CRUD, login, logout, org switch, settings view, admin portal access. Comprehensive logging to demonstrate the full audit capability.

### Test Suite
- **D-06:** Key flows tested with WorkOS::fake()/actingAs(): login redirect, todo CRUD (create, complete, delete), org switching, permission checks. Remove any tests that hit real APIs.
- **D-07:** WorkOSFakeExampleTest stays as standalone reference file for package consumers learning how to test. Separate from app-specific tests.

### Todo App Quality
- **D-08:** Showcase quality — loading states, transitions, empty states, responsive design. Higher demo value since this is the primary way developers evaluate the package.
- **D-09:** RBAC demonstrated with CheckRole/CheckPermission middleware on routes. Admins can delete any todo, members can only delete their own. Demonstrates real-world authorization patterns.

### Claude's Discretion
- Exact Flux Pro component choices and layout details
- Loading state implementation (Livewire wire:loading vs skeleton screens)
- Empty state messaging and illustrations
- Which specific routes get CheckRole vs CheckPermission middleware
- Audit event naming conventions (e.g., "todo.created" vs "todo.create")

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Existing Workbench Code
- `workbench/app/Models/Todo.php` — Todo model (existing)
- `workbench/app/Models/User.php` — User model with WorkOS traits (existing)
- `workbench/app/Models/Organization.php` — Organization model (existing)
- `workbench/app/Livewire/TodoList.php` — Todo list component (existing)
- `workbench/app/Livewire/TodoItem.php` — Todo item component (existing)
- `workbench/app/Livewire/AdminPortalLinks.php` — Admin portal with 6 intents (existing, complete)
- `workbench/app/Livewire/OrganizationSwitcher.php` — Org switcher component (existing)
- `workbench/routes/web.php` — Route definitions (existing)

### Existing Tests
- `workbench/tests/Feature/AuthTest.php` — Auth flow tests
- `workbench/tests/Feature/TodoTest.php` — Todo CRUD tests
- `workbench/tests/Feature/OrganizationTest.php` — Org switching tests
- `workbench/tests/Feature/WorkOSFakeExampleTest.php` — Fake usage examples (standalone reference)
- `workbench/tests/Pest.php` — Test configuration

### Package Features to Demonstrate
- `src/Testing/WorkOSFake.php` — Fake implementation for tests (Phase 2)
- `src/Audit/AuditLogger.php` — Audit logging to WorkOS API
- `src/Audit/AuditMiddleware.php` — Automatic route logging
- `src/Http/Middleware/CheckRole.php` — Role-based route protection
- `src/Http/Middleware/CheckPermission.php` — Permission-based route protection
- `src/Http/Middleware/ShareWorkOSData.php` — Inertia shared props (Phase 1)

### Views
- `workbench/resources/views/todos/index.blade.php` — Todo list page
- `workbench/resources/views/organizations/settings.blade.php` — Org settings with admin portal
- `workbench/resources/views/dashboard.blade.php` — Dashboard
- `workbench/resources/views/livewire/` — All Livewire component views

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AdminPortalLinks`: Complete with 6 intents + portal link generation — no changes needed
- `OrganizationSwitcher`: Org switching Livewire component — exists and working
- `TodoList`/`TodoItem`: Livewire components for todo CRUD — exist, may need polish
- `DashboardController`: Dashboard page — exists
- Flux Pro + Tailwind CSS: Already configured in workbench

### Established Patterns
- Livewire components in `workbench/app/Livewire/` with views in `workbench/resources/views/livewire/`
- Routes use `auth:workos` and `workos.organization.current` middleware groups
- Models use HasWorkOSId, HasWorkOSPermissions traits
- Pest PHP for tests with `InteractsWithWorkOS` trait available

### Integration Points
- Todo model scoped to organization via `organization_id` foreign key
- AuditMiddleware can be applied to route groups for automatic logging
- CheckRole/CheckPermission middleware registered as `workos.role`/`workos.permission` aliases
- WorkOS::fake() replaces container bindings for test isolation

</code_context>

<specifics>
## Specific Ideas

- Showcase quality means loading states, transitions, empty states, responsive design — this is the primary evaluation tool for the package
- RBAC with middleware is more realistic than blade directives alone — demonstrates CheckRole/CheckPermission on actual routes
- All actions audited gives the most compelling demo of audit log capabilities

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 04-workbench-example-app*
*Context gathered: 2026-04-07*
