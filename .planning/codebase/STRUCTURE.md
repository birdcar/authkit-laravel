# Codebase Structure

**Analysis Date:** 2026-04-06

## Directory Layout

```
authkit-laravel/
├── src/                          # Main package code
│   ├── Audit/                    # Audit logging system
│   ├── Auth/                     # Authentication and session management
│   ├── Commands/                 # CLI commands
│   ├── Events/                   # Event classes for webhook and lifecycle events
│   ├── Exceptions/               # Custom exception types
│   ├── Facades/                  # Laravel facade for WorkOS
│   ├── Http/                     # HTTP controllers and middleware
│   │   ├── Controllers/          # Route handlers
│   │   └── Middleware/           # Request middleware
│   ├── Install/                  # Installation wizard and configuration
│   ├── Listeners/                # Event listeners for webhook sync
│   ├── Models/                   # Model traits and concerns
│   ├── Support/                  # Utility classes
│   ├── Testing/                  # Testing utilities and fakes
│   ├── WorkOS.php                # Main service coordinator
│   ├── WorkOSServiceProvider.php # Service provider for Laravel
│   └── helpers.php               # Global helper functions
├── routes/                       # HTTP route definitions
│   ├── web.php                   # Auth routes (login, callback, logout)
│   ├── organizations.php         # Organization management routes
│   └── webhooks.php              # Webhook endpoint route
├── config/                       # Package configuration
│   └── workos.php                # WorkOS configuration template
├── database/                     # Migrations
│   └── migrations/               # Database schema migrations
├── tests/                        # Test suite
│   ├── Unit/                     # Unit tests for individual classes
│   └── Fixtures/ (implicit)      # Test data and factories
├── docs/                         # Documentation
├── workbench/                    # Development Laravel app for testing
└── composer.json                 # Package manifest
```

## Directory Purposes

**src/Audit/:**
- Purpose: Audit logging integration with WorkOS Audit Logs API
- Contains: Logger, middleware, contracts, model traits
- Key files: `AuditLogger.php`, `AuditMiddleware.php`

**src/Auth/:**
- Purpose: Authentication system and session management
- Contains: Guard implementation, session manager, session value object
- Key files: `WorkOSGuard.php`, `SessionManager.php`, `WorkOSSession.php`

**src/Commands/:**
- Purpose: CLI commands for installation and maintenance
- Contains: Artisan command definitions
- Key files: `InstallCommand.php`, `SyncUsersCommand.php`, `EventsListenCommand.php`

**src/Events/:**
- Purpose: Event classes for pub/sub system
- Contains: 
  - Application lifecycle events: `UserAuthenticated.php`, `UserLoggedOut.php`, `OrganizationSwitched.php`
  - Webhook-specific events: `Webhooks/` subdirectory with `WorkOSUserCreated.php`, etc.
  - Generic webhook event: `WebhookReceived.php`
- Key files: All events in this directory are event classes

**src/Exceptions/:**
- Purpose: Custom exception types for this package
- Contains: `AuthenticationException.php`, `MissingRoleException.php`, `MissingPermissionException.php`, `WorkOSException.php`

**src/Facades/:**
- Purpose: Laravel facade for convenient access to WorkOS services
- Contains: `WorkOS.php` facade definition
- Used as: `WorkOS::loginUrl()`, `WorkOS::user()`, etc.

**src/Http/Controllers/:**
- Purpose: HTTP request handlers
- Contains:
  - `AuthController.php`: OAuth flow (login, callback, logout)
  - `WebhookController.php`: Webhook endpoint, event dispatching
  - `OrganizationController.php`: Organization switching, invitations
- Routing: Routes defined in `routes/` directory

**src/Http/Middleware/:**
- Purpose: HTTP middleware for request-scoped concerns
- Contains:
  - `EnsureWorkOSAuthenticated.php`: Require valid session
  - `CheckRole.php`: Check user has required role
  - `CheckPermission.php`: Check user has required permission
  - `CheckOrganization.php`: Require membership in organization
  - `SetCurrentOrganization.php`: Load current organization context
  - `DetectImpersonation.php`: Detect admin impersonation
  - `ShareWorkOSData.php`: Share session data with views/Inertia
  - `AuditMiddleware.php`: Capture request context for audit logs

