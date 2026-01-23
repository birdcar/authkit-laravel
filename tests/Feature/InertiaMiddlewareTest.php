<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Http\Middleware\ShareWorkOSData;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class InertiaTestUser extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $fillable = ['workos_id', 'email', 'name'];

    public $id = 1;

    public $workos_id = 'user_inertia_123';

    public $email = 'inertia@example.com';

    public $name = 'Inertia User';
}

function createInertiaTestSession(
    ?string $organizationId = null,
    array $roles = [],
    array $permissions = [],
    ?array $impersonator = null,
): WorkOSSession {
    return new WorkOSSession(
        userId: 'user_inertia_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: $roles,
        permissions: $permissions,
        organizationId: $organizationId,
        impersonator: $impersonator,
    );
}

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('middleware passes through when Inertia is not installed', function () {
    $sessionManager = $this->mock(SessionManager::class);
    $middleware = new ShareWorkOSData($sessionManager);

    $request = Request::create('/test');
    $called = false;

    $response = $middleware->handle($request, function ($req) use (&$called) {
        $called = true;

        return response('OK');
    });

    expect($called)->toBeTrue();
    expect($response->getContent())->toBe('OK');
});

it('middleware builds correct unauthenticated data structure', function () {
    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getSession')->andReturn(null);

    $middleware = new ShareWorkOSData($sessionManager);

    // Access private method via reflection
    $reflection = new ReflectionClass($middleware);
    $method = $reflection->getMethod('getAuthData');
    $method->setAccessible(true);

    $request = Request::create('/test');
    // No user set on request

    $data = $method->invoke($middleware, $request);

    expect($data['check'])->toBeFalse();
    expect($data['user'])->toBeNull();
    expect($data['roles'])->toBe([]);
    expect($data['permissions'])->toBe([]);
    expect($data['organization'])->toBeNull();
    expect($data['impersonating'])->toBeFalse();
});

it('middleware builds correct authenticated data structure', function () {
    $session = createInertiaTestSession(
        organizationId: 'org_123',
        roles: ['admin', 'user'],
        permissions: ['users.read', 'users.write'],
    );

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getSession')->andReturn($session);

    $middleware = new ShareWorkOSData($sessionManager);

    $reflection = new ReflectionClass($middleware);
    $method = $reflection->getMethod('getAuthData');
    $method->setAccessible(true);

    $user = new InertiaTestUser;
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $data = $method->invoke($middleware, $request);

    expect($data['check'])->toBeTrue();
    expect($data['user']['id'])->toBe('user_inertia_123'); // HasWorkOSId trait overrides getAuthIdentifier
    expect($data['user']['workos_id'])->toBe('user_inertia_123');
    expect($data['user']['name'])->toBe('Inertia User');
    expect($data['user']['email'])->toBe('inertia@example.com');
    expect($data['roles'])->toBe(['admin', 'user']);
    expect($data['permissions'])->toBe(['users.read', 'users.write']);
    expect($data['organization'])->toBe('org_123');
    expect($data['impersonating'])->toBeFalse();
});

it('middleware includes impersonation data when impersonating', function () {
    $impersonator = ['email' => 'admin@example.com', 'reason' => 'Support'];
    $session = createInertiaTestSession(
        impersonator: $impersonator,
    );

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getSession')->andReturn($session);

    $middleware = new ShareWorkOSData($sessionManager);

    $reflection = new ReflectionClass($middleware);
    $method = $reflection->getMethod('getAuthData');
    $method->setAccessible(true);

    $user = new InertiaTestUser;
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $data = $method->invoke($middleware, $request);

    expect($data['impersonating'])->toBeTrue();
    expect($data['impersonator'])->toBe($impersonator);
});

it('middleware handles user without getWorkOSId method', function () {
    $session = createInertiaTestSession();

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getSession')->andReturn($session);

    $middleware = new ShareWorkOSData($sessionManager);

    $reflection = new ReflectionClass($middleware);
    $method = $reflection->getMethod('getAuthData');
    $method->setAccessible(true);

    // Create a user without the HasWorkOSId trait
    $user = new class extends Authenticatable
    {
        public $id = 2;

        public $email = 'basic@example.com';

        public $name = 'Basic User';
    };

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $data = $method->invoke($middleware, $request);

    expect($data['check'])->toBeTrue();
    expect($data['user']['workos_id'])->toBeNull();
});

it('middleware handles null session gracefully', function () {
    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getSession')->andReturn(null);

    $middleware = new ShareWorkOSData($sessionManager);

    $reflection = new ReflectionClass($middleware);
    $method = $reflection->getMethod('getAuthData');
    $method->setAccessible(true);

    $user = new InertiaTestUser;
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $data = $method->invoke($middleware, $request);

    expect($data['check'])->toBeTrue();
    expect($data['roles'])->toBe([]);
    expect($data['permissions'])->toBe([]);
    expect($data['organization'])->toBeNull();
    expect($data['impersonating'])->toBeFalse();
});

it('registers workos.inertia middleware alias', function () {
    $router = app('router');

    expect(
        $router->hasMiddlewareGroup('workos.inertia') ||
        isset($router->getMiddleware()['workos.inertia'])
    )->toBeTrue();
});
