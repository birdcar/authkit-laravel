<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSGuard;
use WorkOS\AuthKit\Auth\WorkOSSession;

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
    $this->userProvider = Mockery::mock(UserProvider::class);
    $this->sessionManager = Mockery::mock(SessionManager::class);
    $this->request = Request::create('/');

    $this->guard = new WorkOSGuard(
        $this->userProvider,
        $this->sessionManager,
        $this->request
    );
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('returns null when no valid session', function () {
    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn(null);

    expect($this->guard->user())->toBeNull();
});

it('returns user when valid session exists', function () {
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

    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(1);

    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn($session);

    $this->userProvider->shouldReceive('retrieveById')
        ->with('user_123')
        ->once()
        ->andReturn($user);

    expect($this->guard->user())->toBe($user);
});

it('attaches session to user when trait present', function () {
    $session = new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: null,
        roles: ['admin'],
        permissions: ['read'],
        organizationId: null,
        impersonator: null,
    );

    $user = new class implements Authenticatable
    {
        public ?WorkOSSession $workosSession = null;

        public function setWorkOSSession(WorkOSSession $session): void
        {
            $this->workosSession = $session;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 1;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn($session);

    $this->userProvider->shouldReceive('retrieveById')
        ->with('user_123')
        ->once()
        ->andReturn($user);

    $result = $this->guard->user();

    expect($result->workosSession)->toBe($session)
        ->and($result->workosSession->roles)->toBe(['admin']);
});

it('caches user lookup', function () {
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

    $user = Mockery::mock(Authenticatable::class);

    $this->sessionManager->shouldReceive('getValidSession')
        ->once()
        ->andReturn($session);

    $this->userProvider->shouldReceive('retrieveById')
        ->with('user_123')
        ->once()
        ->andReturn($user);

    $this->guard->user();
    $result = $this->guard->user();

    expect($result)->toBe($user);
});

it('check returns true when authenticated', function () {
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

    $user = Mockery::mock(Authenticatable::class);

    $this->sessionManager->shouldReceive('getValidSession')
        ->andReturn($session);

    $this->userProvider->shouldReceive('retrieveById')
        ->andReturn($user);

    expect($this->guard->check())->toBeTrue();
});

it('check returns false when not authenticated', function () {
    $this->sessionManager->shouldReceive('getValidSession')
        ->andReturn(null);

    expect($this->guard->check())->toBeFalse();
});

it('guest returns opposite of check', function () {
    $this->sessionManager->shouldReceive('getValidSession')
        ->andReturn(null);

    expect($this->guard->guest())->toBeTrue();
});

it('validates by checking session exists', function () {
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
        ->andReturn($session);

    expect($this->guard->validate())->toBeTrue();
});

it('allows setting user manually', function () {
    $user = Mockery::mock(Authenticatable::class);

    $this->guard->setUser($user);

    expect($this->guard->hasUser())->toBeTrue();
});

it('returns auth identifier', function () {
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

    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(42);

    $this->sessionManager->shouldReceive('getValidSession')
        ->andReturn($session);

    $this->userProvider->shouldReceive('retrieveById')
        ->andReturn($user);

    expect($this->guard->id())->toBe(42);
});
