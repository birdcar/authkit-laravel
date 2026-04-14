<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Services\PipesService;

beforeEach(function () {
    config([
        'workos.api_key' => 'sk_test_pipes',
        'workos.widgets.base_url' => 'https://api.workos.com',
        'workos.routes.home' => '/dashboard',
    ]);
});

it('lists providers', function () {
    Http::fake([
        'api.workos.com/data-integrations' => Http::response([
            'data' => [['slug' => 'github', 'name' => 'GitHub']],
        ]),
    ]);

    $service = new PipesService;
    $result = $service->listProviders();

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['slug'])->toBe('github');
});

it('gets authorization url', function () {
    Http::fake([
        'api.workos.com/data-integrations/github/authorize' => Http::response([
            'url' => 'https://github.com/login/oauth/authorize?client_id=abc',
        ]),
    ]);

    $service = new PipesService;
    $url = $service->getAuthorizationUrl('github', 'user_123', 'https://myapp.com/callback');

    expect($url)->toBe('https://github.com/login/oauth/authorize?client_id=abc');

    Http::assertSent(function ($request) {
        return $request['user_id'] === 'user_123'
            && $request['return_to'] === 'https://myapp.com/callback';
    });
});

it('defaults returnTo to config home', function () {
    Http::fake([
        'api.workos.com/data-integrations/github/authorize' => Http::response(['url' => 'https://example.com']),
    ]);

    $service = new PipesService;
    $service->getAuthorizationUrl('github', 'user_123');

    Http::assertSent(function ($request) {
        return $request['return_to'] === '/dashboard';
    });
});

it('includes organization id when provided', function () {
    Http::fake([
        'api.workos.com/data-integrations/github/authorize' => Http::response(['url' => 'https://example.com']),
    ]);

    $service = new PipesService;
    $service->getAuthorizationUrl('github', 'user_123', organizationId: 'org_456');

    Http::assertSent(function ($request) {
        return $request['organization_id'] === 'org_456';
    });
});

it('gets connected account', function () {
    Http::fake([
        'api.workos.com/user_management/users/user_123/connected_accounts/github' => Http::response([
            'id' => 'ca_123',
            'state' => 'connected',
        ]),
    ]);

    $service = new PipesService;
    $result = $service->getConnectedAccount('user_123', 'github');

    expect($result['state'])->toBe('connected');
});

it('deletes connected account', function () {
    Http::fake([
        'api.workos.com/user_management/users/user_123/connected_accounts/github' => Http::response(null, 204),
    ]);

    $service = new PipesService;
    $service->deleteConnectedAccount('user_123', 'github');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE';
    });
});

it('gets access token', function () {
    Http::fake([
        'api.workos.com/user_management/users/user_123/connected_accounts/github/access_token' => Http::response([
            'access_token' => 'gho_abc123',
            'expires_at' => '2026-04-12T12:00:00Z',
        ]),
    ]);

    $service = new PipesService;
    $result = $service->getAccessToken('user_123', 'github');

    expect($result['access_token'])->toBe('gho_abc123');
});

it('throws on API error', function () {
    Http::fake([
        'api.workos.com/data-integrations' => Http::response(['error' => 'forbidden'], 403),
    ]);

    $service = new PipesService;
    $service->listProviders();
})->throws(RuntimeException::class, 'WorkOS Pipes API error');

it('throws when pipes feature is disabled', function () {
    config(['workos.features.pipes' => false]);

    app('workos')->pipes();
})->throws(RuntimeException::class, 'WorkOS Pipes is not enabled');
