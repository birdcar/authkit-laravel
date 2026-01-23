<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Contracts\Session\Session;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
    $this->mockSession = Mockery::mock(Session::class);
    $this->sessionManager = new SessionManager($this->mockSession);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('returns null when no session exists', function () {
    $this->mockSession->shouldReceive('get')
        ->with('workos_session')
        ->andReturn(null);

    expect($this->sessionManager->getSession())->toBeNull();
});

it('returns session when it exists', function () {
    $sessionData = [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => 'refresh_xyz',
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => 'session_456',
        'roles' => ['admin'],
        'permissions' => ['read'],
        'organization_id' => 'org_789',
        'impersonator' => null,
    ];

    $this->mockSession->shouldReceive('get')
        ->with('workos_session')
        ->andReturn($sessionData);

    $session = $this->sessionManager->getSession();

    expect($session)->toBeInstanceOf(WorkOSSession::class)
        ->and($session->userId)->toBe('user_123');
});

it('stores auth response as session', function () {
    $authResponse = [
        'access_token' => 'token_abc',
        'refresh_token' => 'refresh_xyz',
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => 'session_456',
        'organization_id' => 'org_789',
        'user' => [
            'id' => 'user_123',
            'roles' => [],
            'permissions' => [],
        ],
    ];

    $this->mockSession->shouldReceive('put')
        ->once()
        ->withArgs(function ($key, $value) {
            return $key === 'workos_session'
                && $value['user_id'] === 'user_123'
                && $value['access_token'] === 'token_abc';
        });

    $session = $this->sessionManager->store($authResponse);

    expect($session)->toBeInstanceOf(WorkOSSession::class)
        ->and($session->userId)->toBe('user_123');
});

it('destroys session', function () {
    $this->mockSession->shouldReceive('forget')
        ->once()
        ->with('workos_session');

    $this->sessionManager->destroy();
});

it('detects impersonation', function () {
    $sessionData = [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => null,
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => null,
        'roles' => [],
        'permissions' => [],
        'organization_id' => null,
        'impersonator' => ['email' => 'admin@example.com'],
    ];

    $this->mockSession->shouldReceive('get')
        ->with('workos_session')
        ->andReturn($sessionData);

    expect($this->sessionManager->isImpersonating())->toBeTrue();
});

it('checks permissions', function () {
    $sessionData = [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => null,
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => null,
        'roles' => [],
        'permissions' => ['read', 'write'],
        'organization_id' => null,
        'impersonator' => null,
    ];

    $this->mockSession->shouldReceive('get')
        ->with('workos_session')
        ->andReturn($sessionData);

    expect($this->sessionManager->hasPermission('read'))->toBeTrue()
        ->and($this->sessionManager->hasPermission('delete'))->toBeFalse();
});

it('checks roles', function () {
    $sessionData = [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => null,
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => null,
        'roles' => ['admin'],
        'permissions' => [],
        'organization_id' => null,
        'impersonator' => null,
    ];

    $this->mockSession->shouldReceive('get')
        ->with('workos_session')
        ->andReturn($sessionData);

    expect($this->sessionManager->hasRole('admin'))->toBeTrue()
        ->and($this->sessionManager->hasRole('viewer'))->toBeFalse();
});

it('returns valid session when not expired', function () {
    $sessionData = [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => 'refresh_xyz',
        'expires_at' => '2024-01-15T13:00:00Z',
        'session_id' => null,
        'roles' => [],
        'permissions' => [],
        'organization_id' => null,
        'impersonator' => null,
    ];

    $this->mockSession->shouldReceive('get')
        ->with('workos_session')
        ->andReturn($sessionData);

    $session = $this->sessionManager->getValidSession();

    expect($session)->toBeInstanceOf(WorkOSSession::class)
        ->and($session->userId)->toBe('user_123');
});
