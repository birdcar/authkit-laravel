<?php

declare(strict_types=1);

use Authkit\Authkit\Authkit;

it('resolves the singleton', function () {
    expect(app(Authkit::class))->toBeInstanceOf(Authkit::class);
});

it('returns the same instance from the container', function () {
    expect(app(Authkit::class))->toBe(app(Authkit::class));
});

it('merges the package config', function () {
    expect(config('authkit-laravel.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('authkit-laravel::messages.placeholder'))->toBe('Authkit placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('authkit-laravel::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('authkit-laravel:placeholder')
        ->expectsOutputToContain('Authkit placeholder command executed.')
        ->assertSuccessful();
});
