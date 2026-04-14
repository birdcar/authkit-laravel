<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use WorkOS\AuthKit\Auth\ApiKeyValidation;
use WorkOS\AuthKit\Facades\WorkOS;
use WorkOS\AuthKit\Http\Middleware\ValidateApiKey;

it('returns 401 when authorization header is missing', function () {
    $request = Request::create('/api/test', 'GET');
    $middleware = new ValidateApiKey;

    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true))->toMatchArray(['message' => 'Unauthenticated.']);
});

it('returns 401 for non-Bearer authorization', function () {
    $request = Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
    ]);
    $middleware = new ValidateApiKey;

    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(401);
});

it('returns 401 when api key is invalid', function () {
    WorkOS::shouldReceive('validateApiKey')
        ->with('sk_test_invalid')
        ->andReturn(null);

    $request = Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer sk_test_invalid',
    ]);
    $middleware = new ValidateApiKey;

    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true))->toMatchArray(['message' => 'Invalid API key.']);
});

it('passes request through when api key is valid', function () {
    $validation = new ApiKeyValidation(
        id: 'key_123',
        ownerType: 'organization',
        ownerId: 'org_456',
        name: 'Test Key',
        obfuscatedValue: 'sk_***',
        permissions: ['read:data'],
        organizationId: 'org_456',
        lastUsedAt: null,
        createdAt: '2024-01-01T00:00:00Z',
        updatedAt: '2024-01-01T00:00:00Z',
    );

    WorkOS::shouldReceive('validateApiKey')
        ->with('sk_test_valid')
        ->andReturn($validation);

    $request = Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer sk_test_valid',
    ]);
    $middleware = new ValidateApiKey;

    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('injects organization id when key owner is an organization', function () {
    $validation = new ApiKeyValidation(
        id: 'key_123',
        ownerType: 'organization',
        ownerId: 'org_456',
        name: 'Test Key',
        obfuscatedValue: 'sk_***',
        permissions: [],
        organizationId: 'org_456',
        lastUsedAt: null,
        createdAt: '2024-01-01T00:00:00Z',
        updatedAt: '2024-01-01T00:00:00Z',
    );

    WorkOS::shouldReceive('validateApiKey')
        ->with('sk_test_valid')
        ->andReturn($validation);

    $request = Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer sk_test_valid',
    ]);
    $middleware = new ValidateApiKey;

    $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($request->attributes->get('workos_api_key'))->toBe($validation)
        ->and($request->attributes->get('workos_organization_id'))->toBe('org_456');
});

it('does not inject organization id for user-owned keys', function () {
    $validation = new ApiKeyValidation(
        id: 'key_789',
        ownerType: 'user',
        ownerId: 'user_123',
        name: 'User Key',
        obfuscatedValue: 'sk_***',
        permissions: [],
        organizationId: null,
        lastUsedAt: null,
        createdAt: '2024-01-01T00:00:00Z',
        updatedAt: '2024-01-01T00:00:00Z',
    );

    WorkOS::shouldReceive('validateApiKey')
        ->with('sk_test_user_key')
        ->andReturn($validation);

    $request = Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer sk_test_user_key',
    ]);
    $middleware = new ValidateApiKey;

    $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($request->attributes->get('workos_api_key'))->toBe($validation)
        ->and($request->attributes->has('workos_organization_id'))->toBeFalse();
});
