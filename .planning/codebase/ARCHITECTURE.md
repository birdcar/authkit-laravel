# Architecture

**Analysis Date:** 2026-04-06

## Pattern Overview

**Overall:** Service Provider-based Laravel library with layered authentication and organization management

**Key Characteristics:**
- Laravel service provider integration pattern for library bootstrapping
- Guard-based authentication system extending Laravel's built-in auth contracts
- Facade for ergonomic service access to WorkOS PHP SDK
- Event-driven webhook synchronization for external state management
- Cookie-based session management using WorkOS's Halite encryption

## Layers

**Service Layer (`src/WorkOS.php`):**
- Purpose: Provides high-level API to WorkOS SDK functionality with caching
- Location: `src/WorkOS.php`
- Contains: Service method mapping, session management, authentication helpers
- Depends on: SessionManager, AuditLogger, WorkOS PHP SDK
- Used by: Controllers, Listeners, Blade directives, application code via Facade

**Authentication Layer (`src/Auth/`):**
- Purpose: Implements Laravel Guard contract and manages session lifecycle
- Location: `src/Auth/`
- Contains: WorkOSGuard (guard implementation), SessionManager (cookie+token handling), WorkOSSession (data model)
- Depends on: Laravel auth contracts, WorkOS PHP SDK's CookieSession
- Used by: WorkOSServiceProvider, middleware, controllers

**HTTP Layer (`src/Http/`):**
- Purpose: Routes and middleware for handling authentication flows
- Location: `src/Http/`
- Contains: AuthController (login/callback/logout), OrganizationController (org switching/invitations), WebhookController (event ingestion), middleware for authorization checks
- Depends on: Authentication layer, Service layer, Event system
- Used by: Routes registered via ServiceProvider

**Event & Listener Layer (`src/Events/`, `src/Listeners/`):**
- Purpose: Decouples webhook processing from database synchronization
- Location: `src/Events/` and `src/Listeners/`
- Contains: Webhook event classes (WorkOSUserCreated, etc.), listeners that sync to local User/Organization models
- Depends on: User/Organization models (via config), event system
- Used by: WebhookController, ServiceProvider event registration

**Model Traits (`src/Models/Concerns/`):**
- Purpose: Extend user/organization models with WorkOS-specific functionality
- Location: `src/Models/Concerns/`
- Contains: HasWorkOSId (WorkOS ID lookup), HasWorkOSPermissions (role/permission checks), HasOrganization (multi-org support)
- Depends on: Eloquent models
- Used by: Application User/Organization models via trait inclusion

**Installation & Setup (`src/Install/`):**
- Purpose: Interactive wizard and file manipulation for initial setup
- Location: `src/Install/`
- Contains: WizardFlow (orchestrates installation steps), installers for routes/auth/webhooks, migration plan generator for legacy auth system migration
- Depends on: File system, environment detection, console commands
- Used by: InstallCommand console command

**Middleware (`src/Http/Middleware/`):**
- Purpose: HTTP request filtering for authentication and authorization
- Location: `src/Http/Middleware/`
- Contains: EnsureWorkOSAuthenticated (guards routes), CheckRole/CheckPermission (authorization), SetCurrentOrganization (org context), DetectImpersonation (admin mode)
- Depends on: SessionManager, request object
- Used by: Route groups and individual routes

**Audit Layer (`src/Audit/`):**
- Purpose: Optional WorkOS Audit Logs API integration
- Location: `src/Audit/`
- Contains: AuditLogger (formats and sends events), AuditMiddleware (captures requests), Auditable contract
- Depends on: WorkOS PHP SDK auditLogs service, SessionManager
- Used by: ApplicationOptionally via WorkOS::audit() or trait inclusion

## Data Flow

**Authentication Flow:**

1. User visits `/auth/login` → AuthController::login()
2. Generates WorkOS authorization URL with state parameter (redirect_uri from config)
3. WorkOS redirects back to `/auth/callback` with authorization code
4. AuthController::callback() exchanges code for tokens via WorkOS::userManagement()->authenticateWithCode()
5. SessionManager::store() seals tokens with Halite encryption into wos-session cookie
6. WorkOSSession created from auth response containing user ID, org, roles/permissions
7. User model found/created via trait method findOrCreateByWorkOS() or updateOrCreate() fallback
8. UserAuthenticated event fired for application hooks

**Session Validation Flow:**

1. Incoming request intercepted by SessionManager
2. getCookieSession() loads and decrypts wos-session cookie
3. CookieSession::authenticate() validates token signature
4. If expired: attemptRefresh() refreshes using refresh_token
5. WorkOSSession cached per-request in $cachedSession
6. Guard::user() retrieves cached session and loads User model via provider

**Organization Context Flow:**

1. WorkOS session contains organizationId from multi-org login
2. SetCurrentOrganization middleware reads from session
3. CheckOrganization middleware validates user belongs to requested org
4. User traits query organizations via HasOrganization::organizations() relation
5. Audit logs include org context via SessionManager::getOrganizationId()

**Webhook Processing Flow:**

1. POST `/webhooks/workos` → WebhookController::handle()
2. Validates WorkOS-Signature header against webhook_secret
3. Maps event type (user.created, organization.updated, etc.) to event class
4. Dispatches generic WebhookReceived + specific event (WorkOSUserCreated, etc.)
5. Service Provider wires listeners: SyncUserFromWebhook, SyncOrganizationFromWebhook, SyncMembershipFromWebhook
6. Listeners call trait methods (findByWorkOSId) to sync to local models