**src/Install/:**
- Purpose: Installation wizard and setup automation
- Contains:
  - `WizardFlow.php`: Orchestrates multi-step installation
  - `AuthSystemInstaller.php`: Configure auth guard and provider
  - `RouteInstaller.php`: Publish built-in routes
  - `WebhookInstaller.php`: Configure webhook endpoint
  - `EnvManager.php`: Read/write .env variables
  - `MigrationPlanGenerator.php`: Analyze existing auth setup and generate migration plan
  - `LaravelWorkosMigrator.php`: Migrate from laravel/workos package
  - `Plans/`: Migration plan definitions for different auth scaffolds (Breeze, Fortify, Jetstream)

**src/Listeners/:**
- Purpose: Event listeners that handle webhook synchronization
- Contains:
  - `SyncUserFromWebhook.php`: Update local user from WorkOS webhook
  - `SyncOrganizationFromWebhook.php`: Update local organization
  - `SyncMembershipFromWebhook.php`: Manage organization memberships
- Trigger: Registered in `WorkOSServiceProvider::configureEventListeners()`

**src/Models/Concerns/:**
- Purpose: Trait-based composition for model enrichment
- Contains:
  - `HasWorkOSId.php`: Store/retrieve WorkOS ID mapping
  - `HasWorkOSPermissions.php`: Role/permission checking, session attachment
  - `HasOrganization.php`: Organization relationship and context
- Usage: Mix into your User/Organization models

**src/Support/:**
- Purpose: Utility and helper classes
- Contains: `EnvironmentDetector.php` (detects existing auth framework), `DetectionResult.php`

**src/Testing/:**
- Purpose: Test support utilities and fakes
- Contains: 
  - `WorkOSFake.php`: Mock WorkOS for testing
  - `Concerns/InteractsWithWorkOS.php`: Test helper trait
- Usage: `WorkOS::fake()` in tests

**routes/:**
- Purpose: HTTP route definitions
- Contains:
  - `web.php`: Auth routes `/auth/login`, `/auth/callback`, `/auth/logout`
  - `organizations.php`: Org routes `/organizations/switch`, invitations
  - `webhooks.php`: Webhook route `/webhooks/workos`
- Registration: Routes loaded conditionally in `WorkOSServiceProvider::configureRoutes()`

**config/:**
- Purpose: Package configuration
- Contains: `workos.php` - template configuration file with all options documented
- Publishing: `php artisan vendor:publish --tag=workos-config`

**database/migrations/:**
- Purpose: Database schema for package tables
- Contains:
  - `create_organizations_table.php`: Organizations table (workos_id, name, slug)
  - `create_organization_memberships_table.php`: Org membership relationships
  - `add_workos_id_to_users_table.php`: Add workos_id column to existing users table
- Registration: Loaded in `WorkOSServiceProvider::configureMigrations()`

**tests/Unit/:**
- Purpose: Unit tests for individual components
- Contains: Test files matching classes in src/ (e.g., `WorkOSSessionTest.php`, `SessionManagerTest.php`)
- Framework: Pest PHP (v3)

## Key File Locations

**Entry Points:**
- `src/WorkOSServiceProvider.php`: Package bootstrap and registration
- `routes/web.php`: OAuth flow entry points
- `routes/webhooks.php`: Webhook endpoint
- `src/Commands/InstallCommand.php`: CLI installation entry point

**Configuration:**
- `config/workos.php`: All package configuration
- `src/WorkOSServiceProvider.php`: Runtime configuration in boot methods

**Core Logic:**
- `src/Auth/SessionManager.php`: Session validation and caching
- `src/Auth/WorkOSGuard.php`: Guard implementation
- `src/Http/Controllers/AuthController.php`: OAuth flow logic
- `src/Http/Controllers/WebhookController.php`: Webhook validation and dispatch
- `src/WorkOS.php`: Service coordinator

**Model Integration:**
- `src/Models/Concerns/HasWorkOSPermissions.php`: Add to User model
- `src/Models/Concerns/HasWorkOSId.php`: Add to User model
- `src/Models/Concerns/HasOrganization.php`: Add to User/Organization models

