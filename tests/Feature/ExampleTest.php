<?php

declare(strict_types=1);

use Authkit\Authkit\Authkit;
use Authkit\Authkit\AuthkitServiceProvider;
use Illuminate\Support\Facades\Artisan;

it('resolves the singleton', function () {
    expect(app(Authkit::class))->toBeInstanceOf(Authkit::class);
});

it('returns the same instance from the container', function () {
    expect(app(Authkit::class))->toBe(app(Authkit::class));
});

it('merges the package config', function () {
    expect(config('authkit.base_url'))->toBe('https://api.workos.com');
});

it('merges the package config from the package schema itself', function () {
    // Clear the key first so a config/authkit.php left behind in the Testbench
    // skeleton by an earlier authkit:install run cannot satisfy this vacuously.
    config()->set('authkit', []);

    (new AuthkitServiceProvider($this->app))->register();

    expect(config('authkit.base_url'))->toBe('https://api.workos.com');
    expect(config('authkit.emulate.api_key'))->toBe('sk_test_default');
});

it('loads the package translations', function () {
    expect(trans('authkit-laravel::messages.placeholder'))->toBe('Authkit placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('authkit-laravel::placeholder'))->toBeTrue();
});

it('registers the artisan commands without the retired placeholder', function () {
    $registered = array_keys(Artisan::all());

    expect($registered)->toContain('authkit:install');
    expect($registered)->toContain('authkit:inspect-token');
    expect($registered)->toContain('authkit:work');
    expect($registered)->not->toContain('authkit-laravel:placeholder');
});
