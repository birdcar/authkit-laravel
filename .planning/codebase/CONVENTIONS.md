# Coding Conventions

**Analysis Date:** 2026-04-06

## Naming Patterns

**Files:**
- PascalCase for class files: `WorkOSGuard.php`, `SessionManager.php`, `WizardFlow.php`
- camelCase for functions/utilities: `helpers.php`
- Interfaces use `Interface` suffix: `ComponentInstaller`, `Auditable`
- Factories use `Factory` suffix: `DetectionResultFactory.php`, `WorkOSSessionFactory.php`
- Trait files use `Concerns` subdirectory: `src/Models/Concerns/HasWorkOSId.php`
- Test files use the same class name with `Test` suffix: `WorkOSGuardTest.php`

**Functions/Methods:**
- camelCase for all functions and methods: `getSession()`, `setUser()`, `hasPermission()`
- Private methods use lowercase camelCase: `getCookieSession()`, `buildWorkOSSession()`, `normalizeTargets()`
- Static factory methods named descriptively: `fresh()`, `freshInstall()`, `withBreeze()`, `admin()`, `impersonating()`
- Methods that check state use `is*()` or `has*()` pattern: `check()`, `guest()`, `hasUser()`, `isExpired()`, `isImpersonating()`
- Methods for retrieval use `get*()` pattern: `getSession()`, `getOrganizationId()`, `getLogoutUrl()`
- Methods for modification use `set*()` or declarative names: `setUser()`, `setWorkOSSession()`, `store()`, `destroy()`

**Variables:**
- camelCase for all variable names: `$cookiePassword`, `$userId`, `$cachedSession`
- Boolean variables use `is*`, `has*`, `can*` prefixes: `$isExpired`, `$hasUser`, `$authenticated`
- Private properties use `?Type` for nullable: `private ?Authenticatable $user`
- Array properties explicitly typed in comments: `/** @var array<string> */`

**Types:**
- PascalCase for class names: `WorkOSGuard`, `SessionManager`, `WorkOSException`
- PascalCase for enum names (if used)
- Type unions use `|` syntax: `?string`, `array<string>`
- Complex types documented in docblock: `@param array<string, mixed> $data`

**Constants:**
- SCREAMING_SNAKE_CASE: Following Laravel conventions, app-level config constants
- Cookie name constant: `wos-session` (using kebab-case for cookie names per HTTP standard)

## Code Style

**Formatting:**
- Tool: Laravel Pint (config: `pint.json`)
- Preset: `laravel` with strict types enforcement
- Key rules:
  - Strict types required (enforced via `declare(strict_types=1)` on every file)
  - Single blank line between methods
  - Trailing comma in multiline arrays/argument lists
  - 4-space indentation
  - 120-character line length
  - Space after control structures: `if (...)`, `foreach (...)`

**Linting:**
- Tool: PHPStan
- Config: `phpstan.neon`
- Level: 8 (bleeding edge configuration)
- Paths analyzed: `src/` (excluding `Traits`, `Models/Concerns`, `Audit/Concerns`, `Testing/Concerns`)
- Enforces strict typing and null safety

## Import Organization

**Order:**
1. `declare(strict_types=1);` statement (always first)
2. Blank line
3. `namespace` declaration
4. Blank line
5. `use` statements organized by:
   - Illuminate (Laravel framework) imports first
   - External packages (WorkOS SDK, Carbon)
   - Internal package imports last
   - All alphabetically ordered within each group

**Example from `SessionManager.php`:**
```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Support\Facades\Cookie;
use WorkOS\AuthKit\Facades\WorkOS;
use WorkOS\CookieSession;
use WorkOS\Resource\Impersonator;
use WorkOS\Resource\SessionAuthenticationSuccessResponse;
use WorkOS\Session\HaliteSessionEncryption;
```

