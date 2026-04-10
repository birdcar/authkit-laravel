# PRD: AuthKit Laravel - Phase 2

**Contract**: ./contract.md
**Phase**: 2 of 6
**Focus**: Authorization primitives - model traits, middleware, Blade directives, and auth routes

## Phase Overview

Phase 2 builds the authorization layer on top of Phase 1's authentication foundation. This includes model traits that add WorkOS capabilities to Eloquent models, middleware for protecting routes, Blade directives for template conditionals, and the actual HTTP routes for login/callback/logout.

This phase is sequenced second because authorization requires authentication to exist first. You can't check roles until you can identify who the user is. The middleware depends on the guard from Phase 1, and the traits need the session manager to access role/permission data.

After Phase 2 completes, developers have a fully functional auth system with route protection. They can add `use HasWorkOSId, HasWorkOSPermissions` to their User model, protect routes with `middleware('workos.role:admin')`, and use `@workosPermission('billing:read')` in Blade templates. This is a complete single-user authentication and authorization solution.

## User Stories

1. As a Laravel developer, I want to add traits to my User model so that I get WorkOS functionality without modifying my model structure
2. As a Laravel developer, I want to protect routes with role/permission middleware so that unauthorized users can't access sensitive areas
3. As a Laravel developer, I want Blade directives so that I can conditionally show UI elements based on permissions
4. As a Laravel developer, I want preconfigured auth routes so that I don't have to write login/callback/logout controllers
5. As a user, I want to click "Sign In" and be redirected through WorkOS so that I can authenticate with my organization's SSO

## Functional Requirements

### Model Traits - HasWorkOSId

- **FR-2.1**: Trait must add `workos_id` to model's fillable array via `initializeHasWorkOSId()`
- **FR-2.2**: Trait must provide `getWorkOSIdColumn()` returning column name (default: 'workos_id')
- **FR-2.3**: Trait must provide `getWorkOSId()` returning the current model's WorkOS ID
- **FR-2.4**: Trait must provide `findByWorkOSId(string $id)` static method
- **FR-2.5**: Trait must provide `findOrCreateByWorkOS(array $workosUser)` static method

### Model Traits - HasWorkOSPermissions

- **FR-2.6**: Trait must store WorkOSSession instance via `setWorkOSSession()`
- **FR-2.7**: Trait must provide `getWorkOSSession()` returning current session or null
- **FR-2.8**: Trait must provide `hasWorkOSRole(string $role): bool`
- **FR-2.9**: Trait must provide `hasWorkOSPermission(string $permission): bool`
- **FR-2.10**: Trait must provide `hasAnyWorkOSRole(array $roles): bool`
- **FR-2.11**: Trait must provide `hasAllWorkOSPermissions(array $permissions): bool`
- **FR-2.12**: Trait must provide `currentOrganizationId(): ?string`
- **FR-2.13**: Trait must provide `isImpersonating(): bool`
- **FR-2.14**: Trait must provide `impersonator(): ?array` returning impersonator details

### Middleware - EnsureWorkOSAuthenticated

- **FR-2.15**: Middleware must check if user is authenticated via workos guard
- **FR-2.16**: Middleware must redirect to login route if not authenticated (web requests)
- **FR-2.17**: Middleware must return 401 JSON response if not authenticated (API requests)
- **FR-2.18**: Middleware must be registered as `workos.auth` alias

### Middleware - CheckRole

- **FR-2.19**: Middleware must accept role names as parameters: `workos.role:admin,editor`
- **FR-2.20**: Middleware must check if authenticated user has ANY of the specified roles
- **FR-2.21**: Middleware must throw `MissingRoleException` if check fails
- **FR-2.22**: Exception must render as 403 with appropriate message

### Middleware - CheckPermission

- **FR-2.23**: Middleware must accept permission names as parameters: `workos.permission:billing:read`
- **FR-2.24**: Middleware must check if authenticated user has ALL specified permissions
- **FR-2.25**: Middleware must throw `MissingPermissionException` if check fails
- **FR-2.26**: Exception must render as 403 with appropriate message

### Middleware - DetectImpersonation

- **FR-2.27**: Middleware must set request attribute indicating impersonation status
- **FR-2.28**: Middleware must optionally block certain routes during impersonation
- **FR-2.29**: Middleware must be registered as `workos.impersonation` alias

### Blade Directives

- **FR-2.30**: `@workosRole('role')` directive must check if user has role
- **FR-2.31**: `@workosPermission('permission')` directive must check if user has permission
- **FR-2.32**: `@impersonating` directive must check if session is impersonated
- **FR-2.33**: All directives must handle unauthenticated users (return false)
- **FR-2.34**: Corresponding `@else` and `@end` directives must work correctly

### Auth Routes

- **FR-2.35**: `GET /auth/login` must redirect to WorkOS authorization URL
- **FR-2.36**: Login route must support optional `organization` query parameter
- **FR-2.37**: `GET /auth/callback` must handle OAuth callback from WorkOS
- **FR-2.38**: Callback must authenticate user via session, create user if needed, and redirect
- **FR-2.39**: `POST /auth/logout` must destroy session and redirect to WorkOS logout
- **FR-2.40**: Routes must be configurable via config (prefix, middleware, paths)
- **FR-2.41**: Routes must be disableable via `config('workos.routes.enabled')`

### Events

- **FR-2.42**: `UserAuthenticated` event must be dispatched after successful callback
- **FR-2.43**: `UserLoggedOut` event must be dispatched after logout
- **FR-2.44**: Events must include user model and session data

## Non-Functional Requirements

- **NFR-2.1**: Middleware must add less than 1ms overhead per request
- **NFR-2.2**: Blade directives must be compiled (not runtime evaluated)
- **NFR-2.3**: Role/permission checks must not make external API calls (use session data)
- **NFR-2.4**: Login redirect must include CSRF state parameter for security
- **NFR-2.5**: Callback must validate state parameter to prevent CSRF attacks

## Dependencies

### Prerequisites

- Phase 1 complete (service provider, guard, session manager, facade)

### Outputs for Next Phase

- HasWorkOSId trait for adding workos_id to models
- HasWorkOSPermissions trait for role/permission checking
- Working middleware for route protection
- Blade directives for template conditionals
- Complete auth flow (login → WorkOS → callback → authenticated)
- UserAuthenticated and UserLoggedOut events

## Acceptance Criteria

- [ ] User model with traits can call `$user->hasWorkOSRole('admin')`
- [ ] `User::findByWorkOSId('user_123')` returns correct user
- [ ] `User::findOrCreateByWorkOS($data)` creates new user if not exists
- [ ] Route with `middleware('workos.role:admin')` blocks non-admin users
- [ ] Route with `middleware('workos.permission:billing:read')` checks permission
- [ ] `@workosRole('admin')` renders content only for admins
- [ ] `@impersonating` shows banner during impersonation
- [ ] Visiting `/auth/login` redirects to WorkOS
- [ ] OAuth callback creates session and redirects to intended URL
- [ ] Logout destroys session and redirects appropriately
- [ ] UserAuthenticated event fires after login
- [ ] All middleware registered with correct aliases
- [ ] All unit and feature tests passing

---

*Review this PRD and provide feedback before spec generation.*
