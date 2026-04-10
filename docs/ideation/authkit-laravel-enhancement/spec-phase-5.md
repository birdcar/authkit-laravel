# Implementation Spec: AuthKit Laravel Enhancement - Phase 5

**PRD**: ./prd-phase-5.md
**Estimated Effort**: M (Medium)

## Technical Approach

This phase completes the project by adding comprehensive documentation, basic tests for the example app, UI polish, and final cleanup. The README goes in `.github/README.md` which GitHub displays as the repository README.

Key technical decisions:
1. Place README at `.github/README.md` per GitHub conventions
2. Delete any root `README.md` to avoid confusion
3. Add Pest tests for the example app demonstrating WorkOS::actingAs
4. Use badge from CI workflow for status display
5. Polish UI with consistent loading states and error handling

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `.github/README.md` | Comprehensive package documentation |
| `workbench/tests/Feature/AuthTest.php` | Authentication flow tests |
| `workbench/tests/Feature/TodoTest.php` | Todo CRUD tests |
| `workbench/tests/Feature/OrganizationTest.php` | Organization switching tests |
| `workbench/tests/TestCase.php` | Base test case with helpers |
| `workbench/phpunit.xml` | PHPUnit/Pest configuration |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `composer.json` | Ensure test:example script is correct |
| Various Livewire views | Add loading states, polish |

### Deleted Files

| File Path | Reason |
|-----------|--------|
| `README.md` (if exists) | Replaced by .github/README.md |

## Implementation Details

### README Documentation

**.github/README.md**:
```markdown
# AuthKit Laravel

[![CI](https://github.com/workos/authkit-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/workos/authkit-laravel/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/workos/authkit-laravel.svg)](https://packagist.org/packages/workos/authkit-laravel)
[![License](https://img.shields.io/packagist/l/workos/authkit-laravel.svg)](https://packagist.org/packages/workos/authkit-laravel)

Laravel integration for [WorkOS AuthKit](https://workos.com/authkit) - add enterprise-grade authentication to your Laravel application in minutes.

## Features

- 🔐 **AuthKit Authentication** - SSO, MFA, social login via WorkOS
- 🏢 **Multi-tenant Organizations** - Built-in organization support with role-based access
- 📝 **Audit Logging** - Track user actions with WorkOS Audit Logs
- 🔄 **Webhook Sync** - Automatic user/org sync from WorkOS webhooks
- 🎭 **Impersonation** - Support user impersonation with visual indicators
- 🧪 **Testing Utilities** - Easy testing with `WorkOS::actingAs()`

## Requirements

- PHP 8.2 or higher
- Laravel 10, 11, or 12
- [WorkOS account](https://dashboard.workos.com/)

## Installation

Install via Composer:

```bash
composer require workos/authkit-laravel
```

Run the installation command:

```bash
php artisan workos:install
```

This will:
- Publish the configuration file
- Add WorkOS environment variables to `.env`
- Run migrations for user/organization tables

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
WORKOS_API_KEY=sk_test_your_api_key
WORKOS_CLIENT_ID=client_your_client_id
WORKOS_REDIRECT_URI=http://localhost:8000/auth/callback
WORKOS_WEBHOOK_SECRET=your_webhook_secret
```

### Configuration Options

Publish the config file:

```bash
php artisan vendor:publish --tag=workos-config
```

Key options in `config/workos.php`:

```php
return [
    // Your WorkOS credentials
    'api_key' => env('WORKOS_API_KEY'),
    'client_id' => env('WORKOS_CLIENT_ID'),
    'redirect_uri' => env('WORKOS_REDIRECT_URI'),

    // Auth guard name
    'guard' => 'workos',

    // Your User model
    'user_model' => App\Models\User::class,

    // Enable/disable features
    'features' => [
        'organizations' => true,
        'impersonation' => true,
    ],

    // Route configuration
    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'middleware' => ['web'],
        'home' => '/dashboard',
    ],

    // Webhook configuration
    'webhooks' => [
        'enabled' => true,
        'prefix' => 'webhooks/workos',
        'sync_enabled' => true,
    ],
];
```

## Usage

### Authentication Routes

The package registers these routes automatically:

| Route | Description |
|-------|-------------|
| `GET /auth/login` | Redirect to WorkOS AuthKit |
| `GET /auth/callback` | Handle authentication callback |
| `GET /auth/logout` | Log out and redirect to WorkOS |