**Testing:**
- `src/Testing/WorkOSFake.php`: Faking in tests
- `src/Testing/Concerns/InteractsWithWorkOS.php`: Test helper trait

## Naming Conventions

**Files:**
- Classes: PascalCase matching class name (e.g., `WorkOSGuard.php` contains `WorkOSGuard` class)
- Traits: Prefix with `Has` for ability traits (e.g., `HasWorkOSPermissions.php`)
- Contracts/Interfaces: Named with `Contract` or `Interface` suffix where not following standard (e.g., `Auditable.php`)
- Commands: Suffix with `Command` (e.g., `InstallCommand.php`)

**Directories:**
- Controllers: Plural `Controllers/`
- Middleware: Plural `Middleware/`
- Events: Plural `Events/`
- Listeners: Plural `Listeners/`
- Models: Plural `Models/`
- Concerns: Plural `Concerns/` (traits directory)
- Commands: Plural `Commands/`
- Facades: Plural `Facades/`

**Classes:**
- Service classes: Noun-based (e.g., `SessionManager`, `AuditLogger`)
- Guard: `WorkOSGuard`
- Controllers: Suffix with `Controller`
- Middleware: `PascalCase` (e.g., `EnsureWorkOSAuthenticated`)
- Events: `PascalCase` with namespace indicating scope (e.g., `WorkOS\AuthKit\Events\UserAuthenticated`)
- Traits: Prefix with `Has` (e.g., `HasWorkOSPermissions`)

**Methods:**
- Boolean checks: Prefix with `has`, `is`, `can` (e.g., `hasWorkOSRole()`, `isImpersonating()`)
- Setters: Prefix with `set` (e.g., `setWorkOSSession()`)
- Getters: Prefix with `get` (e.g., `getLogoutUrl()`)

**Constants:**
- Config keys: snake_case (e.g., `WORKOS_API_KEY`)
- Event type map keys: snake_case dot notation (e.g., `'user.created'`, `'organization.updated'`)

## Where to Add New Code

**New Authentication Feature:**
- Primary code: `src/Auth/` - Add new class or extend existing
- Middleware: `src/Http/Middleware/` - Add new middleware if needed
- Tests: `tests/Unit/` - Match structure to src/

**New Webhook Event:**
- Event class: `src/Events/Webhooks/WorkOS[Entity][Action].php` (e.g., `WorkOSUserCreated.php`)
- Listener: `src/Listeners/Sync[Entity]FromWebhook.php` (e.g., `SyncUserFromWebhook.php`)
- Event mapping: Update `WebhookController::EVENT_MAP`
- Registration: Register listener in `WorkOSServiceProvider::configureEventListeners()`

**New Middleware:**
- File: `src/Http/Middleware/[YourMiddleware].php`
- Registration: Add alias in `WorkOSServiceProvider::configureMiddleware()`
- Usage: Apply via route groups or controller

**New CLI Command:**
- File: `src/Commands/[YourCommand].php`
- Registration: Add to `WorkOSServiceProvider::configureCommands()`

**New Model Trait:**
- File: `src/Models/Concerns/Has[Capability].php`
- Pattern: Use trait-based composition like existing `HasWorkOSPermissions.php`
- Documentation: Include usage example in docblock

**Utilities:**
- Location: `src/Support/` for shared utility classes
- Pattern: Keep utilities stateless and focused

**Testing Utilities:**
- Location: `src/Testing/` and `src/Testing/Concerns/`
- Pattern: Mirror production structure but prefixed with test concerns

## Special Directories

**workbench/:**
- Purpose: Development Laravel application for testing the package
- Generated: Yes, created by Orchestral Testbench
- Committed: Partially (composer.json and some config)
- Usage: `composer serve` spins up dev server with workbench app

**vendor/:**
- Purpose: Composer dependencies
- Generated: Yes
- Committed: No (in .gitignore)

**.planning/:**
- Purpose: GSD planning and analysis documents
- Generated: Yes (by analysis tools)
- Committed: Yes (part of GSD workflow)

**docs/:**
- Purpose: Package documentation and guides
- Generated: No (manually maintained)
- Committed: Yes

---

*Structure analysis: 2026-04-06*
