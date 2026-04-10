# AuthKit Laravel Contract

**Created**: 2026-01-23
**Confidence Score**: 98/100
**Status**: Draft

## Problem Statement

Laravel developers integrating WorkOS AuthKit face a significant friction gap. The existing `workos/workos-php-laravel` package is a thin wrapper that requires developers to manually wire up authentication guards, session management, middleware, and authorization logic. This leads to inconsistent implementations, security oversights, and significant boilerplate code.

Developers expect Laravel packages to follow established conventions (like Sanctum and Jetstream) with service providers, facades, model traits, publishable configs, and artisan commands. Without these, teams spend days implementing what should be a drop-in solution.

The cost of not solving this is slower adoption of WorkOS AuthKit in the Laravel ecosystem, higher support burden, and fragmented community implementations that don't follow best practices.

## Goals

1. **Drop-in authentication**: Developers can install the package and have working AuthKit login/logout within 15 minutes, using Laravel's native auth system
2. **Laravel-native patterns**: All features use Laravel conventions (guards, middleware, traits, facades, blade directives) so developers feel immediately productive
3. **Full-featured authorization**: Role and permission checking works seamlessly with WorkOS's authorization model, including organization context and impersonation detection
4. **Production-ready**: Includes audit logging, webhook handling, session refresh, and comprehensive test utilities so apps are secure and maintainable from day one
5. **Frontend-agnostic**: Works with both Livewire/Blade and Inertia.js stacks via headless data sharing, without forcing specific UI components

## Success Criteria

- [ ] Package installs via Composer and auto-registers service provider
- [ ] `workos:install` command publishes config, migrations, and updates `auth.php`
- [ ] Custom `workos` guard authenticates users via WorkOS session
- [ ] Sessions auto-refresh before expiry without user intervention
- [ ] Model traits (`HasWorkOSId`, `HasWorkOSPermissions`) work on any Eloquent model
- [ ] Middleware (`workos.role:admin`, `workos.permission:billing:read`) protects routes
- [ ] Blade directives (`@workosRole`, `@workosPermission`, `@impersonating`) work in templates
- [ ] Facade (`WorkOS::user()`, `WorkOS::hasRole()`) and helper (`workos('user')`) provide ergonomic access
- [ ] Audit logger sends events to WorkOS Audit Logs API
- [ ] Webhook controller verifies signatures and dispatches Laravel events
- [ ] Events API example/sidecar process demonstrates real-time event handling
- [ ] Full team management (switching, roles per team, invitations) matches Jetstream patterns
- [ ] `WorkOS::fake()` and `WorkOS::actingAs()` enable comprehensive testing
- [ ] Test assertions (`assertAudited`, `assertNotAudited`) verify audit behavior
- [ ] ShareWorkOSData middleware shares auth state for Inertia.js apps
- [ ] All tests pass with >90% coverage
- [ ] Example application demonstrates full integration
- [ ] Works with Laravel 10, 11, and 12 on PHP 8.1+

## Scope Boundaries

### In Scope

**Authentication & Sessions**
- Custom WorkOS auth guard using `Auth::extend()`
- Session manager using Laravel's native session store
- Automatic session refresh before expiry
- Impersonation detection and context

**Authorization**
- Role and permission checking via model traits
- Middleware for route protection
- Blade directives for template conditionals
- Full team management (create, switch, invite, roles per team)

**Developer Experience**
- WorkOS facade with full IDE autocompletion
- `workos()` helper with shortcuts
- Publishable config with feature flags
- Publishable migrations
- Artisan commands (install, sync-users, prune-sessions)

**Audit Logging**
- AuditLogger service wrapping WorkOS API
- AuditMiddleware for automatic route logging
- Test assertions for audit verification

**Webhooks & Events**
- Webhook controller with signature verification
- Laravel event dispatching for all webhook types (except directory sync)
- Events API example/sidecar for real-time processing
- UserAuthenticated, UserLoggedOut, ImpersonationStarted/Ended events

**Frontend Support**
- ShareWorkOSData middleware for Inertia.js
- Headless data sharing (no UI components)
- Works with Livewire/Blade via Blade directives

**Testing**
- WorkOSFake for mocking
- `actingAs()` with roles/permissions/organization
- Fluent builder API for test setup
- Audit event assertions
- Example application with integration tests

### Out of Scope

- **UI components** - Package is headless; users build their own login buttons, menus, etc.
- **Directory Sync webhook handling** - WorkOS recommends Events API for this
- **MFA/Passwordless flows** - Focus on AuthKit OAuth flow only
- **Custom SSO configuration UI** - Handled in WorkOS Dashboard
- **User provisioning/deprovisioning automation** - Beyond initial sync command

### Future Considerations

- Vue/React component library (separate package)
- Admin dashboard for user management
- Multi-tenancy database separation (beyond team context)
- GraphQL integration
- Livewire components for common auth UI patterns

---

*This contract was generated from brain dump input. Review and approve before proceeding to PRD generation.*