**State Management:**

- **Primary source of truth:** WorkOS wos-session cookie (sealed, encrypted by Halite)
- **Secondary source:** Local User/Organization models synced via webhooks
- **Ephemeral state:** WorkOSSession object cached per-request in SessionManager
- **Session duration:** 30 days (cookie) with hourly expiry checks; refresh token used for automatic renewal

## Key Abstractions

**WorkOSSession:**
- Purpose: Immutable readonly representation of authenticated session
- Examples: `src/Auth/WorkOSSession.php`
- Pattern: Value object with factory methods (fromAuthResponse, fromArray), expiry/permission checks
- Properties: userId, accessToken, refreshToken, expiresAt, sessionId, roles, permissions, organizationId, impersonator

**SessionManager:**
- Purpose: Bridge between WorkOS PHP SDK's CookieSession and Laravel's request/response cycle
- Examples: `src/Auth/SessionManager.php`
- Pattern: Manages cache invalidation, token refresh, cookie encryption/decryption
- Key methods: getValidSession(), store(), destroy(), getOrganizationId(), hasPermission(), hasRole()

**WorkOSGuard:**
- Purpose: Implements Laravel's Guard contract for use with auth() helper
- Examples: `src/Auth/WorkOSGuard.php`
- Pattern: Delegates session lookup to SessionManager, user loading to UserProvider
- Integration: Registered in ServiceProvider::configureGuard() as 'workos' guard

**WorkOS Facade:**
- Purpose: Ergonomic static access to service + SDK methods
- Examples: `src/Facades/WorkOS.php`, `src/WorkOS.php`
- Pattern: Exposes high-level auth methods + passes through SDK service methods
- Usage: WorkOS::loginUrl(), WorkOS::storeSession(), WorkOS::userManagement(), etc.

**Event-Driven Webhook Sync:**
- Purpose: Decouple webhook ingestion from database mutations
- Examples: Event classes in `src/Events/Webhooks/`, listeners in `src/Listeners/`
- Pattern: WebhookController dispatches events → Laravel event dispatcher → specific listeners
- Flexibility: Applications can listen to same events for custom sync logic

## Entry Points

**Service Registration:**
- Location: `src/WorkOS.php` and `src/WorkOSServiceProvider.php`
- Triggers: Framework service provider boot during app initialization
- Responsibilities: Register guard, middleware, commands; configure routes and webhooks; load migrations

**Authentication Routes:**
- Location: `routes/web.php`
- Triggers: HTTP requests to /auth/* paths
- Responsibilities: GET /auth/login (initiate), GET /auth/callback (handle OAuth callback), GET|POST /auth/logout (terminate)

**Organization Routes:**
- Location: `routes/organizations.php`
- Triggers: HTTP requests to /organizations/* with auth:workos middleware
- Responsibilities: POST /organizations/switch (org context change), POST /organizations/{org}/invitations (send), DELETE revoke

**Webhook Route:**
- Location: `routes/webhooks.php`
- Triggers: POST /webhooks/workos with WorkOS-Signature header
- Responsibilities: Validate signature, dispatch typed events for user/org/membership changes

**Console Commands:**
- Location: `src/Commands/`
- Triggers: php artisan workos:install, workos:sync-users, workos:listen-events
- Responsibilities: InstallCommand (interactive setup), SyncUsersCommand (backfill users), EventsListenCommand (local testing)

## Error Handling

**Strategy:** Fail open with exceptions, catch and report in critical paths

**Patterns:**

- **Authentication failures:** AuthController catches exceptions from authenticateWithCode(), returns null, redirects to login with error
- **Session validation:** SessionManager catches exceptions from authenticate()/refresh(), returns null (guest), middleware redirects
- **Webhook validation:** WebhookController catches signature verification exceptions, logs via report(), returns 400
- **Database lookups:** Listeners check for method existence before calling (findByWorkOSId), silently skip if missing
- **Audit logs:** AuditLogger catches exceptions from API call, reports but doesn't propagate

## Cross-Cutting Concerns

**Logging:** No centralized logging layer; exceptions logged via report() to Laravel's error handler

**Validation:** 
- HTTP inputs validated in controllers via Form Request-style validation
- Session state validated in SessionManager (expiry checks, token presence)
- Webhook signature validated via WorkOS::webhook()->constructEvent()

**Authentication:** 
- Implemented as Guard + SessionManager + WorkOSSession value object
- Integrated with Laravel's auth() helper and Authenticatable contract
- Supports custom user providers via config('workos.user_model')

**Authorization:**
- Role-based via WorkOSSession::hasRole()
- Permission-based via WorkOSSession::hasPermission()
- Blade directives (@workosRole, @workosPermission) for template checks
- Middleware (CheckRole, CheckPermission) for route protection
- Fallback to session permissions; org-specific via HasOrganization trait

**Impersonation:**
- Detected in SessionManager via $session->impersonator property
- Available to app via WorkOS::isImpersonating()
- Blade directive @impersonating for UI/audit purposes
- Tracked in audit logs via AuditLogger

---

*Architecture analysis: 2026-04-06*
