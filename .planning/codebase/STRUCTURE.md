# Codebase Structure

**Analysis Date:** 2026-04-06

## Directory Layout

```
authkit-laravel/
├── src/                               # Main library source code
│   ├── Auth/                          # Authentication system
│   ├── Http/                          # HTTP layer (controllers, middleware)
│   ├── Models/Concerns/               # Traits for User/Organization models
│   ├── Events/                        # Application and webhook events
│   ├── Listeners/                     # Event listeners
│   ├── Exceptions/                    # Custom exception classes
│   ├── Facades/                       # Facade classes
│   ├── Audit/                         # Audit logging layer
│   ├── Commands/                      # Artisan console commands
│   ├── Install/                       # Installation wizard and setup
│   ├── Support/                       # Utility and helper classes
│   ├── Testing/                       # Testing utilities for apps
│   ├── WorkOS.php                     # Main service class
│   ├── WorkOSServiceProvider.php      # Service provider (entry point)
│   └── helpers.php                    # Global helper functions
├── routes/                            # Route definitions
│   ├── web.php                        # Auth routes (login, callback, logout)
│   ├── organizations.php              # Organization management routes
│   └── webhooks.php                   # Webhook ingestion route
├── config/                            # Configuration files
│   └── workos.php                     # WorkOS configuration
├── database/                          # Database files
│   └── migrations/                    # Package migrations
├── tests/                             # Test suite
│   └── ...                            # Various test files
├── workbench/                         # Development/testing sandbox
└── composer.json                      # Package manifest
```

## Directory Purposes

**`src/Auth/`:**
- Purpose: Core authentication and session management
- Contains: WorkOSGuard, SessionManager, WorkOSSession
- Key files: `WorkOSGuard.php` (implements Guard contract), `SessionManager.php` (cookie encryption/validation), `WorkOSSession.php` (session data model)

**`src/Http/Controllers/`:**
- Purpose: HTTP request handlers for auth flows and webhooks
- Contains: AuthController (OAuth flow), OrganizationController (org switching/invitations), WebhookController (webhook ingestion)
- Key files: `AuthController.php` (login/callback/logout), `WebhookController.php` (event routing)

**`src/Http/Middleware/`:**
- Purpose: HTTP request filtering and authorization
- Contains: EnsureWorkOSAuthenticated (auth guard), CheckRole/CheckPermission (authz), SetCurrentOrganization (org context), DetectImpersonation (admin mode)
- Key files: All 7 middleware classes in this directory

**`src/Models/Concerns/`:**
- Purpose: Traits to extend User/Organization models with WorkOS functionality
- Contains: HasWorkOSId, HasOrganization, HasWorkOSPermissions
- Key files: `HasWorkOSId.php` (WorkOS ID queries), `HasOrganization.php` (org relations), `HasWorkOSPermissions.php` (role/permission checks)

**`src/Events/`:**
- Purpose: Application and webhook events for event-driven architecture
- Contains: User events (UserAuthenticated, UserLoggedOut), org events (OrganizationSwitched), webhook events (WorkOSUserCreated, WorkOSMembershipUpdated, etc.)
- Key files: Subdirectory `Webhooks/` contains specific webhook event classes

**`src/Listeners/`:**
- Purpose: Event handler logic for syncing external state to database
- Contains: SyncUserFromWebhook, SyncOrganizationFromWebhook, SyncMembershipFromWebhook
- Key files: Each listener handles specific event types and calls model trait methods

**`src/Facades/`:**
- Purpose: Facade classes for ergonomic static access
- Contains: WorkOS facade providing static access to service layer
- Key files: `WorkOS.php` (facade definition with @method docblocks)

**`src/Audit/`:**
- Purpose: Audit logging integration with WorkOS Audit Logs API
- Contains: AuditLogger, AuditMiddleware, Auditable contract
- Key files: `AuditLogger.php` (formats and sends events), `AuditMiddleware.php` (captures HTTP context)

