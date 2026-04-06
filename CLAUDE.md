<!-- GSD:project-start source:PROJECT.md -->
## Project

**AuthKit Laravel**

A Laravel package (`birdcar/authkit-laravel`) that provides drop-in WorkOS AuthKit integration for Laravel applications. It wraps the WorkOS PHP SDK with Laravel-native patterns — guards, middleware, traits, facades, blade directives, and artisan commands — so developers can have working AuthKit authentication within 15 minutes.

**Core Value:** Laravel developers can install this package and have production-ready WorkOS AuthKit authentication without manually wiring up guards, sessions, middleware, or authorization logic.

### Constraints

- **PHP version**: ^8.3 — enforced via composer.json
- **Laravel version**: ^11.0|^12.0 — must support both
- **WorkOS SDK**: ^4.29 — must align with SDK's session management approach
- **Testing**: PHPStan level 8, Pest, Laravel Pint — all must pass before any release
- **Backwards compatibility**: Package is pre-1.0, but existing workbench app must keep working
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP 8.3+ - Core language for all server-side logic in the Laravel package and workbench application
- JavaScript/TypeScript - Frontend tooling and build configuration (Vite-based)
- SQL - Database queries (via Laravel Eloquent ORM)
## Runtime
- PHP 8.3 minimum (enforced via `composer.json` constraint `php: ^8.3`)
- Laravel 11+ and 12+ (via `illuminate/contracts` and `illuminate/support` packages)
- Composer - PHP dependency management
- Lockfile: `composer.lock` present (both in root and workbench)
- npm - Node.js package management for frontend tooling
- Lockfile: `package-lock.json` (in workbench, not committed)
## Frameworks
- Laravel Framework 11+/12+ - Web application framework
- Laravel Tinker ^2.10.1 - Interactive shell for workbench (dev only)
- Livewire ^4.0 - Reactive Laravel components
- Flux/Flux Pro ^2.11 - Headless UI component system
- Tailwind CSS ^4.1.18 - Utility-first CSS framework
- Vite ^7.0.7 - Frontend build tool and development server
- Laravel Vite Plugin ^2.0.0 - Vite integration for Laravel
- Tailwind CSS Vite Plugin ^4.1.18 - Tailwind integration with Vite
- Pest PHP ^3.0 (main library) / ^4.0 (workbench) - Testing framework built on PHPUnit
- Pest Plugin Browser ^4.0 - Browser testing for Pest (workbench)
- Pest Plugin Laravel ^4.0 - Laravel testing utilities for Pest (workbench)
- PHPUnit ^12.0 - Test runner (workbench)
- Mockery ^1.6 - Mocking library for tests
- Laravel Pint ^1.0 - Code style fixer for Laravel
- PHPStan ^1.0 - Static analysis tool (level 8)
- Laravel Pail ^1.2.2 - Real-time log monitoring (dev only)
- Laravel Sail ^1.41 - Docker environment setup (dev only)
- Concurrently ^9.0.1 - Run multiple npm scripts concurrently
- Faker PHP ^1.23 - Generates fake data for testing
## Key Dependencies
- workos/workos-php ^4.29 - Official WorkOS PHP SDK
- workos/authkit-laravel @dev - The package itself (symlinked in workbench via path repository)
- guzzlehttp/guzzle - HTTP client (dependency of workos-php)
- guzzlehttp/promises - Promise handling (dependency of workos-php)
- guzzlehttp/psr7 - PSR-7 HTTP message implementation
- illuminate/contracts ^11.0|^12.0 - Interface contracts for Laravel
- illuminate/support ^11.0|^12.0 - Support utilities and helpers
- orchestra/testbench ^9.0|^10.0 - Testing infrastructure for Laravel packages
## Configuration
- `config/workos.php` - Main configuration file for WorkOS integration
- `.env` file (not committed) - Environment variables for sensitive values
- Environment variables required:
- `pint.json` - Code style configuration (preset: "laravel", strict type declarations)
- `phpstan.neon` - Static analysis configuration (level 8)
- `vite.config.js` - Frontend build configuration (workbench only)
- SQLite (default for development in workbench)
- Supports MySQL, MariaDB, PostgreSQL, SQL Server via Laravel configuration
- Migrations handled by Laravel migration system
- Location: `database/migrations/`
## Platform Requirements
- PHP 8.3 or higher
- Composer 2.x
- Node.js 16+ (for npm/frontend tooling)
- Git
- PHP 8.3 or higher
- Laravel 11 or 12
- Web server (Apache/Nginx with PHP-FPM)
- Database (MySQL/PostgreSQL/SQLite/MariaDB/SQL Server)
- Optional: Redis for caching (configured but not required)
- Deployed as standalone Laravel application
- Can use any Laravel-compatible hosting (Laravel Forge, Vercel, Heroku, etc.)
- SQLite database (file-based, development only)
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Naming Patterns
- Classes: `PascalCase` (e.g., `SessionManager.php`, `EnsureWorkOSAuthenticated.php`)
- Traits: `PascalCase` with `Concerns/` subdirectory (e.g., `HasWorkOSId.php`, `HasOrganization.php`)
- Tests: `{SubjectName}Test.php` (e.g., `WorkOSSessionTest.php`, `AuditLoggerTest.php`)
- Test directories: `Unit/`, `Feature/`, `Fixtures/`, `Helpers/`
- Methods: `camelCase` (e.g., `getSession()`, `hasPermission()`, `getAuthIdentifier()`)
- Public methods: expose behavior, not implementation (e.g., `getValidSession()` not `getSessionIfValid()`)
- Private methods: prefixed with underscore is not used; use private visibility keyword instead
- Helper functions: `camelCase` (e.g., `workos()` in `helpers.php`)
- Local variables: `camelCase` (e.g., `$cachedSession`, `$cookieSession`, `$accessToken`)
- Properties (public/private): `camelCase` with visibility keywords (e.g., `private readonly string $cookieName`)
- Database columns: `snake_case` in migrations (e.g., `workos_id`, `organization_id`)
- Classes: `PascalCase` (e.g., `WorkOSSession`, `SessionManager`)
- Namespaces: `PascalCase` with `\` separator (e.g., `WorkOS\AuthKit\Auth\SessionManager`)
- Interfaces: `PascalCase`, often with `-able` suffix (e.g., `Auditable` contract in `Audit/Contracts/`)
- Type hints: always use full qualified names or imported classes
## Code Style
- Tool: Laravel Pint (PSR-12)
- Preset: `laravel` (from `pint.json`)
- Key setting: `declare_strict_types: true` is enforced at file top
- Every PHP file begins with:
- Example: `src/WorkOSServiceProvider.php:1-5`
- Tool: PHPStan
- Level: 8 (strict, enables all features)
- Config: `analyse` composer script runs against `src/` directory only
- Run: `composer analyse`
- All properties/methods explicitly declare `public`, `protected`, or `private`
- Readonly properties used extensively for immutability (e.g., `private readonly string $cookieName`)
- Constructor property promotion used for dependency injection (e.g., `SessionManager.__construct()`)
## Import Organization
- `Illuminate` imports grouped first (Auth, Blade, Event, Route, ServiceProvider)
- AuthKit internal imports follow (Audit, Auth, Commands, Events, Http, Install, Listeners, Support)
- PSR-4 autoload defined in `composer.json`:
- No short aliases used; imports use full namespaces
## Error Handling
- Silent exception catching used for non-critical failures (e.g., `catch (\Exception) { return null; }`)
- Found in `src/Auth/SessionManager.php:46-48` — session retrieval gracefully returns null instead of throwing
- Custom exceptions thrown for authorization failures (e.g., `MissingRoleException` in middleware)
- No generic catches on controllers; specific exception types preferred when possible
- Nullable return types used (`?Type`) when operation may fail (e.g., `?WorkOSSession`)
- Callers must check `if (! $session) { ... }` or use `?->` operator
- Example: `getSession(): ?WorkOSSession` in `SessionManager`
## Logging
- No centralized logging tool imported; commands use Illuminate console output
- Installation/setup commands use `$command->info()`, `$command->line()` for user feedback
- Example: `src/Commands/InstallCommand.php:43-44` uses `$this->components->info()`
- Audit logging through `AuditLogger` class for application events (e.g., `user.login`)
- Humanizes action names: `user.login` → `User login`
- Logs sent to WorkOS API via `WorkOS\AuditLogs`, not local files
- See `src/Audit/AuditLogger.php` for implementation
## Comments
- Comments explain the "why" for non-obvious behavior
- Example from `src/WorkOSServiceProvider.php:136-140`:
- Comments sparse elsewhere; code should be self-documenting through clear names
- Used for public methods with complex parameters or returns
- Example from `src/Auth/SessionManager.php:66-71`:
- Type hints in array parameters: `@param array<string, mixed>`
- Impersonator type hints: `@return array<string, mixed>|null`
## Function Design
- Methods typically 20-50 lines; longer methods broken into private helpers
- Example: `SessionManager.getSession()` delegates to private methods like `getCookieSession()` and `buildWorkOSSession()`
- Constructor promotion preferred for dependencies (e.g., `SessionManager.__construct(private readonly string $cookiePassword)`)
- Method parameters use type hints (e.g., `handle(Request $request, Closure $next, ?string $redirectTo = null)`)
- Named arguments used in calls for clarity (e.g., `loginUrl(organizationId: $id, state: $state)`)
- Methods return specific types or nullable types: `WorkOSSession`, `?WorkOSSession`, `RedirectResponse`, `int`
- Early returns preferred to reduce nesting (e.g., `if (! $session) { return null; }`)
- Void return type when method has side effects only
## Module Design
- Facade pattern used for public API (e.g., `Facades\WorkOS`)
- Service provider registers singletons in container
- No barrel exports (no `index.php` files re-exporting from subdirectories)
- Traits for shared model behavior: `HasWorkOSId`, `HasWorkOSPermissions`, `HasOrganization`
- Located in `src/Models/Concerns/` directory
- Traits initialize via `initialize{TraitName}()` hook (e.g., `initializeHasWorkOSId()`)
- Used for factories: `WorkOSSession::fromAuthResponse()`, `User::findByWorkOSId()`
- Named with `from`, `find`, `findOrCreate` prefix for clarity
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## Pattern Overview
- Laravel service provider integration pattern for library bootstrapping
- Guard-based authentication system extending Laravel's built-in auth contracts
- Facade for ergonomic service access to WorkOS PHP SDK
- Event-driven webhook synchronization for external state management
- Cookie-based session management using WorkOS's Halite encryption
## Layers
- Purpose: Provides high-level API to WorkOS SDK functionality with caching
- Location: `src/WorkOS.php`
- Contains: Service method mapping, session management, authentication helpers
- Depends on: SessionManager, AuditLogger, WorkOS PHP SDK
- Used by: Controllers, Listeners, Blade directives, application code via Facade
- Purpose: Implements Laravel Guard contract and manages session lifecycle
- Location: `src/Auth/`
- Contains: WorkOSGuard (guard implementation), SessionManager (cookie+token handling), WorkOSSession (data model)
- Depends on: Laravel auth contracts, WorkOS PHP SDK's CookieSession
- Used by: WorkOSServiceProvider, middleware, controllers
- Purpose: Routes and middleware for handling authentication flows
- Location: `src/Http/`
- Contains: AuthController (login/callback/logout), OrganizationController (org switching/invitations), WebhookController (event ingestion), middleware for authorization checks
- Depends on: Authentication layer, Service layer, Event system
- Used by: Routes registered via ServiceProvider
- Purpose: Decouples webhook processing from database synchronization
- Location: `src/Events/` and `src/Listeners/`
- Contains: Webhook event classes (WorkOSUserCreated, etc.), listeners that sync to local User/Organization models
- Depends on: User/Organization models (via config), event system
- Used by: WebhookController, ServiceProvider event registration
- Purpose: Extend user/organization models with WorkOS-specific functionality
- Location: `src/Models/Concerns/`
- Contains: HasWorkOSId (WorkOS ID lookup), HasWorkOSPermissions (role/permission checks), HasOrganization (multi-org support)
- Depends on: Eloquent models
- Used by: Application User/Organization models via trait inclusion
- Purpose: Interactive wizard and file manipulation for initial setup
- Location: `src/Install/`
- Contains: WizardFlow (orchestrates installation steps), installers for routes/auth/webhooks, migration plan generator for legacy auth system migration
- Depends on: File system, environment detection, console commands
- Used by: InstallCommand console command
- Purpose: HTTP request filtering for authentication and authorization
- Location: `src/Http/Middleware/`
- Contains: EnsureWorkOSAuthenticated (guards routes), CheckRole/CheckPermission (authorization), SetCurrentOrganization (org context), DetectImpersonation (admin mode)
- Depends on: SessionManager, request object
- Used by: Route groups and individual routes
- Purpose: Optional WorkOS Audit Logs API integration
- Location: `src/Audit/`
- Contains: AuditLogger (formats and sends events), AuditMiddleware (captures requests), Auditable contract
- Depends on: WorkOS PHP SDK auditLogs service, SessionManager
- Used by: ApplicationOptionally via WorkOS::audit() or trait inclusion
## Data Flow
- **Primary source of truth:** WorkOS wos-session cookie (sealed, encrypted by Halite)
- **Secondary source:** Local User/Organization models synced via webhooks
- **Ephemeral state:** WorkOSSession object cached per-request in SessionManager
- **Session duration:** 30 days (cookie) with hourly expiry checks; refresh token used for automatic renewal
## Key Abstractions
- Purpose: Immutable readonly representation of authenticated session
- Examples: `src/Auth/WorkOSSession.php`
- Pattern: Value object with factory methods (fromAuthResponse, fromArray), expiry/permission checks
- Properties: userId, accessToken, refreshToken, expiresAt, sessionId, roles, permissions, organizationId, impersonator
- Purpose: Bridge between WorkOS PHP SDK's CookieSession and Laravel's request/response cycle
- Examples: `src/Auth/SessionManager.php`
- Pattern: Manages cache invalidation, token refresh, cookie encryption/decryption
- Key methods: getValidSession(), store(), destroy(), getOrganizationId(), hasPermission(), hasRole()
- Purpose: Implements Laravel's Guard contract for use with auth() helper
- Examples: `src/Auth/WorkOSGuard.php`
- Pattern: Delegates session lookup to SessionManager, user loading to UserProvider
- Integration: Registered in ServiceProvider::configureGuard() as 'workos' guard
- Purpose: Ergonomic static access to service + SDK methods
- Examples: `src/Facades/WorkOS.php`, `src/WorkOS.php`
- Pattern: Exposes high-level auth methods + passes through SDK service methods
- Usage: WorkOS::loginUrl(), WorkOS::storeSession(), WorkOS::userManagement(), etc.
- Purpose: Decouple webhook ingestion from database mutations
- Examples: Event classes in `src/Events/Webhooks/`, listeners in `src/Listeners/`
- Pattern: WebhookController dispatches events → Laravel event dispatcher → specific listeners
- Flexibility: Applications can listen to same events for custom sync logic
## Entry Points
- Location: `src/WorkOS.php` and `src/WorkOSServiceProvider.php`
- Triggers: Framework service provider boot during app initialization
- Responsibilities: Register guard, middleware, commands; configure routes and webhooks; load migrations
- Location: `routes/web.php`
- Triggers: HTTP requests to /auth/* paths
- Responsibilities: GET /auth/login (initiate), GET /auth/callback (handle OAuth callback), GET|POST /auth/logout (terminate)
- Location: `routes/organizations.php`
- Triggers: HTTP requests to /organizations/* with auth:workos middleware
- Responsibilities: POST /organizations/switch (org context change), POST /organizations/{org}/invitations (send), DELETE revoke
- Location: `routes/webhooks.php`
- Triggers: POST /webhooks/workos with WorkOS-Signature header
- Responsibilities: Validate signature, dispatch typed events for user/org/membership changes
- Location: `src/Commands/`
- Triggers: php artisan workos:install, workos:sync-users, workos:listen-events
- Responsibilities: InstallCommand (interactive setup), SyncUsersCommand (backfill users), EventsListenCommand (local testing)
## Error Handling
- **Authentication failures:** AuthController catches exceptions from authenticateWithCode(), returns null, redirects to login with error
- **Session validation:** SessionManager catches exceptions from authenticate()/refresh(), returns null (guest), middleware redirects
- **Webhook validation:** WebhookController catches signature verification exceptions, logs via report(), returns 400
- **Database lookups:** Listeners check for method existence before calling (findByWorkOSId), silently skip if missing
- **Audit logs:** AuditLogger catches exceptions from API call, reports but doesn't propagate
## Cross-Cutting Concerns
- HTTP inputs validated in controllers via Form Request-style validation
- Session state validated in SessionManager (expiry checks, token presence)
- Webhook signature validated via WorkOS::webhook()->constructEvent()
- Implemented as Guard + SessionManager + WorkOSSession value object
- Integrated with Laravel's auth() helper and Authenticatable contract
- Supports custom user providers via config('workos.user_model')
- Role-based via WorkOSSession::hasRole()
- Permission-based via WorkOSSession::hasPermission()
- Blade directives (@workosRole, @workosPermission) for template checks
- Middleware (CheckRole, CheckPermission) for route protection
- Fallback to session permissions; org-specific via HasOrganization trait
- Detected in SessionManager via $session->impersonator property
- Available to app via WorkOS::isImpersonating()
- Blade directive @impersonating for UI/audit purposes
- Tracked in audit logs via AuditLogger
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, or `.github/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
