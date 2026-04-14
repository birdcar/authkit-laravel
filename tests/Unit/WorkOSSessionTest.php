<?php

declare(strict_types=1);

use Carbon\Carbon;
use WorkOS\AuthKit\Auth\WorkOSSession;

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('can be created from array', function () {
    $data = [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => 'refresh_xyz',
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => 'session_456',
        'roles' => ['admin'],
        'permissions' => ['read', 'write'],
        'organization_id' => 'org_789',
        'impersonator' => null,
    ];

    $session = WorkOSSession::fromArray($data);

    expect($session->userId)->toBe('user_123')
        ->and($session->accessToken)->toBe('token_abc')
        ->and($session->refreshToken)->toBe('refresh_xyz')
        ->and($session->sessionId)->toBe('session_456')
        ->and($session->roles)->toBe(['admin'])
        ->and($session->permissions)->toBe(['read', 'write'])
        ->and($session->organizationId)->toBe('org_789')
        ->and($session->impersonator)->toBeNull();
});

it('can be created from auth response', function () {
    $response = [
        'access_token' => 'token_abc',
        'refresh_token' => 'refresh_xyz',
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => 'session_456',
        'organization_id' => 'org_789',
        'user' => [
            'id' => 'user_123',
            'roles' => ['admin'],
            'permissions' => ['read', 'write'],
        ],
    ];

    $session = WorkOSSession::fromAuthResponse($response);

    expect($session->userId)->toBe('user_123')
        ->and($session->accessToken)->toBe('token_abc')
        ->and($session->roles)->toBe(['admin'])
        ->and($session->permissions)->toBe(['read', 'write']);
});

it('can be converted to array', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::parse('2024-01-15T13:00:00Z'),
        sessionId: 'session_456',
        roles: ['admin'],
        permissions: ['read', 'write'],
        organizationId: 'org_789',
        impersonator: null,
    );

    $array = $session->toArray();

    expect($array['user_id'])->toBe('user_123')
        ->and($array['access_token'])->toBe('token_abc')
        ->and($array['refresh_token'])->toBe('refresh_xyz')
        ->and($array['session_id'])->toBe('session_456')
        ->and($array['roles'])->toBe(['admin'])
        ->and($array['permissions'])->toBe(['read', 'write'])
        ->and($array['organization_id'])->toBe('org_789')
        ->and($array['impersonator'])->toBeNull();
});

it('detects expired sessions', function () {
    $expiredSession = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::parse('2024-01-15T11:00:00Z'),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    $validSession = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::parse('2024-01-15T13:00:00Z'),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    expect($expiredSession->isExpired())->toBeTrue()
        ->and($validSession->isExpired())->toBeFalse();
});

it('detects when refresh is needed', function () {
    $needsRefresh = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::parse('2024-01-15T12:03:00Z'),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    $doesNotNeedRefresh = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::parse('2024-01-15T13:00:00Z'),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    expect($needsRefresh->needsRefresh(5))->toBeTrue()
        ->and($doesNotNeedRefresh->needsRefresh(5))->toBeFalse();
});

it('checks permissions', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: ['read', 'write'],
        organizationId: null,
        impersonator: null,
    );

    expect($session->hasPermission('read'))->toBeTrue()
        ->and($session->hasPermission('delete'))->toBeFalse();
});

it('checks roles', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: ['admin', 'editor'],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    expect($session->hasRole('admin'))->toBeTrue()
        ->and($session->hasRole('viewer'))->toBeFalse();
});

it('checks feature flags', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        featureFlags: ['new-dashboard', 'beta-feature'],
    );

    expect($session->hasFeatureFlag('new-dashboard'))->toBeTrue()
        ->and($session->hasFeatureFlag('old-feature'))->toBeFalse();
});

it('checks entitlements', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        entitlements: ['sso', 'audit-logs'],
    );

    expect($session->hasEntitlement('sso'))->toBeTrue()
        ->and($session->hasEntitlement('fga'))->toBeFalse();
});

it('accesses arbitrary claims', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        claims: ['custom_field' => 'custom_value', 'tenant_tier' => 'enterprise'],
    );

    expect($session->claim('custom_field'))->toBe('custom_value')
        ->and($session->claim('tenant_tier'))->toBe('enterprise')
        ->and($session->claim('missing_key'))->toBeNull()
        ->and($session->claim('missing_key', 'default'))->toBe('default');
});

it('includes feature flags and entitlements in toArray', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        featureFlags: ['new-dashboard'],
        entitlements: ['sso'],
        claims: ['custom' => 'value'],
    );

    $array = $session->toArray();

    expect($array['feature_flags'])->toBe(['new-dashboard'])
        ->and($array['entitlements'])->toBe(['sso'])
        ->and($array['claims'])->toBe(['custom' => 'value']);
});

it('roundtrips feature flags and entitlements through fromArray', function () {
    $original = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: ['admin'],
        permissions: ['read'],
        featureFlags: ['beta'],
        entitlements: ['sso'],
        claims: ['custom' => 'value'],
    );

    $restored = WorkOSSession::fromArray($original->toArray());

    expect($restored->featureFlags)->toBe(['beta'])
        ->and($restored->entitlements)->toBe(['sso'])
        ->and($restored->claims)->toBe(['custom' => 'value']);
});

it('defaults feature flags and entitlements to empty arrays', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
    );

    expect($session->featureFlags)->toBe([])
        ->and($session->entitlements)->toBe([])
        ->and($session->claims)->toBe([]);
});
