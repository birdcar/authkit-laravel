# Coding Conventions

**Analysis Date:** 2026-04-06

## Naming Patterns

**Files:**
- Classes: `PascalCase` (e.g., `SessionManager.php`, `EnsureWorkOSAuthenticated.php`)
- Traits: `PascalCase` with `Concerns/` subdirectory (e.g., `HasWorkOSId.php`, `HasOrganization.php`)
- Tests: `{SubjectName}Test.php` (e.g., `WorkOSSessionTest.php`, `AuditLoggerTest.php`)
- Test directories: `Unit/`, `Feature/`, `Fixtures/`, `Helpers/`

**Functions:**
- Methods: `camelCase` (e.g., `getSession()`, `hasPermission()`, `getAuthIdentifier()`)
- Public methods: expose behavior, not implementation (e.g., `getValidSession()` not `getSessionIfValid()`)
- Private methods: prefixed with underscore is not used; use private visibility keyword instead
- Helper functions: `camelCase` (e.g., `workos()` in `helpers.php`)

**Variables:**
- Local variables: `camelCase` (e.g., `$cachedSession`, `$cookieSession`, `$accessToken`)
- Properties (public/private): `camelCase` with visibility keywords (e.g., `private readonly string $cookieName`)
- Database columns: `snake_case` in migrations (e.g., `workos_id`, `organization_id`)

**Types:**
- Classes: `PascalCase` (e.g., `WorkOSSession`, `SessionManager`)
- Namespaces: `PascalCase` with `\` separator (e.g., `WorkOS\AuthKit\Auth\SessionManager`)
- Interfaces: `PascalCase`, often with `-able` suffix (e.g., `Auditable` contract in `Audit/Contracts/`)
- Type hints: always use full qualified names or imported classes

## Code Style

**Formatting:**
- Tool: Laravel Pint (PSR-12)
- Preset: `laravel` (from `pint.json`)
- Key setting: `declare_strict_types: true` is enforced at file top

**Declaration:**
- Every PHP file begins with:
  ```php
  <?php
  
  declare(strict_types=1);
  
  namespace [namespace];
  ```
- Example: `src/WorkOSServiceProvider.php:1-5`

**Linting:**
- Tool: PHPStan
- Level: 8 (strict, enables all features)
- Config: `analyse` composer script runs against `src/` directory only
- Run: `composer analyse`

**Visibility Keywords:**
- All properties/methods explicitly declare `public`, `protected`, or `private`
- Readonly properties used extensively for immutability (e.g., `private readonly string $cookieName`)
- Constructor property promotion used for dependency injection (e.g., `SessionManager.__construct()`)

## Import Organization

**Order:**
1. Root imports from current package (`WorkOS\AuthKit\...`)
2. Illuminate framework imports (`Illuminate\...`)
3. External vendor imports (WorkOS SDK, Carbon)
4. PHP built-ins/standard functions (`\Exception`, `\stdClass`)

**Example from `src/WorkOSServiceProvider.php:7-46`:**
- `Illuminate` imports grouped first (Auth, Blade, Event, Route, ServiceProvider)
- AuthKit internal imports follow (Audit, Auth, Commands, Events, Http, Install, Listeners, Support)

**Path Aliases:**
- PSR-4 autoload defined in `composer.json`:
  - `WorkOS\AuthKit\` maps to `src/`
  - `WorkOS\AuthKit\Tests\` maps to `tests/`
- No short aliases used; imports use full namespaces

## Error Handling

**Patterns:**
- Silent exception catching used for non-critical failures (e.g., `catch (\Exception) { return null; }`)
- Found in `src/Auth/SessionManager.php:46-48` — session retrieval gracefully returns null instead of throwing
- Custom exceptions thrown for authorization failures (e.g., `MissingRoleException` in middleware)
- No generic catches on controllers; specific exception types preferred when possible

**Return Nullability:**
- Nullable return types used (`?Type`) when operation may fail (e.g., `?WorkOSSession`)
- Callers must check `if (! $session) { ... }` or use `?->` operator
- Example: `getSession(): ?WorkOSSession` in `SessionManager`

## Logging

**Framework:**
- No centralized logging tool imported; commands use Illuminate console output
- Installation/setup commands use `$command->info()`, `$command->line()` for user feedback
- Example: `src/Commands/InstallCommand.php:43-44` uses `$this->components->info()`

**Patterns:**
- Audit logging through `AuditLogger` class for application events (e.g., `user.login`)
- Humanizes action names: `user.login` → `User login`
- Logs sent to WorkOS API via `WorkOS\AuditLogs`, not local files
- See `src/Audit/AuditLogger.php` for implementation

## Comments

**When to Comment:**
- Comments explain the "why" for non-obvious behavior
- Example from `src/WorkOSServiceProvider.php:136-140`:
  ```php
  /**
   * Exclude the WorkOS session cookie from Laravel's cookie encryption.
   *
   * The wos-session cookie is already encrypted using WorkOS's Halite-based
   * encryption, so Laravel's EncryptCookies middleware must not double-encrypt it.
   */
  ```
- Comments sparse elsewhere; code should be self-documenting through clear names

**JSDoc/DocBlock:**
- Used for public methods with complex parameters or returns
- Example from `src/Auth/SessionManager.php:66-71`:
  ```php
  /**
   * Seal and store the session cookie after authentication.
   *
   * @param  array<string, mixed>  $authResponse
   */
  public function store(array $authResponse): WorkOSSession
  ```
- Type hints in array parameters: `@param array<string, mixed>`
- Impersonator type hints: `@return array<string, mixed>|null`

## Function Design

**Size:**
- Methods typically 20-50 lines; longer methods broken into private helpers
- Example: `SessionManager.getSession()` delegates to private methods like `getCookieSession()` and `buildWorkOSSession()`

**Parameters:**
- Constructor promotion preferred for dependencies (e.g., `SessionManager.__construct(private readonly string $cookiePassword)`)
- Method parameters use type hints (e.g., `handle(Request $request, Closure $next, ?string $redirectTo = null)`)
- Named arguments used in calls for clarity (e.g., `loginUrl(organizationId: $id, state: $state)`)

**Return Values:**
- Methods return specific types or nullable types: `WorkOSSession`, `?WorkOSSession`, `RedirectResponse`, `int`
- Early returns preferred to reduce nesting (e.g., `if (! $session) { return null; }`)
- Void return type when method has side effects only

## Module Design

**Exports:**
- Facade pattern used for public API (e.g., `Facades\WorkOS`)
- Service provider registers singletons in container
- No barrel exports (no `index.php` files re-exporting from subdirectories)

**Concerns/Traits:**
- Traits for shared model behavior: `HasWorkOSId`, `HasWorkOSPermissions`, `HasOrganization`
- Located in `src/Models/Concerns/` directory
- Traits initialize via `initialize{TraitName}()` hook (e.g., `initializeHasWorkOSId()`)

**Static Methods:**
- Used for factories: `WorkOSSession::fromAuthResponse()`, `User::findByWorkOSId()`
- Named with `from`, `find`, `findOrCreate` prefix for clarity

---

*Convention analysis: 2026-04-06*
