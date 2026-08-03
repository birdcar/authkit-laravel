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

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="authkit-laravel"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="authkit-laravel-config"
```

### Publishing and Running the Migrations

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
