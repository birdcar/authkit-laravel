<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use WorkOS\AuthKit\Tests\Fixtures\TestUser;
use WorkOS\AuthKit\Tests\Helpers\WorkOSSessionFactory;
use WorkOS\AuthKit\Exceptions\MissingPermissionException;
use WorkOS\AuthKit\Http\Middleware\CheckPermission;

it('passes when user has required permission', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withPermissions(['read']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckPermission;
    $response = $middleware->handle($request, fn ($req) => response('OK'), 'read');

    expect($response->getContent())->toBe('OK');
});

it('passes when user has all required permissions', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withPermissions(['read', 'write', 'delete']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckPermission;
    $response = $middleware->handle($request, fn ($req) => response('OK'), 'read', 'write');

    expect($response->getContent())->toBe('OK');
});

it('throws exception when user is not authenticated', function () {
    $request = Request::create('/test');
    $request->setUserResolver(fn () => null);

    $middleware = new CheckPermission;
    $middleware->handle($request, fn ($req) => response('OK'), 'read');
})->throws(MissingPermissionException::class, 'Unauthenticated');

it('throws exception when user is missing trait', function () {
    $user = new stdClass;

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckPermission;
    $middleware->handle($request, fn ($req) => response('OK'), 'read');
})->throws(MissingPermissionException::class, 'User model missing HasWorkOSPermissions trait');

it('throws exception when user is missing one of required permissions', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withPermissions(['read']));

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckPermission;
    $middleware->handle($request, fn ($req) => response('OK'), 'read', 'write');
})->throws(MissingPermissionException::class);

it('throws exception with permission list in message', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckPermission;

    try {
        $middleware->handle($request, fn ($req) => response('OK'), 'read', 'write');
    } catch (MissingPermissionException $e) {
        expect($e->getMessage())->toContain('read')
            ->and($e->getMessage())->toContain('write')
            ->and($e->permissions)->toBe(['read', 'write']);

        return;
    }

    $this->fail('Expected MissingPermissionException was not thrown');
});

it('returns 403 status code', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new CheckPermission;

    try {
        $middleware->handle($request, fn ($req) => response('OK'), 'read');
    } catch (MissingPermissionException $e) {
        expect($e->getStatusCode())->toBe(403);

        return;
    }

    $this->fail('Expected MissingPermissionException was not thrown');
});
