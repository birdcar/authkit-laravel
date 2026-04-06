# Architecture

**Analysis Date:** 2026-04-06

## Pattern Overview

**Overall:** Service Provider with Event-Driven Webhook Synchronization

This is a Laravel package that wraps the WorkOS SDK and provides authentication, organization management, and real-time data sync via webhooks. The architecture follows a clear separation between authentication concerns, session management, data synchronization, and audit logging.

**Key Characteristics:**
- Service provider-driven dependency injection (Laravel standard)
- Cookie-based session management using WorkOS's sealed session format
- Event-driven architecture for webhook processing and data sync
- Guard implementation for Laravel's authentication system
- Middleware-based authorization and impersonation detection
- Support for organizations with role-based access control (RBAC)

## Layers

**Service Registration Layer:**
- Purpose: Bootstrap the package, register bindings, configure Laravel framework
- Location: `src/WorkOSServiceProvider.php`
- Contains: Service provider with registration and boot phases
- Depends on: Laravel service container, configuration files
- Used by: Laravel framework during bootstrap

**Authentication Layer:**
- Purpose: Manage user sessions, validate tokens, provide guard implementation
- Location: `src/Auth/`
  - `WorkOSGuard.php`: Implements Laravel's `Guard` contract for WorkOS
  - `SessionManager.php`: Manages sealed cookie sessions from WorkOS SDK
  - `WorkOSSession.php`: Value object representing authenticated session state
- Contains: Guard, session management, session value objects
- Depends on: WorkOS SDK, Laravel authentication contracts, Laravel cookies
- Used by: Middleware, controllers, guard resolution

**HTTP Request Handling Layer:**
- Purpose: Handle OAuth flow, webhooks, and organization management
- Location: `src/Http/`
  - `Controllers/AuthController.php`: OAuth callback, session storage, logout
  - `Controllers/WebhookController.php`: Validate and dispatch webhook events
  - `Controllers/OrganizationController.php`: Switch organizations, manage invitations
  - `Middleware/`: Request-scoped concerns (auth checks, impersonation detection, org context)
- Contains: Controllers, middleware, request validation
- Depends on: Authentication layer, events, WorkOS SDK
- Used by: HTTP router, request pipeline

**Model Traits Layer:**
- Purpose: Extend application User/Organization models with WorkOS capabilities
- Location: `src/Models/Concerns/`
  - `HasWorkOSPermissions.php`: Role/permission checking, org context
  - `HasWorkOSId.php`: WorkOS ID mapping
  - `HasOrganization.php`: Organization relationship
- Contains: Trait mixins for model enrichment
- Depends on: WorkOSSession value object, Laravel Eloquent
- Used by: Application User model

**Event & Webhook Layer:**
- Purpose: Handle real-time sync from WorkOS via webhooks
- Location: `src/Events/` and `src/Listeners/`
  - `Http/Controllers/WebhookController.php`: Entry point, signature validation, event dispatch
  - `Events/Webhooks/`: Specific webhook events (WorkOSUserCreated, etc.)
  - `Listeners/`: Handle sync to local database
- Contains: Event classes, listener handlers
- Depends on: HTTP layer, models, Laravel events
- Used by: Webhook HTTP endpoint, event dispatcher

**Audit Layer:**
- Purpose: Log user actions to WorkOS Audit Logs API
- Location: `src/Audit/`
  - `AuditLogger.php`: Format and send audit events
  - `AuditMiddleware.php`: Capture request context for audit
  - `Concerns/HasAuditTrail.php`: Model interface for auditable objects
- Contains: Audit event formatting, logging, middleware
- Depends on: SessionManager, WorkOS SDK audit service, Laravel auth
- Used by: Controllers, middleware, application code via facade

**Installation Layer:**
- Purpose: Interactive wizard and configuration for initial setup
- Location: `src/Install/`
  - `WizardFlow.php`: Orchestrates multi-step installation
  - `AuthSystemInstaller.php`: Configure auth guard/provider
  - `RouteInstaller.php`: Publish routes
  - `WebhookInstaller.php`: Configure webhook endpoint
  - `MigrationPlanGenerator.php`: Detect existing auth and plan migration
- Contains: CLI command orchestration, file publishing, env configuration
- Depends on: File system, environment manager, Laravel console
- Used by: InstallCommand

**Facade & Public API Layer:**
- Purpose: Provide convenient access to WorkOS functionality
- Location: `src/Facades/WorkOS.php` and `src/helpers.php`
- Contains: Facade definition, global helper function
- Depends on: WorkOS main class, Laravel facade system
- Used by: Application code, controllers, tests

**Central Coordination:**
- Location: `src/WorkOS.php`
- Purpose: Main entry point, lazy-loads WorkOS SDK services, coordinates session access
- Acts as service locator for SDK services and session management
- Used by: Controllers, facade, middleware

## Data Flow

**Authentication Flow:**

1. User requests `/auth/login` → `AuthController::login()`
2. Controller generates WorkOS login URL with optional org/state parameters
3. Browser redirects to WorkOS login
4. User authenticates at WorkOS
5. Browser redirected to `/auth/callback?code=...&state=...`
6. `AuthController::callback()` receives auth code
7. Controller calls `WorkOS::userManagement()->authenticateWithCode()`
8. Returns token response (access_token, refresh_token, user data)
9. `SessionManager::store()` encrypts tokens using Halite encryption and sets sealed cookie
10. `findOrCreateUser()` creates/updates local user record from WorkOS data
11. `UserAuthenticated` event dispatched
12. Redirect to home or return_to URL

**Webhook Sync Flow:**

