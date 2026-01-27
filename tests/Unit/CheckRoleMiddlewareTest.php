<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use WorkOS\AuthKit\Exceptions\MissingRoleException;
use WorkOS\AuthKit\Http\Middleware\CheckRole;
use WorkOS\AuthKit\Tests\Fixtures\TestUser;
use WorkOS\AuthKit\Tests\Helpers\WorkOSSessionFactory;

it('passes when user has required role', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withRoles(['admin']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;
    $response = $middleware->handle($request, fn ($req) => response('OK'), 'admin');

    expect($response->getContent())->toBe('OK');
});

it('passes when user has any of required roles', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withRoles(['editor']));

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
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withRoles(['viewer']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckRole;
    $middleware->handle($request, fn ($req) => response('OK'), 'admin');
})->throws(MissingRoleException::class);

it('throws exception with role list in message', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

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
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

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