### Protecting Routes

Use the `workos.auth` middleware:

```php
Route::middleware('workos.auth')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});

// Or use the auth guard directly
Route::middleware('auth:workos')->group(function () {
    // ...
});
```

### Getting the Current User

```php
// Get the authenticated user
$user = auth()->user();
// or
$user = workos()->user();

// Get the current session
$session = workos()->session();

// Check authentication
if (workos()->isAuthenticated()) {
    // User is authenticated
}
```

### Organizations

Enable organization support in config:

```php
'features' => [
    'organizations' => true,
],
```

Then use the organization middleware:

```php
Route::middleware(['workos.auth', 'workos.organization'])->group(function () {
    // Routes that require organization context
});
```

Switching organizations:

```php
// Organizations are available on the user
$user->organizations; // Collection of organizations

// Store current organization in session
session(['current_organization_id' => $organization->id]);
```

### Roles and Permissions

Check roles and permissions:

```php
// In PHP
if (workos()->hasRole('admin')) {
    // User is admin
}

if (workos()->hasPermission('posts:write')) {
    // User can write posts
}

// In Blade
@workosRole('admin')
    <p>Admin content</p>
@endworkosRole

@workosPermission('posts:write')
    <button>Create Post</button>
@endworkosPermission
```

Use middleware:

```php
Route::middleware('workos.role:admin')->group(function () {
    // Admin-only routes
});

Route::middleware('workos.permission:posts:write')->group(function () {
    // Routes requiring write permission
});
```

### Audit Logging

Log user actions to WorkOS Audit Logs:

```php
use WorkOS\AuthKit\Facades\WorkOS;

// Simple audit log
WorkOS::audit('user.updated', [
    ['type' => 'user', 'id' => '123', 'name' => 'John Doe'],
]);

// With metadata
WorkOS::audit('document.created', [
    ['type' => 'document', 'id' => 'doc_123', 'name' => 'Q4 Report'],
], [
    'ip_address' => request()->ip(),
    'user_agent' => request()->userAgent(),
]);
```

### Admin Portal

Generate Admin Portal links:

```php
use WorkOS\AuthKit\Facades\WorkOS;

// Generate SSO configuration link
$link = WorkOS::portal()->generateLink(
    organization: $organization->workos_id,
    intent: 'sso',
    returnUrl: route('settings'),
);

return redirect($link->link);
```

Available intents:
- `sso` - Configure SSO connection
- `dsync` - Configure Directory Sync
- `audit_logs` - View audit logs
- `log_streams` - Configure log streams
- `domain_verification` - Verify domain ownership
- `certificate_renewal` - Renew SAML certificates

### Webhooks

The package automatically handles these webhook events:
- `user.created` / `user.updated` - Sync user data
- `organization.created` / `organization.updated` - Sync organization data
- `organization_membership.created` / `.updated` / `.deleted` - Sync memberships

Configure your webhook endpoint in WorkOS Dashboard:
```
https://yourapp.com/webhooks/workos
```

### Impersonation

Detect impersonation in your views:

```blade
@impersonating
    <div class="alert alert-warning">
        You are currently impersonating this user.
    </div>
@endimpersonating
```

Or in PHP:

```php
if (workos()->isImpersonating()) {
    // Show impersonation banner
}
```

## Testing

### WorkOS::actingAs()

Test authenticated users without hitting WorkOS:

```php
use WorkOS\AuthKit\Facades\WorkOS;

test('authenticated user can view dashboard', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user, roles: ['admin'], permissions: ['posts:write']);

    $this->get('/dashboard')
        ->assertOk();
});

test('user with permission can create posts', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user, permissions: ['posts:write']);

    $this->post('/posts', ['title' => 'Hello'])
        ->assertCreated();
});

test('user without permission cannot create posts', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user, permissions: []);

    $this->post('/posts', ['title' => 'Hello'])
        ->assertForbidden();
});
```

### Faking WorkOS

For complete control in tests:

```php
use WorkOS\AuthKit\Facades\WorkOS;

test('handles workos errors gracefully', function () {
    $fake = WorkOS::fake();

    // Configure fake responses
    $fake->shouldReceive('userManagement->authenticateWithCode')
        ->andThrow(new \Exception('API Error'));

    // Test error handling
    $this->get('/auth/callback?code=invalid')
        ->assertRedirect('/login')
        ->assertSessionHas('error');
});
```

