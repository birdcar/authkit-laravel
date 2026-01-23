<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSGuard;
use WorkOS\AuthKit\Facades\WorkOS as WorkOSFacade;
use WorkOS\AuthKit\WorkOS;

it('registers workos singleton', function () {
    expect(app('workos'))->toBeInstanceOf(WorkOS::class);
});

it('registers session manager singleton', function () {
    expect(app(SessionManager::class))->toBeInstanceOf(SessionManager::class);

    $first = app(SessionManager::class);
    $second = app(SessionManager::class);

    expect($first)->toBe($second);
});

it('facade resolves to workos service', function () {
    expect(WorkOSFacade::getFacadeRoot())->toBeInstanceOf(WorkOS::class);
});

it('helper function returns workos service', function () {
    expect(workos())->toBeInstanceOf(WorkOS::class);
});

it('registers workos auth guard driver', function () {
    config(['auth.guards.workos' => [
        'driver' => 'workos',
        'provider' => 'users',
    ]]);

    $guard = Auth::guard('workos');

    expect($guard)->toBeInstanceOf(WorkOSGuard::class);
});

it('publishes config file', function () {
    expect(config('workos.api_key'))->toBe('test_api_key');
    expect(config('workos.client_id'))->toBe('test_client_id');
    expect(config('workos.redirect_uri'))->toBe('http://localhost/auth/callback');
});

it('has default session refresh buffer', function () {
    expect(config('workos.session.refresh_buffer_minutes'))->toBe(5);
});

it('has default feature flags', function () {
    expect(config('workos.features.audit_logs'))->toBeFalse();
    expect(config('workos.features.organizations'))->toBeTrue();
    expect(config('workos.features.impersonation'))->toBeTrue();
    expect(config('workos.features.webhooks'))->toBeTrue();
});

it('has default route configuration', function () {
    expect(config('workos.routes.enabled'))->toBeTrue();
    expect(config('workos.routes.prefix'))->toBe('auth');
    expect(config('workos.routes.middleware'))->toBe(['web']);
});
