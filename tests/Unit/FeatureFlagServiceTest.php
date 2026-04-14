<?php

declare(strict_types=1);

use Carbon\Carbon;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;
use WorkOS\Organizations;
use WorkOS\Resource\FeatureFlag;

beforeEach(function () {
    $this->sessionManager = Mockery::mock(SessionManager::class);
    $this->organizations = Mockery::mock(Organizations::class);
    $this->service = new FeatureFlagService($this->sessionManager, $this->organizations);
});

it('returns true when flag is in session featureFlags', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        featureFlags: ['new-dashboard', 'beta'],
    );

    $this->sessionManager->shouldReceive('getSession')->andReturn($session);

    expect($this->service->isEnabled('new-dashboard'))->toBeTrue();
});

it('returns false when flag is not in session', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        featureFlags: ['other-flag'],
    );

    $this->sessionManager->shouldReceive('getSession')->andReturn($session);

    expect($this->service->isEnabled('new-dashboard'))->toBeFalse();
});

it('falls back to API when no session and org ID is available', function () {
    $this->sessionManager->shouldReceive('getSession')->andReturn(null);
    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_123');

    $flag = FeatureFlag::constructFromResponse(['slug' => 'api-flag', 'id' => 'ff_1', 'name' => 'API Flag', 'description' => '', 'created_at' => '', 'updated_at' => '']);

    $result = (object) ['feature_flags' => [$flag]];
    $this->organizations->shouldReceive('listOrganizationFeatureFlags')
        ->with('org_123')
        ->andReturn($result);

    expect($this->service->isEnabled('api-flag'))->toBeTrue();
});

it('returns false when no session and no org ID', function () {
    $this->sessionManager->shouldReceive('getSession')->andReturn(null);
    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn(null);

    expect($this->service->isEnabled('any-flag'))->toBeFalse();
});

it('returns false when API call fails', function () {
    $this->sessionManager->shouldReceive('getSession')->andReturn(null);
    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_123');

    $this->organizations->shouldReceive('listOrganizationFeatureFlags')
        ->andThrow(new RuntimeException('API error'));

    expect($this->service->isEnabled('any-flag'))->toBeFalse();
});

it('returns false when feature flags config is disabled', function () {
    config(['workos.features.feature_flags' => false]);

    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        featureFlags: ['new-dashboard'],
    );

    $this->sessionManager->shouldReceive('getSession')->never();

    expect($this->service->isEnabled('new-dashboard'))->toBeFalse();
});

it('uses explicit org ID over session org ID for API fallback', function () {
    $this->sessionManager->shouldReceive('getSession')->andReturn(null);

    $flag = FeatureFlag::constructFromResponse(['slug' => 'org-flag', 'id' => 'ff_2', 'name' => 'Org Flag', 'description' => '', 'created_at' => '', 'updated_at' => '']);

    $result = (object) ['feature_flags' => [$flag]];
    $this->organizations->shouldReceive('listOrganizationFeatureFlags')
        ->with('org_explicit')
        ->andReturn($result);

    expect($this->service->isEnabled('org-flag', 'org_explicit'))->toBeTrue();
});
