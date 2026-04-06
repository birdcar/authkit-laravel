# Testing Patterns

**Analysis Date:** 2026-04-06

## Test Framework

**Runner:**
- Pest PHP v3.0+
- Config: `composer.json` with `pestphp/pest` dependency
- Base class: `WorkOS\AuthKit\Tests\TestCase` (extends Orchestra Testbench)

**Assertion Library:**
- Pest's built-in `expect()` API with chainable assertions
- No separate assertion library; uses `expect($value)->toBe()`, `expect($value)->toContain()`, etc.

**Run Commands:**
```bash
vendor/bin/pest              # Run all tests
vendor/bin/pest --watch     # Watch mode
vendor/bin/pest --coverage --min=80  # Coverage report with 80% minimum
```

**Command in composer.json:**
```json
"test": "vendor/bin/pest",
"test:coverage": "vendor/bin/pest --coverage --min=80"
```

## Test File Organization

**Location:**
- Co-located with source: `tests/Unit/` mirrors `src/` structure
- Unit tests for individual classes: `tests/Unit/WorkOSGuardTest.php`
- Feature tests for integration: `tests/Feature/AuthFlowTest.php`
- Helpers for factories: `tests/Helpers/DetectionResultFactory.php`
- Fixtures for test objects: `tests/Fixtures/TestUser.php`

**Naming:**
- Test files use `{ClassName}Test.php` pattern
- Test functions use descriptive names: `it('returns null when no valid session', ...)`
- Organized by concern: `Unit/` for isolated, `Feature/` for integrated

**Test Suites (from phpunit.xml):**
```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory>tests/Feature</directory>
    </testsuite>
</testsuites>
```

## Test Structure

**Suite Organization:**
Pest uses function-based syntax without classes. Setup is shared via `beforeEach()` hooks.

```php
<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Contracts\Auth\UserProvider;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSGuard;

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
    $this->userProvider = Mockery::mock(UserProvider::class);
    $this->sessionManager = Mockery::mock(SessionManager::class);
    $this->request = Request::create('/');

    $this->guard = new WorkOSGuard(
        $this->userProvider,
        $this->sessionManager,
        $this->request
    );
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('returns null when no valid session', function () {
    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn(null);

    expect($this->guard->user())->toBeNull();
});
```

**Patterns:**
- `beforeEach()` for test setup (DI, mocking)
- `afterEach()` for cleanup (reset time, close mocks)
- `it('descriptive name', function () { ... })` for individual tests
- Test method properties stored on `$this` for reuse
- Each test function is isolated with fresh setup

## Mocking

