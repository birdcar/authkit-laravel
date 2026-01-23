<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Http\Middleware\DetectImpersonation;

function createImpersonationTestSession(?array $impersonator = null): WorkOSSession
{
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: $impersonator,
    );
}

it('sets impersonation attributes when impersonating', function () {
    $impersonator = ['email' => 'admin@example.com'];
    $session = createImpersonationTestSession(impersonator: $impersonator);

    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('isImpersonating')->once()->andReturnTrue();
    $sessionManager->shouldReceive('getSession')->once()->andReturn($session);

    $request = Request::create('/test');

    $middleware = new DetectImpersonation($sessionManager);
    $middleware->handle($request, fn ($req) => response('OK'));

    expect($request->attributes->get('workos_impersonating'))->toBeTrue()
        ->and($request->attributes->get('workos_impersonator'))->toBe($impersonator);
});

it('does not set attributes when not impersonating', function () {
    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('isImpersonating')->once()->andReturnFalse();

    $request = Request::create('/test');

    $middleware = new DetectImpersonation($sessionManager);
    $middleware->handle($request, fn ($req) => response('OK'));

    expect($request->attributes->has('workos_impersonating'))->toBeFalse()
        ->and($request->attributes->has('workos_impersonator'))->toBeFalse();
});

it('passes request through to next handler', function () {
    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('isImpersonating')->once()->andReturnFalse();

    $request = Request::create('/test');

    $middleware = new DetectImpersonation($sessionManager);
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});
