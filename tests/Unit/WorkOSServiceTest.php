<?php

declare(strict_types=1);

use Carbon\Carbon;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\WorkOS;

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
    $this->sessionManager = Mockery::mock(SessionManager::class);
    $this->workos = new WorkOS($this->sessionManager);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('proxies to SDK services', function () {
    expect($this->workos->userManagement())->toBeInstanceOf(\WorkOS\UserManagement::class)
        ->and($this->workos->sso())->toBeInstanceOf(\WorkOS\SSO::class)
        ->and($this->workos->organizations())->toBeInstanceOf(\WorkOS\Organizations::class)
        ->and($this->workos->directorySync())->toBeInstanceOf(\WorkOS\DirectorySync::class)
        ->and($this->workos->auditLogs())->toBeInstanceOf(\WorkOS\AuditLogs::class)
        ->and($this->workos->webhook())->toBeInstanceOf(\WorkOS\Webhook::class);
});

it('caches SDK service instances', function () {
    $first = $this->workos->userManagement();
    $second = $this->workos->userManagement();

    expect($first)->toBe($second);
});

it('throws for unsupported services', function () {
    $this->workos->nonExistentService();
})->throws(InvalidArgumentException::class, 'WorkOS service [nonExistentService] is not supported');

it('returns session from manager', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    $this->sessionManager->shouldReceive('getSession')
        ->once()
        ->andReturn($session);

    expect($this->workos->session())->toBe($session);
});

it('returns valid session from manager', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn($session);

    expect($this->workos->validSession())->toBe($session);
});

it('checks authentication status', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn($session);

    expect($this->workos->isAuthenticated())->toBeTrue();
});

it('checks not authenticated when no session', function () {
    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn(null);

    expect($this->workos->isAuthenticated())->toBeFalse();
});

it('checks impersonation status', function () {
    $this->sessionManager->shouldReceive('isImpersonating')
        ->once()
        ->andReturn(true);

    expect($this->workos->isImpersonating())->toBeTrue();
});

it('checks permissions', function () {
    $this->sessionManager->shouldReceive('hasPermission')
        ->with('read')
        ->once()
        ->andReturn(true);

    expect($this->workos->hasPermission('read'))->toBeTrue();
});

it('checks roles', function () {
    $this->sessionManager->shouldReceive('hasRole')
        ->with('admin')
        ->once()
        ->andReturn(true);

    expect($this->workos->hasRole('admin'))->toBeTrue();
});

it('stores session', function () {
    $authResponse = [
        'access_token' => 'token_abc',
        'user' => ['id' => 'user_123'],
        'expires_at' => '2024-01-15T13:00:00Z',
    ];

    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );

    $this->sessionManager->shouldReceive('store')
        ->with($authResponse)
        ->once()
        ->andReturn($session);

    expect($this->workos->storeSession($authResponse))->toBe($session);
});

it('destroys session', function () {
    $this->sessionManager->shouldReceive('destroy')
        ->once();

    $this->workos->destroySession();
});
