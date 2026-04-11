# AuthKit Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/birdcar/authkit-laravel.svg?style=flat-square)](https://packagist.org/packages/birdcar/authkit-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/birdcar/authkit-laravel?style=flat-square)](https://packagist.org/packages/birdcar/authkit-laravel)
[![Laravel Version](https://img.shields.io/badge/laravel-11.x%20%7C%2012.x-blue?style=flat-square)](https://packagist.org/packages/birdcar/authkit-laravel)
[![License](https://img.shields.io/packagist/l/birdcar/authkit-laravel?style=flat-square)](LICENSE)

Drop-in [WorkOS AuthKit](https://workos.com/docs/authkit) integration for Laravel. Guards, middleware, Blade directives, Livewire widgets, webhook sync, and an interactive install wizard -- everything wired up so you can go from `composer require` to working authentication in about 15 minutes.

## Installation

```bash
composer require birdcar/authkit-laravel
```

Run the install wizard, which walks you through environment setup, guard configuration, route registration, and webhook wiring:

```bash
php artisan workos:install
```

You need three environment variables from your [WorkOS Dashboard](https://dashboard.workos.com):

```env
WORKOS_API_KEY=sk_live_...
WORKOS_CLIENT_ID=client_...
WORKOS_REDIRECT_URI=https://your-app.test/auth/callback
```

The package auto-registers its service provider and `WorkOS` facade via Laravel's package discovery. For detailed installation options (migrating from Laravel's built-in auth, custom guard names, publishing config), see [docs/usage/installation.md](docs/usage/installation.md).

## Quick start

The package registers a `workos` auth guard and sets up `/auth/login`, `/auth/callback`, and `/auth/logout` routes automatically. Protect routes with the included middleware:

```php
// routes/web.php
Route::middleware('workos.auth')->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();
        return view('dashboard', compact('user'));
    });
});
```

Check roles and permissions in routes:

```php
Route::middleware(['workos.auth', 'workos.role:admin'])->group(function () {
    Route::get('/admin', [AdminController::class, 'index']);
});

Route::middleware(['workos.auth', 'workos.permission:posts:write'])->group(function () {
    Route::post('/posts', [PostController::class, 'store']);
});
```

Or in Blade templates:

```blade
@workosRole('admin')
    <a href="/admin">Admin Panel</a>
@endworkosRole

@workosPermission('posts:write')
    <button>New Post</button>
@endworkosPermission

@impersonating
    <div class="banner">You are impersonating this user.</div>
@endimpersonating
```

## User model setup

Add the package traits to your `User` model:

```php
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId, HasWorkOSPermissions;
}
```

`HasWorkOSId` adds `findByWorkOSId()` and `findOrCreateByWorkOS()` methods, and maps the `workos_id` column as the auth identifier. `HasWorkOSPermissions` exposes `hasWorkOSRole()`, `hasWorkOSPermission()`, and organization context from the session.

## Livewire widgets

If you have Livewire installed, the package registers drop-in components that match the official WorkOS widget designs. No API calls from your frontend -- they render server-side using the WorkOS API through your existing session.

```blade
{{-- Full user management panel --}}
<livewire:workos-user-management />

{{-- User profile with security settings --}}
<livewire:workos-user-profile />

{{-- SSO configuration --}}
<livewire:workos-admin-portal />
```

Available widget groups: User Management, User Profile, Admin Portal (SSO/Domains), API Keys, Data Integrations, Directory Sync, and Settings. Each group has a top-level component and granular sub-components if you need to compose your own layout. See [docs/usage/widgets.md](docs/usage/widgets.md) for the full list and customization options.

## Testing

The package provides `WorkOS::fake()` and `WorkOS::actingAs()` so you can test authenticated flows without hitting the WorkOS API:

```php
use WorkOS\AuthKit\Facades\WorkOS;

it('requires admin role', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user, roles: ['member']);

    $this->get('/admin')->assertForbidden();

    WorkOS::actingAs($user, roles: ['admin']);

    $this->get('/admin')->assertOk();
});
```

The fake also captures audit log calls for assertion:

```php
$fake = WorkOS::fake();
WorkOS::actingAs($user);

$this->post('/posts', ['title' => 'Hello']);

$fake->assertAudited('post.created');
```

See [docs/usage/testing.md](docs/usage/testing.md) for the full assertion API.

## Middleware reference

| Alias | Class | Purpose |
|---|---|---|
| `workos.auth` | `EnsureWorkOSAuthenticated` | Require valid WorkOS session |
| `workos.role` | `CheckRole` | Require specific role |
| `workos.permission` | `CheckPermission` | Require specific permission |
| `workos.organization` | `CheckOrganization` | Require organization membership |
| `workos.organization.current` | `SetCurrentOrganization` | Set org context from request |
| `workos.impersonation` | `DetectImpersonation` | Block or flag impersonated sessions |
| `workos.audit` | `AuditMiddleware` | Log request to WorkOS Audit Logs |
| `workos.inertia` | `ShareWorkOSData` | Share auth state with Inertia |

## Documentation

- [Installation](docs/usage/installation.md) -- Detailed setup, migration from existing auth
- [Authentication](docs/usage/authentication.md) -- Guard, sessions, token refresh
- [Authorization](docs/usage/authorization.md) -- Roles, permissions, middleware
- [Organizations](docs/usage/organizations.md) -- Multi-org, switching, invitations
- [Events API & Webhooks](docs/usage/events.md) -- Event routing, Events API polling worker, hybrid approaches
- [Webhooks](docs/usage/webhooks.md) -- Real-time webhook ingestion, event handling, user/org sync
- [Widgets](docs/usage/widgets.md) -- Livewire components
- [Testing](docs/usage/testing.md) -- `WorkOS::fake()`, assertions
- [Audit Logging](docs/usage/audit-logging.md) -- WorkOS Audit Logs integration
- [Commands](docs/usage/commands.md) -- Artisan command reference
- [Configuration](docs/usage/configuration.md) -- Complete config reference

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- WorkOS PHP SDK ^4.29
- Livewire ^3.0 (optional, for widget components only)

## License

MIT. See [LICENSE](LICENSE) for details.
