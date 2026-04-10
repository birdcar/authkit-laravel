# PRD: AuthKit Laravel - Phase 6

**Contract**: ./contract.md
**Phase**: 6 of 6
**Focus**: Testing utilities, Inertia.js support, and example application

## Phase Overview

Phase 6 completes the package with comprehensive testing utilities, frontend integration support, and a full example application demonstrating all features. This is the polish phase that makes the package production-ready and easy to adopt.

This phase is last because testing utilities need all features to exist before they can be tested, and the example application demonstrates the complete integration. The Inertia.js middleware needs all the auth data to be available before it can share it.

After Phase 6 completes, the package is ready for public release. Developers have everything they need: comprehensive testing tools, working examples, and documentation through the example app that shows best practices for integration.

## User Stories

1. As a developer, I want `WorkOS::fake()` so that I can test auth flows without hitting real APIs
2. As a developer, I want `actingAs()` with roles/permissions so that I can test authorization in isolation
3. As a developer, I want audit assertions so that I can verify logging behavior in tests
4. As an Inertia.js developer, I want auth state shared automatically so that I can access user/permissions in React/Vue
5. As a developer evaluating the package, I want an example app so that I can see how everything fits together

## Functional Requirements

### WorkOSFake

- **FR-6.1**: `WorkOS::fake()` must replace real service with test double
- **FR-6.2**: Fake must not make any HTTP requests to WorkOS
- **FR-6.3**: Fake must be restorable via `WorkOS::restore()`
- **FR-6.4**: Fake must capture all audit events for assertion
- **FR-6.5**: Fake must allow setting arbitrary user/session state

### actingAs() Test Helper

- **FR-6.6**: `WorkOS::actingAs($user)` must authenticate user for testing
- **FR-6.7**: `actingAs($user, roles: ['admin'])` must set roles on fake session
- **FR-6.8**: `actingAs($user, permissions: ['billing:read'])` must set permissions
- **FR-6.9**: Method must return fluent builder for chaining
- **FR-6.10**: `->inOrganization($orgId)` must set organization context
- **FR-6.11**: `->impersonating(['id' => '...', 'email' => '...'])` must set impersonator
- **FR-6.12**: `->withRoles([...])` and `->withPermissions([...])` for incremental building

### Test Assertions (expanded from Phase 4)

- **FR-6.13**: `assertAuthenticated()` must verify user is logged in
- **FR-6.14**: `assertGuest()` must verify no user is authenticated
- **FR-6.15**: `assertHasRole($role)` must verify current user has role
- **FR-6.16**: `assertHasPermission($permission)` must verify current user has permission
- **FR-6.17**: `assertInOrganization($orgId)` must verify organization context
- **FR-6.18**: All assertions must provide helpful failure messages

### Pest Plugin (Optional)

- **FR-6.19**: Provide Pest expectations if Pest is installed
- **FR-6.20**: `expect($user)->toHaveWorkOSRole('admin')`
- **FR-6.21**: `expect($user)->toHaveWorkOSPermission('billing:read')`
- **FR-6.22**: Plugin must be optional (not required dependency)

### ShareWorkOSData Middleware (Inertia.js)

- **FR-6.23**: Middleware must share auth state via `Inertia::share()`
- **FR-6.24**: Shared data must include: `auth.user`, `auth.roles`, `auth.permissions`
- **FR-6.25**: Shared data must include: `auth.organization`, `auth.impersonating`
- **FR-6.26**: Shared data must include: `auth.check` (boolean)
- **FR-6.27**: Middleware must handle unauthenticated state gracefully
- **FR-6.28**: Middleware must be registered as `workos.inertia` alias
- **FR-6.29**: Data structure must be documented for frontend consumption

### Livewire/Blade Support

- **FR-6.30**: Blade directives from Phase 2 must work in Livewire components
- **FR-6.31**: Document `workos()` helper usage in Livewire
- **FR-6.32**: Provide example Livewire components in example app

### Example Application

- **FR-6.33**: Create separate `workos/authkit-laravel-example` repository
- **FR-6.34**: Example must demonstrate complete auth flow (login, logout)
- **FR-6.35**: Example must demonstrate role-based route protection
- **FR-6.36**: Example must demonstrate permission-based UI conditionals
- **FR-6.37**: Example must demonstrate team switching
- **FR-6.38**: Example must demonstrate audit logging
- **FR-6.39**: Example must include both Blade and Inertia.js versions
- **FR-6.40**: Example must include comprehensive test suite

### Documentation via Example

- **FR-6.41**: Example README must serve as getting-started guide
- **FR-6.42**: Example must include comments explaining each integration point
- **FR-6.43**: Example must demonstrate testing patterns

### Session Cleanup Command

- **FR-6.44**: `workos:prune-sessions` command must clean expired sessions
- **FR-6.45**: Command must be schedulable via Laravel scheduler
- **FR-6.46**: Command must support `--hours=` option for retention period

## Non-Functional Requirements

- **NFR-6.1**: WorkOSFake must add zero HTTP overhead (no network calls)
- **NFR-6.2**: Inertia middleware must add less than 1ms per request
- **NFR-6.3**: Example app must demonstrate best practices, not just functionality
- **NFR-6.4**: Test suite must achieve >90% code coverage
- **NFR-6.5**: All tests must run in under 30 seconds

## Dependencies

### Prerequisites

- Phase 1-5 complete (all features implemented)

### Outputs

- Complete, production-ready package
- Comprehensive testing utilities
- Inertia.js integration middleware
- Example application demonstrating full integration
- Test coverage >90%

## Acceptance Criteria

- [ ] `WorkOS::fake()` prevents all HTTP calls to WorkOS
- [ ] `WorkOS::actingAs($user, roles: ['admin'])->inOrganization($org)` works
- [ ] Audit assertions verify logged events correctly
- [ ] Inertia middleware shares auth state to frontend
- [ ] `page.props.auth.user` available in Vue/React components
- [ ] Example app installs and runs without errors
- [ ] Example app demonstrates login flow end-to-end
- [ ] Example app includes working tests
- [ ] `workos:prune-sessions --hours=24` cleans old sessions
- [ ] Package has >90% test coverage
- [ ] All tests pass in CI (Laravel 10, 11, 12 matrix)
- [ ] Package passes Laravel Pint style checks

---

*Review this PRD and provide feedback before spec generation.*
