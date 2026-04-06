# Testing Patterns

**Analysis Date:** 2026-04-06

## Test Framework

**Runner:**
- Pest PHP v3.0 (installed via `pestphp/pest` in `require-dev`)
- Config: `phpunit.xml` defines test suites and source directory for coverage
- Test bootstrap: `vendor/autoload.php` (configured in phpunit.xml)

**Assertion Library:**
- Pest's built-in expectation syntax: `expect($value)->toBe(...)`
- Supports chainable assertions: `expect($var)->toBe('OK')->and($other)->toBeTrue()`

**Run Commands:**
```bash
composer test              # Run all tests via Pest
composer test:coverage     # Run with coverage report, minimum 80% threshold
composer analyse           # PHPStan static analysis at level 8
composer format            # Format code with Pint
composer format:test       # Check formatting without applying changes
composer test:example      # Run tests in workbench example app
```

**CI/Coverage:**
- Minimum coverage required: 80% (enforced in composer script)
- Coverage report generated when running `composer test:coverage`

## Test File Organization

**Location:**
- Tests co-located with test suite directory, NOT alongside source
- `tests/Unit/` for isolated unit tests
- `tests/Feature/` for integration/feature tests
- `tests/Fixtures/` for test doubles and fake objects
- `tests/Helpers/` for test factories and builder utilities

**Naming:**
- Test files: `{Subject}Test.php` (e.g., `WorkOSSessionTest.php`, `AuditLoggerTest.php`)
- Fixtures: descriptive names (e.g., `TestUser.php`, `AuditableModel` defined inline in test)
- Helpers: `{Object}Factory.php` (e.g., `WorkOSSessionFactory.php`)

**Structure:**
```
tests/
├── Pest.php                    # Bootstrap file
├── TestCase.php                # Base test class extending Orchestra\Testbench
├── Unit/                       # Unit tests
│   ├── WorkOSSessionTest.php
│   ├── AuditLoggerTest.php
│   └── ...
├── Feature/                    # Feature/integration tests
│   ├── OrganizationSwitchTest.php
│   └── ...
├── Fixtures/                   # Test doubles
│   └── TestUser.php
└── Helpers/                    # Factories and builders
    ├── WorkOSSessionFactory.php
    └── DetectionResultFactory.php
```

## Test Structure

**Suite Organization:**
- Pest's closure-based syntax without `describe()` blocks
- File-level `beforeEach()` and `afterEach()` hooks for setup/teardown
- Example from `tests/Unit/WorkOSSessionTest.php`:

```php
beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('can be created from array', function () {
    // test implementation
});
```

**Test Names:**
- Use `it('...')` syntax with behavior-driven naming
- Examples: `it('can be created from array')`, `it('detects expired sessions')`, `it('throws exception when user is missing trait')`
- Names read as specification/documentation

**Setup Pattern:**
- `beforeEach()`: Initialize mocks, test data, fixtures
- Direct test file access via `$this->` context available in Pest tests
- Example from `tests/Unit/AuditLoggerTest.php:34-43`:
  ```php
  beforeEach(function () {
      Carbon::setTestNow('2024-01-15 12:00:00');
      $this->auditLogs = Mockery::mock(AuditLogs::class);
      $this->sessionManager = Mockery::mock(SessionManager::class);
  });
  
  afterEach(function () {
      Carbon::setTestNow();
      Mockery::close();
  });
  ```

**Assertion Pattern:**
- Chainable expectations: `expect($var)->toBe('OK')->and($other)->toBeTrue()`
- Multiple assertions for single behavior allowed if related
- Example: `expect($session->userId)->toBe('user_123')->and($session->roles)->toBe(['admin'])`

**Exception Testing:**
- Pest's `->throws()` syntax: `expect(fn () => $code)->throws(ExceptionClass::class, 'message')`
- For complex exception assertions, use try-catch to inspect exception properties:
  ```php
  try {
      $middleware->handle($request, fn () => response('OK'), 'admin', 'editor');
  } catch (MissingRoleException $e) {
      expect($e->getMessage())->toContain('admin')
          ->and($e->roles)->toBe(['admin', 'editor']);
      return;
  }
  ```

## Mocking

**Framework:**
- Mockery for mocking objects (imported as `use Mockery;`)
- Mockery available in test context automatically via Pest

**Patterns:**
- Create mocks in `beforeEach()`: `$this->mock = Mockery::mock(ClassName::class);`
- Setup expectations with `shouldReceive()`: 
  ```php
  $this->sessionManager->shouldReceive('getValidSession')
      ->once()
      ->andReturn($session);
  ```
