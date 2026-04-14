<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use WorkOS\AuthKit\Services\PipesService;
use WorkOS\Exception\AuthorizationException;
use WorkOS\WorkOS;

function makePipesClient(MockHandler $mock): WorkOS
{
    $stack = HandlerStack::create($mock);

    return new WorkOS(apiKey: 'sk_test_pipes', handler: $stack);
}

beforeEach(function () {
    config(['workos.routes.home' => '/dashboard']);
});

it('lists providers', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'object' => 'list',
            'data' => [
                [
                    'object' => 'data_provider',
                    'id' => 'provider_1',
                    'name' => 'GitHub',
                    'description' => null,
                    'slug' => 'github',
                    'integration_type' => 'github',
                    'credentials_type' => 'oauth2',
                    'scopes' => ['repo'],
                    'ownership' => 'userland_user',
                    'created_at' => '2024-01-01T00:00:00.000Z',
                    'updated_at' => '2024-01-01T00:00:00.000Z',
                    'connected_account' => null,
                ],
            ],
        ])),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $result = $service->listProviders('user_123');

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['slug'])->toBe('github');
});

it('gets authorization url', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'url' => 'https://github.com/login/oauth/authorize?client_id=abc',
        ])),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $url = $service->getAuthorizationUrl('github', 'user_123', 'https://myapp.com/callback');

    expect($url)->toBe('https://github.com/login/oauth/authorize?client_id=abc');
});

it('defaults returnTo to config home', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'url' => 'https://github.com/login/oauth/authorize?client_id=abc',
        ])),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $url = $service->getAuthorizationUrl('github', 'user_123');

    expect($url)->toBeString();
});

it('gets connected account', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'object' => 'connected_account',
            'id' => 'ca_123',
            'user_id' => 'user_123',
            'organization_id' => null,
            'scopes' => ['repo'],
            'state' => 'connected',
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
        ])),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $result = $service->getConnectedAccount('user_123', 'github');

    expect($result['state'])->toBe('connected');
});

it('deletes connected account', function () {
    $mock = new MockHandler([
        new Response(204),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $service->deleteConnectedAccount('user_123', 'github');

    expect($mock->count())->toBe(0);
});

it('gets access token', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([])),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $result = $service->getAccessToken('user_123', 'github');

    expect($result)->toBeArray();
});

it('throws on API error', function () {
    $mock = new MockHandler([
        new Response(403, [], json_encode(['message' => 'Forbidden'])),
    ]);

    $service = new PipesService(makePipesClient($mock));
    $service->listProviders('user_123');
})->throws(AuthorizationException::class);

it('throws when pipes feature is disabled', function () {
    config(['workos.features.pipes' => false]);

    app('workos')->pipes();
})->throws(RuntimeException::class, 'WorkOS Pipes is not enabled');
