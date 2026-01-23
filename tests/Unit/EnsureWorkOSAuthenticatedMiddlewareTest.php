<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Http\Middleware\EnsureWorkOSAuthenticated;

beforeEach(function () {
    Route::get('/login', fn () => 'login')->name('login');
});

function createValidSession(): WorkOSSession
{
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: [],
        permissions: [],
        organizationId: null,
        impersonator: null,
    );
}

it('passes when session is valid', function () {
    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->once()->andReturn(createValidSession());

    $request = Request::create('/test');

    $middleware = new EnsureWorkOSAuthenticated($sessionManager);
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

it('redirects to login when no session', function () {
    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->once()->andReturnNull();

    $request = Request::create('/test');

    $middleware = new EnsureWorkOSAuthenticated($sessionManager);
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('login');
});

it('redirects to custom url when specified', function () {
    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->once()->andReturnNull();

    Route::get('/custom-login', fn () => 'custom login')->name('custom.login');

    $request = Request::create('/test');

    $middleware = new EnsureWorkOSAuthenticated($sessionManager);
    $response = $middleware->handle($request, fn ($req) => response('OK'), route('custom.login'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('custom-login');
});

it('returns json response for api requests when no session', function () {
    $sessionManager = Mockery::mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->once()->andReturnNull();

    $request = Request::create('/api/test');
    $request->headers->set('Accept', 'application/json');

    $middleware = new EnsureWorkOSAuthenticated($sessionManager);
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true))->toBe(['message' => 'Unauthenticated.']);
});