**Path Aliases:**
- No custom path aliases used; fully qualified namespaces throughout
- Namespace root: `WorkOS\AuthKit\`
- Organized by domain: `Install`, `Auth`, `Models`, `Audit`, `Testing`, `Exceptions`, `Support`, `Facades`

## Error Handling

**Patterns:**
- Use typed exceptions extending base `WorkOSException`
- Named constructors for common error scenarios:
  ```php
  public static function sessionExpired(): self
  public static function invalidCallback(): self
  public static function userNotFound(string $workosId): self
  ```
- Exceptions in `src/Exceptions/` with domain-specific subclasses
- Try-catch blocks used for external API calls (WorkOS SDK): catch with generic `\Exception`, log via `report()`
- Null-coalescing (`??`) for optional values: `$value ?? null`
- Optional returns indicated with `?Type`: `?WorkOSSession`, `?string`

**Null Safety Pattern:**
- Use early returns for null checks:
  ```php
  if ($value === null) {
      return null;
  }
  ```
- Use null-coalescing for chained access: `$session?->userId ?? null`
- Never assume values; always check first

## Logging

**Framework:** 
- Laravel's facade-based logging via `report()` function
- Used only for exceptions in try-catch blocks

**Patterns:**
- Errors caught from external APIs logged via `report($e)`
- No debug/info logging throughout codebase
- Audit logging handled separately via `AuditLogger` class for domain events

## Comments

**When to Comment:**
- No explanatory comments for obvious code
- Comments used for **why**, not **what**
- Comments on public methods/APIs via PHPDoc docblocks

**JSDoc/TSDoc Pattern:**
- PHP uses standard PHPDoc format
- Applied to public methods and properties
- Type hints in docblocks for complex types:
  ```php
  /**
   * @param  array<int, Auditable|array{type?: string, id?: string|int, name?: string|null, metadata?: array<string, mixed>|null}>  $targets
   * @param  array<string, mixed>  $metadata
   */
  public function log(
      string $action,
      array $targets = [],
      ?string $actorId = null,
      array $metadata = [],
  ): void
  ```
- Docblocks document contract, not implementation
- Return types always specified in method signature AND docblock for complex returns

## Function Design

**Size:** 
- Methods kept under 40 lines; longer methods broken into private helper methods
- Private methods named descriptively: `buildWorkOSSession()`, `normalizeTargets()`, `attemptRefresh()`
- Example: `AuditLogger::log()` delegates to 3 private methods: `normalizeTargets()`, `humanize()`, `getActorId()`

**Parameters:**
- Constructor injection preferred: `public function __construct(private readonly Type $property) {}`
- Maximum 4-5 parameters; use objects for larger parameter sets
- Use named arguments for optional parameters: `create(organizationId: 'org_123')`
- Default values used for optional parameters

**Return Values:**
- Always explicitly typed: `public function check(): bool`
- Use `?Type` for optional returns: `public function getSession(): ?WorkOSSession`
- Methods ending in `()` return values
- Methods ending in `d()` return void (actions): `destroy()`, `store()`
- Chain-able methods return `static`: `public function setUser(Authenticatable $user): static`

## Module Design

**Exports:**
- No barrel files (index.php) used
- Full qualified namespace imports required
- Single responsibility: each class has one reason to change
- `interface` files used for contracts (`ComponentInstaller`, `Auditable`)

**Architectural Layers:**
- `Auth/` - Authentication and session management
- `Models/Concerns/` - User/Organization model traits
- `Install/` - Installation commands and wizards
- `Testing/` - Testing utilities and fixtures
- `Audit/` - Audit logging functionality
- `Exceptions/` - Exception hierarchy
- `Support/` - Utility classes (`DetectionResult`, `EnvironmentDetector`)
- `Facades/` - Laravel service container facades

**Class Organization:**
- Properties declared at top with visibility modifiers and readonly where applicable
- Constructor with dependency injection
- Public methods documented
- Private methods grouped at bottom
- One public method per file (interfaces) or related group (concrete classes)

---

*Convention analysis: 2026-04-06*