- Verify with call constraints: `->once()`, `->times(2)`, `->never()`
- Example from `tests/Unit/WorkOSGuardTest.php:31-36`:
  ```php
  $this->sessionManager->shouldReceive('getValidSession')
      ->once()
      ->andReturn(null);
  
  expect($this->guard->user())->toBeNull();
  ```

**What to Mock:**
- External services: `WorkOS\AuditLogs`, `SessionManager` (when testing dependent code)
- Database layer when testing business logic (e.g., `UserProvider`)
- HTTP requests and responses in unit tests

**What NOT to Mock:**
- Model traits and concerns (test with real models or inline implementations)
- Built-in Laravel helpers (use real `Request`, `Route` when possible)
- Actual serialization/transformation logic (test real behavior)

**Mock Closure Inspection:**
- Use `withArgs(function ($arg) { ... })` to inspect call arguments:
  ```php
  $this->auditLogs->shouldReceive('createEvent')
      ->once()
      ->withArgs(function ($orgId, $event) {
          return $orgId === 'org_test_123'
              && $event['action']['type'] === 'user.login';
      });
  ```

## Fixtures and Factories

**Test Data:**

### WorkOSSessionFactory
Located at `tests/Helpers/WorkOSSessionFactory.php`

```php
// Create session with custom parameters
$session = WorkOSSessionFactory::create(
    roles: ['admin'],
    permissions: ['read', 'write'],
    organizationId: 'org_123'
);

// Convenience builders
WorkOSSessionFactory::admin(organizationId: 'org_123');
WorkOSSessionFactory::withRoles(['editor']);
WorkOSSessionFactory::withPermissions(['read']);
WorkOSSessionFactory::impersonating(impersonator: ['email' => 'admin@example.com']);
WorkOSSessionFactory::expired();  // Returns session with past expiresAt
```

### TestUser Fixture
Located at `tests/Fixtures/TestUser.php`

```php
// Minimal user implementing HasWorkOSPermissions
$user = new TestUser;
$user->setWorkOSSession(WorkOSSessionFactory::withRoles(['admin']));
```

**Location:**
- Helpers: `tests/Helpers/{Object}Factory.php`
- Fixtures: `tests/Fixtures/{Name}.php`
- Inline fixtures: Classes defined directly in test file when simple (e.g., `AuditableModel` in `AuditLoggerTest.php`)

## Coverage

**Requirements:**
- Minimum: 80% (enforced via `composer test:coverage` script)
- Source files analyzed: everything in `src/` directory
- Run: `composer test:coverage` to generate report with enforcement

**View Coverage:**
```bash
composer test:coverage  # Generates coverage report (text output)
```

## Test Types

**Unit Tests:**
- Located in `tests/Unit/`
- Test single class/function in isolation
- Mock all dependencies
- Fast, deterministic
- Examples: `WorkOSSessionTest.php` (tests session creation/validation), `AuditLoggerTest.php` (tests logging with mocked API)

**Feature/Integration Tests:**
- Located in `tests/Feature/`
- Test multiple components working together
- Use real database (SQLite in-memory) and routing
- Slower but comprehensive
- Example: `OrganizationSwitchTest.php` — tests full request/response cycle with middleware and controllers

**Test Base Class:**
`tests/TestCase.php` extends `Orchestra\Testbench\TestCase`:
- Provides Laravel integration
- Configures test database (SQLite in-memory)
- Registers `WorkOSServiceProvider` automatically
- Sets test environment variables (`app.key`, `workos.api_key`, etc.)

## Common Patterns

**Async Testing:**
- Laravel/PHP does not use async; tested synchronously
- Closure-based assertions work directly

**Error Testing:**
```php
it('throws exception when user is missing trait', function () {
    $user = new stdClass;
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);
    $middleware = new CheckRole;
    $middleware->handle($request, fn ($req) => response('OK'), 'admin');
})->throws(MissingRoleException::class, 'User model missing HasWorkOSPermissions trait');
```

**Database Testing:**
- Feature tests use `beforeEach()` to create schema:
  ```php
  $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
      $table->id();
      $table->string('workos_id')->nullable()->unique();
      // ... columns
  });
  ```
- Example: `OrganizationSwitchTest.php:56-79`

**HTTP Testing:**
- Use `$this->actingAs($user)` to set authenticated context
- Use `$this->post()`, `$this->get()` for requests
- Assert with `->assertRedirect()`, `->assertOk()`, `->assertForbidden()`, `->assertSessionHasErrors()`
- Example: `OrganizationSwitchTest.php:100-107`

**Request Setup:**
```php
$request = Request::create('/test');
$request->setUserResolver(fn () => $user);
// Pass to middleware/controller for testing
```

---

*Testing analysis: 2026-04-06*
