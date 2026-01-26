# AuthKit Laravel

[![CI](https://github.com/birdcar/authkit-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/birdcar/authkit-laravel/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/birdcar/authkit-laravel.svg)](https://github.com/birdcar/authkit-laravel/blob/main/LICENSE)

Laravel integration for [WorkOS AuthKit](https://workos.com/authkit) - add enterprise-grade authentication to your Laravel application in minutes.

## Features

- **AuthKit Authentication** - SSO, MFA, social login via WorkOS
- **Multi-tenant Organizations** - Built-in organization support with role-based access
- **Audit Logging** - Track user actions with WorkOS Audit Logs
- **Webhook Sync** - Automatic user/org sync from WorkOS webhooks
- **Impersonation** - Support user impersonation with visual indicators
- **Testing Utilities** - Easy testing with `WorkOS::actingAs()`

## Requirements

- PHP 8.2 or higher
- Laravel 10, 11, or 12
- [WorkOS account](https://dashboard.workos.com/)

## Installation

### Install from GitHub

Since this package is not yet on Packagist, add the repository to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/birdcar/authkit-laravel"
        }
    ]
}
```

Then install via Composer:

```bash
composer require workos/authkit-laravel:dev-main
```

Or to install a specific release:

```bash
composer require workos/authkit-laravel:v0.1.0
```

### Run Installation Command

```bash
php artisan workos:install
```

The install command runs an interactive wizard that:

1. **Detects your environment** - Scans for existing auth packages (Breeze, Jetstream, Fortify) and any prior WorkOS setup
2. **Generates a migration plan** - If existing auth is detected, creates a detailed markdown guide at `storage/workos-migration-plan.md`
3. **Handles laravel/workos migration** - If you're migrating from the official `laravel/workos` package, offers options to replace, augment, or run alongside
4. **Selects components** - Lets you choose which parts to install (routes, auth system, webhooks)
5. **Configures environment** - Updates your `.env` with required WorkOS variables
6. **Runs migrations** - Optionally runs database migrations

For a minimal install that only publishes config with setup instructions:

```bash
php artisan workos:install --mini
```

### Migrating from Existing Auth

If you have Laravel Breeze, Jetstream, or Fortify installed, the wizard detects this and generates a comprehensive migration plan. The plan includes:

- **Pre-migration checklist** - Backup reminders and WorkOS account setup
- **Files to remove** - Lists auth controllers and views that are no longer needed
- **Database changes** - Required schema updates
- **User model updates** - Traits to add for WorkOS integration
- **Data migration options** - How to handle existing users (re-authenticate or pre-link via API)
- **Post-migration testing** - Verification steps
- **Rollback plan** - How to revert if needed

The migration plan is saved to `storage/workos-migration-plan.md` for reference.

### Migrating from laravel/workos

If you're using the official `laravel/workos` package, the wizard offers three strategies:

| Strategy | Description |
|----------|-------------|
| **Replace** | Migrate config and remove the old package (recommended) |
| **Augment** | Add AuthKit features alongside existing setup |
| **Keep both** | Install without any migration |

Your existing `WORKOS_*` environment variables are compatible with AuthKit - no changes needed

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# Required
WORKOS_API_KEY=sk_test_your_api_key
WORKOS_CLIENT_ID=client_your_client_id
WORKOS_REDIRECT_URI=http://localhost:8000/auth/callback

# Set WorkOS as the default auth guard
AUTH_GUARD=workos

# Optional
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

    // Session configuration
    // When true (default), uses WorkOS's wos-session cookie as the source of truth
    // When false, stores session data in Laravel's session
    'session' => [
        'cookie_session' => env('WORKOS_COOKIE_SESSION', true),
        'cookie_name' => env('WORKOS_COOKIE_NAME', 'wos-session'),
    ],

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

Use the organization middleware to resolve and share the current organization:

```php
// Resolve current organization and share with views
Route::middleware(['workos.auth', 'workos.organization.current'])->group(function () {
    // $currentOrganization is available in views
    // request()->attributes->get('current_organization') in controllers
});

// Require organization membership (returns 403 if not a member)
Route::middleware(['workos.auth', 'workos.organization'])->group(function () {
    // User must belong to the current organization
});

// Require specific role within organization
Route::middleware(['workos.auth', 'workos.organization:admin'])->group(function () {
    // User must be an admin of the current organization
});
```

Working with organizations:

```php
// Organizations are available on the user
$user->organizations; // Collection of organizations

// Get current organization (from WorkOS session)
$currentOrg = $user->currentOrganization();

// Switch organizations (fires OrganizationSwitched event)
$user->switchOrganization('org_456');

// Check membership and roles
$user->belongsToOrganization('org_456'); // bool
$user->hasOrganizationRole('org_456', 'admin'); // bool
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

## Middleware

| Middleware | Description |
|------------|-------------|
| `workos.auth` | Require WorkOS authentication |
| `workos.role:role` | Require specific role |
| `workos.permission:permission` | Require specific permission |
| `workos.organization` | Require organization membership |
| `workos.organization:role` | Require organization role |
| `workos.organization.current` | Resolve and share current organization |
| `workos.impersonation` | Detect and expose impersonation state |
| `workos.inertia` | Share WorkOS data with Inertia.js |
| `workos.audit` | Log route access to WorkOS Audit Logs |

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
| `workos:install` | Interactive wizard to install and configure the package |
| `workos:install --mini` | Minimal install - config only with setup instructions |
| `workos:install --force` | Overwrite existing configuration files |
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