## Blade Directives

| Directive | Description |
|-----------|-------------|
| `@workosRole('role')` | Show content if user has role |
| `@workosPermission('permission')` | Show content if user has permission |
| `@impersonating` | Show content when impersonating |

## Events

The package dispatches these events:

| Event | When |
|-------|------|
| `UserAuthenticated` | User completes authentication |
| `UserLoggedOut` | User logs out |
| `OrganizationSwitched` | User switches organization |
| `WebhookReceived` | Webhook received from WorkOS |
| `InvitationSent` | User invitation sent |
| `InvitationRevoked` | User invitation revoked |

## Artisan Commands

| Command | Description |
|---------|-------------|
| `workos:install` | Install and configure the package |
| `workos:sync-users` | Sync users from WorkOS |
| `workos:prune-sessions` | Remove expired sessions |
| `workos:events:listen` | Listen to WorkOS events (development) |

## Example Application

The `workbench/` directory contains a complete example Todo application demonstrating all package features.

Run it locally:

```bash
# Clone the repository
git clone https://github.com/workos/authkit-laravel.git
cd authkit-laravel

# Install dependencies
composer install

# Start the example app
composer serve

# Reset the database
composer fresh
```

## Contributing

### Local Development

```bash
# Clone and install
git clone https://github.com/workos/authkit-laravel.git
cd authkit-laravel
composer install

# Run tests
composer test

# Run static analysis
composer analyse

# Format code
composer format

# Run example app tests
composer test:example
```

### Submitting Changes

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests (`composer test && composer analyse`)
5. Commit with conventional commit message
6. Push and create a Pull Request

Use these PR labels:
- `major` / `breaking` - Breaking changes (x.0.0)
- `minor` / `feature` / `enhancement` - New features (0.x.0)
- `patch` / `fix` / `bugfix` - Bug fixes (0.0.x)
- `skip-release` / `no-release` - Don't create release

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.

## Resources

- [WorkOS Documentation](https://workos.com/docs)
- [AuthKit Overview](https://workos.com/docs/user-management/authkit)
- [WorkOS Dashboard](https://dashboard.workos.com)
```

### Example App Tests

**workbench/tests/TestCase.php**:
```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
```

**workbench/tests/Feature/AuthTest.php**:
```php
<?php

use App\Models\User;
use WorkOS\AuthKit\Facades\WorkOS;

test('guest is redirected to login page', function () {
    $this->get('/dashboard')
        ->assertRedirect('/');
});

test('login route redirects to workos', function () {
    $this->get('/auth/login')
        ->assertRedirect();
});

test('authenticated user can access dashboard', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard');
});

test('authenticated user can logout', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user);

    $this->get('/auth/logout')
        ->assertRedirect();
});
```

**workbench/tests/Feature/TodoTest.php**:
```php
<?php

use App\Models\Organization;
use App\Models\Todo;
use App\Models\User;
use Livewire\Livewire;
use WorkOS\AuthKit\Facades\WorkOS;

test('user can view todos page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);

    WorkOS::actingAs($user, organizationId: $org->workos_id);

    $this->withSession(['current_organization_id' => $org->id])
        ->get('/todos')
        ->assertOk()
        ->assertSee('Todos');
});

test('user can create a todo', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);

    WorkOS::actingAs($user, organizationId: $org->workos_id);

    Livewire::withQueryParams(['current_organization_id' => $org->id])
        ->actingAs($user)
        ->test(\App\Livewire\TodoList::class)
        ->set('newTodo', 'My new task')
        ->call('addTodo');

    $this->assertDatabaseHas('todos', [
        'user_id' => $user->id,
        'title' => 'My new task',
        'completed' => false,
    ]);
});

test('user can toggle todo completion', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'completed' => false,
    ]);

    WorkOS::actingAs($user);

    Livewire::actingAs($user)
        ->test(\App\Livewire\TodoItem::class, ['todo' => $todo])
        ->call('toggle');

    expect($todo->fresh()->completed)->toBeTrue();
});

test('user can delete a todo', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    WorkOS::actingAs($user);

    Livewire::actingAs($user)
        ->test(\App\Livewire\TodoItem::class, ['todo' => $todo])
        ->call('confirmDelete')
        ->call('delete');

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
});

