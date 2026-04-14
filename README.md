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

Run the install wizard:

```bash
php artisan workos:install
```

Add your [WorkOS Dashboard](https://dashboard.workos.com) credentials to `.env`:

```env
WORKOS_API_KEY=sk_live_...
WORKOS_CLIENT_ID=client_...
WORKOS_REDIRECT_URI=https://your-app.test/auth/callback
```

The package auto-registers its service provider and `WorkOS` facade via Laravel's package discovery. See [Installation](docs/usage/installation.md) for migration guides, custom guard names, and publishing config.

## Quick start

The package registers a `workos` auth guard and sets up `/auth/login`, `/auth/callback`, and `/auth/logout` routes automatically:

```php
// routes/web.php
Route::middleware('workos.auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'));
});
```

Check roles and permissions in routes or Blade:

```php
Route::middleware(['workos.auth', 'workos.role:admin'])->group(/* ... */);
Route::middleware(['workos.auth', 'workos.permission:posts:write'])->group(/* ... */);
```

```blade
@workosRole('admin')
    <a href="/admin">Admin Panel</a>
@endworkosRole

@workosPermission('posts:write')
    <button>New Post</button>
@endworkosPermission
```

See [Authentication](docs/usage/authentication.md) and [Authorization](docs/usage/authorization.md) for the full API.

## Testing

```php
use WorkOS\AuthKit\Facades\WorkOS;

it('requires admin role', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user, roles: ['admin']);
    $this->get('/admin')->assertOk();

    WorkOS::actingAs($user, roles: ['member']);
    $this->get('/admin')->assertForbidden();
});
```

See [Testing](docs/usage/testing.md) for `WorkOS::fake()`, builder methods, audit assertions, and the `InteractsWithWorkOS` trait.

## Documentation

- [Installation](docs/usage/installation.md) -- Setup, migration from existing auth, publishing config
- [Authentication](docs/usage/authentication.md) -- Guard, sessions, login/callback/logout, impersonation
- [Authorization](docs/usage/authorization.md) -- Roles, permissions, FGA, feature flags, middleware, Blade directives
- [Organizations](docs/usage/organizations.md) -- Multi-org, switching, invitations, domain verification
- [Events & Webhooks](docs/usage/events.md) -- Event routing, Events API polling worker
- [Webhooks](docs/usage/webhooks.md) -- Real-time webhook ingestion, event handling, sync listeners
- [Widgets](docs/usage/widgets.md) -- Livewire components for user management, admin portal, etc.
- [Testing](docs/usage/testing.md) -- `WorkOS::fake()`, `actingAs()`, assertions
- [Audit Logging](docs/usage/audit-logging.md) -- WorkOS Audit Logs integration
- [Commands](docs/usage/commands.md) -- Artisan command reference
- [Configuration](docs/usage/configuration.md) -- Complete config reference

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- WorkOS PHP SDK ^5.0
- Livewire ^4.0 (optional, for widget components only)

## License

MIT. See [LICENSE](LICENSE) for details.
