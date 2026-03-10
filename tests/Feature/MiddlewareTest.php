<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Exceptions\MissingPermissionException;
use WorkOS\AuthKit\Exceptions\MissingRoleException;

beforeEach(function () {
    Route::middleware(['workos.auth'])->get('/protected', fn () => 'OK');
    Route::middleware(['workos.role:admin'])->get('/admin', fn () => 'Admin');
    Route::middleware(['workos.role:admin,editor'])->get('/content', fn () => 'Content');
    Route::middleware(['workos.permission:read'])->get('/readable', fn () => 'Readable');
    Route::middleware(['workos.permission:read,write'])->get('/writable', fn () => 'Writable');
    Route::middleware(['workos.impersonation'])->get('/impersonation-check', function () {
        return response()->json([
            'impersonating' => request()->attributes->get('workos_impersonating', false),
            'impersonator' => request()->attributes->get('workos_impersonator'),
        ]);
    });
});

function createMiddlewareTestSession(
    array $roles = [],
    array $permissions = [],
    ?array $impersonator = null
): WorkOSSession {
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: $roles,
        permissions: $permissions,
        organizationId: 'org_789',
        impersonator: $impersonator,
    );
}

it('blocks unauthenticated access to protected routes', function () {
    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturnNull();

    $response = $this->get('/protected');

    $response->assertRedirect(route('login'));
});

it('returns 401 for unauthenticated api requests', function () {
    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturnNull();

    $response = $this->getJson('/protected');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('allows authenticated access to protected routes', function () {
    $session = createMiddlewareTestSession();

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);

    $response = $this->get('/protected');

    $response->assertOk()
        ->assertSee('OK');
});

it('blocks access when user lacks required role', function () {
    $session = createMiddlewareTestSession(roles: ['viewer']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $this->withoutExceptionHandling();

    $this->expectException(MissingRoleException::class);

    $this->actingAsWorkOSUser($session)->get('/admin');
});

it('allows access when user has required role', function () {
    $session = createMiddlewareTestSession(roles: ['admin']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $response = $this->actingAsWorkOSUser($session)->get('/admin');

    $response->assertOk()
        ->assertSee('Admin');
});

it('allows access when user has any of required roles', function () {
    $session = createMiddlewareTestSession(roles: ['editor']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $response = $this->actingAsWorkOSUser($session)->get('/content');

    $response->assertOk()
        ->assertSee('Content');
});

it('blocks access when user lacks required permission', function () {
    $session = createMiddlewareTestSession(permissions: ['view']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $this->withoutExceptionHandling();

    $this->expectException(MissingPermissionException::class);

    $this->actingAsWorkOSUser($session)->get('/readable');
});

it('allows access when user has required permission', function () {
    $session = createMiddlewareTestSession(permissions: ['read']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $response = $this->actingAsWorkOSUser($session)->get('/readable');

    $response->assertOk()
        ->assertSee('Readable');
});

it('blocks access when user lacks all required permissions', function () {
    $session = createMiddlewareTestSession(permissions: ['read']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $this->withoutExceptionHandling();

    $this->expectException(MissingPermissionException::class);

    $this->actingAsWorkOSUser($session)->get('/writable');
});

it('allows access when user has all required permissions', function () {
    $session = createMiddlewareTestSession(permissions: ['read', 'write']);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $response = $this->actingAsWorkOSUser($session)->get('/writable');

    $response->assertOk()
        ->assertSee('Writable');
});

it('detects impersonation via middleware', function () {
    $impersonator = ['email' => 'admin@example.com'];
    $session = createMiddlewareTestSession(impersonator: $impersonator);

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnTrue();

    $response = $this->actingAsWorkOSUser($session)->get('/impersonation-check');

    $response->assertOk()
        ->assertJson([
            'impersonating' => true,
            'impersonator' => $impersonator,
        ]);
});

it('does not set impersonation when not impersonating', function () {
    $session = createMiddlewareTestSession();

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('isImpersonating')->andReturnFalse();

    $response = $this->actingAsWorkOSUser($session)->get('/impersonation-check');

    $response->assertOk()
        ->assertJson([
            'impersonating' => false,
            'impersonator' => null,
        ]);
});