**`src/Commands/`:**
- Purpose: Artisan console commands
- Contains: InstallCommand (setup wizard), SyncUsersCommand (bulk user sync), EventsListenCommand (webhook testing)
- Key files: `InstallCommand.php` (main setup entry point)

**`src/Install/`:**
- Purpose: Installation and setup orchestration
- Contains: WizardFlow (step coordinator), installers for routes/auth/webhooks, migration plan generator for legacy auth system migration
- Key files: `WizardFlow.php` (orchestrator), `AuthSystemInstaller.php` (Guard/user provider setup), `RouteInstaller.php` (adds routes), `LaravelWorkosMigrator.php` (handles laravel/workos migration)

**`src/Support/`:**
- Purpose: Utility and helper classes
- Contains: EnvironmentDetector (detects existing auth packages), DetectionResult (detection output model)
- Key files: `EnvironmentDetector.php` (checks for Breeze/Jetstream/Fortify)

**`src/Testing/`:**
- Purpose: Testing utilities for applications using this library
- Contains: WorkOSFake (fake implementation), InteractsWithWorkOS trait
- Key files: `WorkOSFake.php` (test double for WorkOS service), `Concerns/InteractsWithWorkOS.php` (test helper trait)

**`src/Exceptions/`:**
- Purpose: Custom exception classes
- Contains: WorkOSException (base), AuthenticationException, MissingRoleException, MissingPermissionException

**`routes/`:**
- Purpose: Route group definitions loaded by ServiceProvider
- Contains: Auth routes, organization routes, webhook route
- Key files: `web.php` (login/callback/logout), `organizations.php` (org management), `webhooks.php` (webhook handler)

**`config/workos.php`:**
- Purpose: Configuration schema for WorkOS integration
- Contains: API credentials, guard name, session settings, feature flags, route prefixes, model class names
- Used by: ServiceProvider, all services that read config('workos.*')

**`database/migrations/`:**
- Purpose: Database schema migrations provided by package
- Contains: Migrations for organization_memberships table and related schema

## Key File Locations

**Entry Points:**
- `src/WorkOSServiceProvider.php`: Service provider registration point; boots all services, routes, commands, event listeners
- `src/Commands/InstallCommand.php`: Interactive setup command (php artisan workos:install)
- `routes/web.php`: Authentication routes (login, callback, logout)

**Configuration:**
- `config/workos.php`: All configuration options and defaults
- `.env` variables: WORKOS_API_KEY, WORKOS_CLIENT_ID, WORKOS_REDIRECT_URI, WORKOS_WEBHOOK_SECRET, etc.

**Core Logic:**
- `src/Auth/SessionManager.php`: Cookie encryption/validation, token refresh, session lifecycle
- `src/Auth/WorkOSGuard.php`: Laravel Guard implementation
- `src/WorkOS.php`: Facade target; service layer coordinating SDK calls
- `src/Http/Controllers/AuthController.php`: OAuth callback and token exchange logic
- `src/Http/Controllers/WebhookController.php`: Webhook signature verification and event dispatch

**Testing:**
- `src/Testing/WorkOSFake.php`: Fake implementation for testing
- `src/Testing/Concerns/InteractsWithWorkOS.php`: Test helper methods (WorkOS::fake(), WorkOS::actingAs())

## Naming Conventions

**Files:**
- Controllers: `{Name}Controller.php` (e.g., AuthController.php, OrganizationController.php)
- Middleware: `{Check|Detect|Set|Share}{Feature}.php` (e.g., EnsureWorkOSAuthenticated.php, DetectImpersonation.php)
- Events: `{Resource}{Action}.php` or `Webhooks/{Action}.php` (e.g., UserAuthenticated.php, WorkOSUserCreated.php)
- Listeners: `{Action}FromWebhook.php` (e.g., SyncUserFromWebhook.php)
- Traits: `Has{Feature}.php` (e.g., HasWorkOSId.php, HasOrganization.php)
- Exceptions: `{Type}Exception.php` (e.g., AuthenticationException.php, MissingRoleException.php)