1. WorkOS posts webhook to `/webhooks/workos`
2. `WebhookController::handle()` validates signature using WorkOS SDK
3. Parses event type and data
4. Dispatches generic `WebhookReceived` event
5. Dispatches type-specific event (e.g., `WorkOSUserCreated`)
6. Listener (e.g., `SyncUserFromWebhook`) handles event
7. Updates local database with data from webhook payload

**Authorization Flow:**

1. Request includes sealed `wos-session` cookie
2. Middleware or controller calls `SessionManager::getValidSession()`
3. SessionManager decrypts cookie, calls WorkOS SDK to authenticate
4. Returns `WorkOSSession` with user ID, roles, permissions, org ID
5. `WorkOSGuard::user()` retrieves local user by ID
6. Attaches `WorkOSSession` to user via `setWorkOSSession()`
7. Middleware checks session validity, roles, permissions, organization
8. Request continues with session available via `auth()` or facade

**Session State Management:**
- `SessionManager` caches session in memory during request
- Cookie is decrypted once, cached, reused for all checks in request
- Session refresh attempted if token expired
- Logout destroys cookie and clears cache

## Key Abstractions

**WorkOSSession:**
- Purpose: Immutable value object representing authenticated session state
- Examples: `src/Auth/WorkOSSession.php`
- Pattern: Value object with factory methods (`fromAuthResponse()`, `fromArray()`)
- Contains: userId, accessToken, refreshToken, roles, permissions, organizationId, impersonator
- Used by: Guard, middleware, models, audit logging

**SessionManager:**
- Purpose: Bridge between HTTP cookie layer and session value object
- Examples: `src/Auth/SessionManager.php`
- Pattern: Stateful manager with caching and lazy-loading
- Responsibilities: Decrypt cookie, call SDK for validation, cache result, handle refresh
- Used by: Guard, middleware, controllers

**WorkOSGuard:**
- Purpose: Implement Laravel Guard contract using WorkOS sessions
- Examples: `src/Auth/WorkOSGuard.php`
- Pattern: Adapter implementing `Illuminate\Contracts\Auth\Guard`
- Delegates session retrieval to SessionManager, user lookup to provider

**Event Mapping:**
- Purpose: Route webhook events to application events
- Examples: `src/Http/Controllers/WebhookController.php::EVENT_MAP`
- Pattern: Static event-type-to-class map
- Allows decoupling webhook processing from event definitions

**Model Traits as Feature Modules:**
- Purpose: Compose WorkOS capabilities into models
- Examples: `HasWorkOSPermissions`, `HasWorkOSId`, `HasOrganization`
- Pattern: Trait-based feature composition
- Allows selective adoption of WorkOS features in user-provided models

## Entry Points

**OAuth Login/Logout Routes:**
- Location: `routes/web.php`
- Routes: `GET /auth/login`, `GET /auth/callback`, `GET|POST /auth/logout`
- Handler: `AuthController`
- Triggers: User-initiated authentication, callback from WorkOS, explicit logout

**Organization Management Routes:**
- Location: `routes/organizations.php`
- Routes: `POST /organizations/switch`, `POST /organizations/{id}/invitations`, `DELETE /organizations/{id}/invitations/{id}`
- Handler: `OrganizationController`
- Triggers: Organization switching, user invitations
- Guarded by: `auth:workos` middleware

**Webhook Endpoint:**
- Location: `routes/webhooks.php`
- Route: `POST /webhooks/workos`
- Handler: `WebhookController::handle()`
- Triggers: WorkOS webhook delivery (user/org/membership/session events)
- Signature validation: WorkOS SDK `Webhook::constructEvent()`

**CLI Commands:**
- `InstallCommand`: Interactive setup wizard
- `SyncUsersCommand`: Manual user synchronization from WorkOS API
- `EventsListenCommand`: Debug webhook events

## Error Handling

**Strategy:** Graceful degradation with reporting

**Patterns:**
- Try-catch blocks around external API calls (SessionManager, AuthController)
- Report exceptions to Laravel error handler without crashing request
- Return null for missing data rather than throwing
- Middleware returns 401 JSON or redirect to login, not exceptions
- Webhook handler returns 400 for invalid signature, 500 if webhook secret missing
- Listener exceptions caught and reported, don't halt event processing

Examples:
- `SessionManager::getSession()`: Returns null if decrypt/validation fails
- `AuthController::authenticateWithCode()`: Returns null if API call fails
- `AuditLogger::log()`: Catches and reports exception, returns silently
- `WebhookController`: Catches exception, reports, returns 400

## Cross-Cutting Concerns

**Logging:** No structured logging framework integrated. Uses Laravel's `report()` for exceptions and console `line()` for CLI output.

**Validation:** 
- Controllers use `Request::validate()` for input validation
- WorkOS SDK validates signatures and tokens
- SessionManager validates session data structure

**Authentication:** 
- Cookie-based using WorkOS sealed session format
- Encrypted with Laravel app key (Halite encryption)
- Session refresh handled transparently
- Impersonation flag propagated from WorkOS

**Authorization:**
- Middleware checks: `EnsureWorkOSAuthenticated`, `CheckRole`, `CheckPermission`, `CheckOrganization`
- Model methods: `hasWorkOSRole()`, `hasWorkOSPermission()`, on user model via trait
- Facade methods: `hasRole()`, `hasPermission()` on WorkOS facade

**Auditing:**
- Opt-in per-feature via config flag
- Captured at middleware level with request context
- Sent to WorkOS API asynchronously (no wait for response)
- Supports custom auditable objects via trait

---

*Architecture analysis: 2026-04-06*