**Framework:** Mockery (Laravel's built-in mocking library)

**Patterns:**

```php
// Mock a contract
$mock = Mockery::mock(UserProvider::class);

// Set expectations
$mock->shouldReceive('retrieveById')
    ->with('user_123')
    ->once()
    ->andReturn($user);

// Assert expectations verified at end of test (afterEach via Mockery::close())

// Conditional stubbing
$mock->shouldReceive('getValidSession')
    ->andReturn(null);  // Always returns null

// Multiple return values in sequence
$mock->shouldReceive('call')
    ->once()->andReturn('first')
    ->once()->andReturn('second');
```

**What to Mock:**
- External service clients: `WorkOS\AuditLogs`, `UserProvider`
- Request/HTTP objects for isolated testing
- Service layer dependencies
- Facades accessed via `Mockery::mock()`

**What NOT to Mock:**
- Value objects: `WorkOSSession`, `DetectionResult`
- Simple data structures
- Application config accessed via `config()`
- Traits applied to test objects (test the trait behavior)
- Laravel's real facades when possible (use `->spy()` instead of mocking)

## Fixtures and Factories

**Test Data:**
Pest uses factory classes for building test objects. No builder/fixture pattern files; factories are simple static methods.

```php
// src/Tests/Helpers/WorkOSSessionFactory.php
final class WorkOSSessionFactory
{
    public static function create(
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
        ?array $impersonator = null,
        string $userId = 'user_123',
        string $accessToken = 'token_abc',
        ?string $refreshToken = null,
        ?Carbon $expiresAt = null,
        string $sessionId = 'session_456',
    ): WorkOSSession {
        return new WorkOSSession(
            userId: $userId,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt ?? Carbon::now()->addHour(),
            sessionId: $sessionId,
            roles: $roles,
            permissions: $permissions,
            organizationId: $organizationId,
            impersonator: $impersonator,
        );
    }

    public static function admin(?string $organizationId = null): WorkOSSession
    {
        return self::create(roles: ['admin'], organizationId: $organizationId);
    }

    public static function withRoles(array $roles, ?string $organizationId = null): WorkOSSession
    {
        return self::create(roles: $roles, organizationId: $organizationId);
    }

    public static function impersonating(array $impersonator = ['email' => 'admin@example.com']): WorkOSSession
    {
        return self::create(impersonator: $impersonator);
    }

    public static function expired(): WorkOSSession
    {
        return self::create(expiresAt: Carbon::now()->subHour());
    }
}
```

**Location:**
- Factories: `tests/Helpers/{EntityType}Factory.php`
- Fixtures: `tests/Fixtures/{EntityType}.php` (test objects implementing interfaces)
- Both in dedicated directories; not mixed with test files

**Usage in Tests:**
```php
it('checks for role', function () {
    $session = WorkOSSessionFactory::withRoles(['admin']);
    expect($session->hasRole('admin'))->toBeTrue();
});

it('handles expired session', function () {
    $session = WorkOSSessionFactory::expired();
    expect($session->isExpired())->toBeTrue();
});
```

## Coverage

**Requirements:**
- Minimum 80% code coverage enforced via `--min=80` flag
- Coverage source: `src/` directory (see phpunit.xml)

**View Coverage:**
```bash
vendor/bin/pest --coverage
```

**Files not analyzed:**
- `src/Traits/` - Trait concerns
- `src/Models/Concerns/` - Model trait concerns  
- `src/Audit/Concerns/` - Audit trait concerns
- `src/Testing/Concerns/` - Testing trait concerns
(See `phpstan.neon` exclusion paths)

## Test Types

**Unit Tests:**
- Path: `tests/Unit/`
- Scope: Single class in isolation
- Dependencies: Mocked via Mockery
- Example: `WorkOSGuardTest.php` tests guard behavior with mocked SessionManager
- Run fast, deterministic, no framework overhead

**Integration Tests:**
- Path: `tests/Feature/`
- Scope: Multiple classes working together
- Dependencies: Partially mocked (external APIs), real Laravel components
- Example: `AuthFlowTest.php` tests full authentication flow with real routes
- Use TestCase with setUp/tearDown hooks: `uses()->group('serial')` for file system tests
- May access Laravel services, database, filesystem

**End-to-End Tests:**
- Not explicitly used; feature tests serve E2E purpose
- Feature tests inherit from `TestCase` (Orchestra Testbench)
- Can make HTTP requests via `$this->get()`, `$this->post()`
- Can make Artisan commands via `$this->artisan()`

## Common Patterns

**Async Testing:**
Not relevant for Laravel/PHP synchronous architecture; no async/await patterns.

**Error Testing:**
```php
it('throws exception for invalid callback', function () {
    $response = $this->get('/auth/callback'); // No code parameter

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error');
});
```

**Testing State Transitions:**
```php
it('caches user lookup', function () {
    $session = new WorkOSSession(...);
    
    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn($session);

    $this->guard->user();  // First call
    $result = $this->guard->user();  // Second call (should use cache)

    expect($result)->toBe($user);
    // shouldReceive()->once() verifies provider called only once
});
```

**Testing with Objects Implementing Interfaces:**
```php
it('attaches session to user when trait present', function () {
    $user = new class implements Authenticatable
    {
        public ?WorkOSSession $workosSession = null;

        public function setWorkOSSession(WorkOSSession $session): void
        {
            $this->workosSession = $session;
        }
        // ... required Authenticatable methods
    };

    $this->guard->user();
    expect($user->workosSession)->toBe($session);
});
```

**Testing Trait Behavior:**
```php
it('checks for single role', function () {
    $user = new TestUser;  // Fixture implementing traits
    $user->setWorkOSSession(WorkOSSessionFactory::withRoles(['admin', 'editor']));

    expect($user->hasWorkOSRole('admin'))->toBeTrue()
        ->and($user->hasWorkOSRole('viewer'))->toBeFalse();
});
```

**Testing with Factories:**
```php
it('normalizes auditable model targets', function () {
    config(['workos.features.audit_logs' => true]);
    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');

    $model = new AuditableModel;

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->withArgs(function ($orgId, $event) {
            return count($event['targets']) === 1
                && $event['targets'][0]['type'] === 'auditablemodel'
                && $event['targets'][0]['id'] === '42';
        });

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('resource.update', targets: [$model]);
});
```

**Testing Laravel Commands:**
```php
it('displays fresh install message when no existing auth', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('No existing auth setup detected')
        ->assertExitCode(0);
});
```

**Testing with Filesystem:**
```php
beforeEach(function () {
    if (File::exists(config_path('workos.php'))) {
        File::delete(config_path('workos.php'));
    }
});

afterEach(function () {
    if (File::exists(config_path('workos.php'))) {
        File::delete(config_path('workos.php'));
    }
});

it('publishes config file', function () {
    $this->artisan('workos:install --mini');
    expect(File::exists(config_path('workos.php')))->toBeTrue();
});
```

**Serial Test Group:**
```php
// Mark tests that modify shared state (filesystem, config) as serial
uses()->group('serial');

// Prevents parallel execution of these tests
```

**Time Testing:**
```php
beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();  // Reset to real time
});

it('detects expired session', function () {
    $session = WorkOSSessionFactory::expired();
    expect($session->isExpired())->toBeTrue();
});
```

---

*Testing analysis: 2026-04-06*
