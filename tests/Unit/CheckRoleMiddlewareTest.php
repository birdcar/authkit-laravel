<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Exceptions\MissingRoleException;
use WorkOS\AuthKit\Http\Middleware\CheckRole;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class RoleTestUser
{
    use HasWorkOSPermissions;

    public function getAuthIdentifier(): string
    {
        return 'user_123';
    }
}

function createRoleTestSession(array $roles = []): WorkOSSession
{
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: $roles,
        permissions: [],
        organizationId: null,
        impersonator: null,
    );
}

it('passes when user has required role', function () {
    $user = new RoleTestUser;
    $user->setWorkOSSession(createRoleTestSession(roles: ['admin']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;
    $response = $middleware->handle($request, fn ($req) => response('OK'), 'admin');

    expect($response->getContent())->toBe('OK');
});

it('passes when user has any of required roles', function () {
    $user = new RoleTestUser;
    $user->setWorkOSSession(createRoleTestSession(roles: ['editor']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;
    $response = $middleware->handle($request, fn ($req) => response('OK'), 'admin', 'editor');

    expect($response->getContent())->toBe('OK');
});

it('throws exception when user is not authenticated', function () {
    $request = Request::create('/test');
    $request->setUserResolver(fn () => null);

    $middleware = new CheckRole;
    $middleware->handle($request, fn ($req) => response('OK'), 'admin');
})->throws(MissingRoleException::class, 'Unauthenticated');

it('throws exception when user is missing trait', function () {
    $user = new stdClass;

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;
    $middleware->handle($request, fn ($req) => response('OK'), 'admin');
})->throws(MissingRoleException::class, 'User model missing HasWorkOSPermissions trait');

it('throws exception when user does not have required role', function () {
    $user = new RoleTestUser;
    $user->setWorkOSSession(createRoleTestSession(roles: ['viewer']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;
    $middleware->handle($request, fn ($req) => response('OK'), 'admin');
})->throws(MissingRoleException::class);

it('throws exception with role list in message', function () {
    $user = new RoleTestUser;
    $user->setWorkOSSession(createRoleTestSession(roles: []));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;

    try {
        $middleware->handle($request, fn ($req) => response('OK'), 'admin', 'editor');
    } catch (MissingRoleException $e) {
        expect($e->getMessage())->toContain('admin')
            ->and($e->getMessage())->toContain('editor')
            ->and($e->roles)->toBe(['admin', 'editor']);

        return;
    }

    $this->fail('Expected MissingRoleException was not thrown');
});

it('returns 403 status code', function () {
    $user = new RoleTestUser;
    $user->setWorkOSSession(createRoleTestSession(roles: []));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;

    try {
        $middleware->handle($request, fn ($req) => response('OK'), 'admin');
    } catch (MissingRoleException $e) {
        expect($e->getStatusCode())->toBe(403);

        return;
    }

    $this->fail('Expected MissingRoleException was not thrown');
});