**Classes:**
- Controllers: Suffix with `Controller`
- Middleware: Prefix with action verb or feature name (EnsureWorkOSAuthenticated, CheckRole)
- Traits: Prefix with `Has` for model concerns
- Events: Use past tense (UserAuthenticated) or resource+action (WorkOSUserCreated)
- Listeners: Use verb (handle, handleCreated) for methods

**Namespaces:**
- Root: `WorkOS\AuthKit\`
- Features: `WorkOS\AuthKit\{Feature\}` (Auth, Http, Models, Events, etc.)
- Subdirectories: Match directory structure (Http\Controllers, Http\Middleware, etc.)
- PSR-4 autoload: src/ maps to `WorkOS\AuthKit\`

**Method Names:**
- Guard methods: Implement `Illuminate\Contracts\Auth\Guard` (check, guest, user, id, validate, hasUser, setUser)
- Manager methods: `get{Resource}()`, `destroy()`, `has{Feature}()` (e.g., getOrganizationId, hasPermission)
- Controller actions: HTTP verb + action (login, callback, logout, switch, invite, revokeInvitation)
- Listener handlers: `handle()` or `handle{Action}()` (handle, handleCreated, handleUpdated, handleDeleted)

## Where to Add New Code

**New Middleware:**
- Directory: `src/Http/Middleware/`
- Implement: `handle(Request $request, Closure $next): Response`
- Register: Add to WorkOSServiceProvider::configureMiddleware() with router->aliasMiddleware()

**New Event:**
- Directory: `src/Events/` or `src/Events/Webhooks/` for webhook events
- Extend: Use existing events as template; include $data parameter in constructor
- Register: Add listener mapping in WorkOSServiceProvider::configureEventListeners() if webhook

**New Listener:**
- Directory: `src/Listeners/`
- Implement: `handle({EventClass} $event): void` method
- Register: Add Event::listen() call in WorkOSServiceProvider::configureEventListeners()

**New Model Trait:**
- Directory: `src/Models/Concerns/`
- Naming: `Has{Feature}.php` (e.g., HasApiTokens.php)
- Pattern: Use existing traits (HasWorkOSId, HasOrganization) as template
- Include: Docblock `@mixin \Illuminate\Database\Eloquent\Model` for IDE support

**New Command:**
- Directory: `src/Commands/`
- Extend: `Illuminate\Console\Command`
- Register: Add to commands array in WorkOSServiceProvider::configureCommands()

**New Exception:**
- Directory: `src/Exceptions/`
- Extend: WorkOSException or \Exception
- Pattern: Add a new class file; no base exception class enforcement

**New Facade Accessor:**
- File: `src/Facades/WorkOS.php` (add @method docblock)
- Implementation: Add public method to `src/WorkOS.php`
- Pattern: Use existing methods as template; use @method docblocks for IDE autocomplete

## Special Directories

**`database/migrations/`:**
- Purpose: Database schema migrations
- Generated: No; committed to repository
- Auto-loaded: Yes; ServiceProvider::configureMigrations() loads from __DIR__.'/../database/migrations'
- Published: Yes; applications can publish to their database_path('migrations') via php artisan vendor:publish --tag=workos-migrations

**`workbench/`:**
- Purpose: Development and testing sandbox
- Generated: Yes; created by package structure
- Committed: Yes; includes composer.json and Laravel application for testing
- Use: `composer serve` runs workbench app; `composer test:example` runs tests in workbench context

**`tests/`:**
- Purpose: Package unit and feature tests
- Generated: No; committed to repository
- Pattern: Uses Pest PHP; located next to source files conceptually
- Run: `composer test` runs all tests; `composer test:coverage` includes coverage reporting

---

*Structure analysis: 2026-04-06*
