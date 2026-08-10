<div align="center">
    <h1>Authkit Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://img.shields.io/packagist/v/birdcar/authkit-laravel.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://img.shields.io/packagist/php-v/birdcar/authkit-laravel.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://badge.laravel.cloud/badge/birdcar/authkit-laravel?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/birdcar/authkit-laravel/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/birdcar/authkit-laravel/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://img.shields.io/packagist/dt/birdcar/authkit-laravel.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A "batteries included" implementation of AuthKit and the entire WorkOS Suite for Laravel

## Installation

You can install the package via Composer:

```bash
composer require birdcar/authkit-laravel
```

Then run the installer, which publishes the config, appends the `WORKOS_*` keys to
your `.env` and `.env.example`, and generates a session cookie password:

```bash
php artisan authkit:install
```

It is safe to re-run: existing keys are left untouched.

You may instead publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="authkit-laravel"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="authkit-config"
```

### Publishing and Running the Migrations

The package does not ship any migrations yet, so this currently publishes nothing.

```bash
php artisan vendor:publish --tag="authkit-laravel-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="authkit-laravel-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="authkit-laravel-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="authkit-laravel-assets"
```

## Usage

<!-- Add a basic usage example here. -->

### JWT Templates

`Authkit::jwtTemplate()` wraps the environment's JWT template:

```php
use Authkit\Authkit\Facades\Authkit;

$template = Authkit::jwtTemplate()->get();

Authkit::jwtTemplate()->update('{"plan": "{{ organization.name }}"}');
```

> [!WARNING]
> **Editing the JWT template changes every access token your environment mints
> from that moment on — and the AuthKit sealed session cookie that carries
> those tokens has a hard 4KB browser ceiling.**
>
> A template that grows the claim set (for example by embedding large
> role/permission arrays) can push the sealed cookie past 4KB, at which point
> browsers silently truncate or drop it: the `workos` guard can no longer
> unseal the session and users are locked out of login entirely. Claims are
> also what back this package's zero-HTTP RBAC checks and the Pennant
> `feature_flags` claim, so template edits shift authorization behavior, not
> just token cosmetics.
>
> Every `update()` call logs a warning and dispatches the
> `Authkit\Authkit\Events\JwtTemplateUpdated` event (carrying the before/after
> content) — listen for it to wire your own alerting. **Always verify a real
> login end-to-end in staging after a template change before deploying it.**
> If you need bulky data available at runtime, keep it out of the template and
> use the runtime APIs instead of growing the token.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Authkit Laravel! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Birdcar](https://github.com/birdcar)
- [All Contributors](../../contributors)

## License

Authkit Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).