test('todos are scoped to organization', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $user->organizations()->attach([$org1->id, $org2->id]);

    Todo::factory()->create(['user_id' => $user->id, 'organization_id' => $org1->id, 'title' => 'Org 1 Task']);
    Todo::factory()->create(['user_id' => $user->id, 'organization_id' => $org2->id, 'title' => 'Org 2 Task']);

    WorkOS::actingAs($user);

    $this->withSession(['current_organization_id' => $org1->id])
        ->get('/todos')
        ->assertSee('Org 1 Task')
        ->assertDontSee('Org 2 Task');
});
```

**workbench/tests/Feature/OrganizationTest.php**:
```php
<?php

use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;
use WorkOS\AuthKit\Facades\WorkOS;

test('user can view organization settings', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => 'admin']);

    WorkOS::actingAs($user);

    $this->withSession(['current_organization_id' => $org->id])
        ->get('/organizations/settings')
        ->assertOk()
        ->assertSee($org->name);
});

test('organization switcher shows all organizations', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create(['name' => 'Acme Corp']);
    $org2 = Organization::factory()->create(['name' => 'Globex Inc']);
    $user->organizations()->attach([$org1->id, $org2->id]);

    WorkOS::actingAs($user);

    Livewire::actingAs($user)
        ->test(\App\Livewire\OrganizationSwitcher::class)
        ->assertSee('Acme Corp')
        ->assertSee('Globex Inc');
});

test('user can switch organizations', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $user->organizations()->attach([$org1->id, $org2->id]);

    WorkOS::actingAs($user);

    session(['current_organization_id' => $org1->id]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\OrganizationSwitcher::class)
        ->call('switch', $org2->id);

    expect(session('current_organization_id'))->toBe($org2->id);
});

test('members list shows organization users', function () {
    $user = User::factory()->create();
    $member = User::factory()->create(['name' => 'Jane Doe']);
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => 'admin']);
    $member->organizations()->attach($org, ['role' => 'member']);

    WorkOS::actingAs($user);

    $this->withSession(['current_organization_id' => $org->id])
        ->get('/organizations/settings')
        ->assertSee($user->name)
        ->assertSee('Jane Doe');
});
```

### Model Factories

**workbench/database/factories/UserFactory.php**:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workos_id' => 'user_' . Str::random(24),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => null,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }
}
```

**workbench/database/factories/OrganizationFactory.php**:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'workos_id' => 'org_' . Str::random(24),
            'name' => $name,
            'slug' => Str::slug($name),
            'domains' => [fake()->domainName()],
        ];
    }
}
```

**workbench/database/factories/TodoFactory.php**:
```php
<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TodoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'title' => fake()->sentence(4),
            'completed' => fake()->boolean(30),
        ];
    }
}
```

### PHPUnit Configuration

**workbench/phpunit.xml**:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
    </php>
</phpunit>
```

### UI Polish

Add loading states to Livewire components:

**workbench/resources/views/livewire/todo-list.blade.php** (loading state):
```blade
{{-- Add to the add button --}}
<flux:button type="submit" variant="primary" wire:loading.attr="disabled">
    <flux:icon.plus class="mr-2" wire:loading.remove wire:target="addTodo" />
    <flux:icon.arrow-path class="mr-2 animate-spin" wire:loading wire:target="addTodo" />
    Add
</flux:button>

{{-- Add loading overlay to list --}}
<div class="space-y-2" wire:loading.class="opacity-50">
    @forelse ($this->todos as $todo)
        ...
    @endforelse
</div>
```

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `workbench/tests/Feature/AuthTest.php` | Authentication flows |
| `workbench/tests/Feature/TodoTest.php` | Todo CRUD operations |
| `workbench/tests/Feature/OrganizationTest.php` | Organization features |

### Manual Testing

- [ ] README renders correctly on GitHub
- [ ] CI badge shows correct status
- [ ] All code examples in README are accurate
- [ ] `composer serve` works
- [ ] `composer fresh` works
- [ ] `composer test:example` passes all tests
- [ ] Loading states appear during operations
- [ ] Error messages display correctly
- [ ] Empty states are helpful

## Validation Commands

```bash
# Run all checks
composer format && composer analyse && composer test

# Run example app tests
composer test:example

# Build example app assets
cd workbench && npm run build

# Verify README renders (view on GitHub)
git add . && git commit -m "docs: add README"
git push origin feature-branch
# Check GitHub PR preview
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: N/A
- **Alerting**: N/A
- **Rollback plan**: Revert documentation changes if errors found

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
