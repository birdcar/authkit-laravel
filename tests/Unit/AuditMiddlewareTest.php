<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\AuditMiddleware;
use WorkOS\AuthKit\Audit\Concerns\HasAuditTrail;
use WorkOS\AuthKit\Audit\Contracts\Auditable;

class MiddlewareAuditableModel implements Auditable
{
    use HasAuditTrail;

    public string $name = 'Test Resource';

    public function getKey(): int
    {
        return 99;
    }
}

beforeEach(function () {
    $this->logger = Mockery::mock(AuditLogger::class);
});

afterEach(function () {
    Mockery::close();
});

function createBoundRoute(string $method, string $uri, ?string $name = null): Route
{
    $route = new Route($method, $uri, fn () => 'OK');
    if ($name !== null) {
        $route->name($name);
    }
    // Bind route with empty parameters so parameters() doesn't throw
    $request = Request::create($uri, $method);
    $route->bind($request);

    return $route;
}

it('logs audit event on successful response', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action, $targets, $actorId, $metadata) {
            return str_contains($action, '.read')
                && is_array($metadata)
                && isset($metadata['method'])
                && $metadata['method'] === 'GET';
        });

    $request = Request::create('/test', 'GET');
    $route = createBoundRoute('GET', '/test', 'test.route');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->getStatusCode())->toBe(200);
});

it('does not log on failed response', function () {
    $this->logger->shouldNotReceive('log');

    $request = Request::create('/test', 'GET');
    $route = createBoundRoute('GET', '/test');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $response = $middleware->handle($request, fn () => new Response('Error', 500));

    expect($response->getStatusCode())->toBe(500);
});

it('does not log on 4xx response', function () {
    $this->logger->shouldNotReceive('log');

    $request = Request::create('/test', 'GET');
    $route = createBoundRoute('GET', '/test');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $response = $middleware->handle($request, fn () => new Response('Not Found', 404));

    expect($response->getStatusCode())->toBe(404);
});

it('uses explicit action when provided', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action) {
            return $action === 'custom.action';
        });

    $request = Request::create('/test', 'GET');
    $route = createBoundRoute('GET', '/test');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200), 'custom.action');
});

it('infers action from HTTP method - GET', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action) {
            return str_contains($action, '.read');
        });

    $request = Request::create('/test', 'GET');
    $route = createBoundRoute('GET', '/test', 'resource');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});

it('infers action from HTTP method - POST', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action) {
            return str_contains($action, '.create');
        });

    $request = Request::create('/test', 'POST');
    $route = createBoundRoute('POST', '/test', 'resource');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 201));
});

it('infers action from HTTP method - PUT', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action) {
            return str_contains($action, '.update');
        });

    $request = Request::create('/test', 'PUT');
    $route = createBoundRoute('PUT', '/test', 'resource');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});

it('infers action from HTTP method - PATCH', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action) {
            return str_contains($action, '.update');
        });

    $request = Request::create('/test', 'PATCH');
    $route = createBoundRoute('PATCH', '/test', 'resource');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});

it('infers action from HTTP method - DELETE', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action) {
            return str_contains($action, '.delete');
        });

    $request = Request::create('/test', 'DELETE');
    $route = createBoundRoute('DELETE', '/test', 'resource');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});

it('extracts auditable model targets from route parameters', function () {
    $model = new MiddlewareAuditableModel;

    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action, $targets) use ($model) {
            return count($targets) === 1
                && $targets[0] === $model;
        });

    $request = Request::create('/test/99', 'GET');
    $route = new Route('GET', '/test/{model}', fn () => 'OK');
    $route->bind($request);
    $route->setParameter('model', $model);
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});

it('extracts scalar targets from route parameters', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action, $targets) {
            return count($targets) === 1
                && $targets[0]['type'] === 'id'
                && $targets[0]['id'] === '123';
        });

    $request = Request::create('/test/123', 'GET');
    $route = new Route('GET', '/test/{id}', fn () => 'OK');
    $route->bind($request);
    $route->setParameter('id', '123');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});

it('includes route metadata in log', function () {
    $this->logger->shouldReceive('log')
        ->once()
        ->withArgs(function ($action, $targets, $actorId, $metadata) {
            return is_array($metadata)
                && $metadata['route'] === 'users.show'
                && $metadata['method'] === 'GET'
                && $metadata['path'] === 'users/123';
        });

    $request = Request::create('/users/123', 'GET');
    $route = new Route('GET', '/users/{id}', fn () => 'OK');
    $route->name('users.show');
    $route->bind($request);
    $route->setParameter('id', '123');
    $request->setRouteResolver(fn () => $route);

    $middleware = new AuditMiddleware($this->logger);
    $middleware->handle($request, fn () => new Response('OK', 200));
});
