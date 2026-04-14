<?php

declare(strict_types=1);

use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\Concerns\HasAuditTrail;
use WorkOS\AuthKit\Audit\Contracts\Auditable;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Testing\WorkOSFake;
use WorkOS\WorkOS;

class IntegrationAuditableModel implements Auditable
{
    use HasAuditTrail;

    public string $name = 'Test Resource';

    public function getKey(): int
    {
        return 42;
    }
}

function createAuditTestSession(
    ?string $organizationId = null
): WorkOSSession {
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: 'refresh_xyz',
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: [],
        permissions: [],
        organizationId: $organizationId,
        impersonator: null,
    );
}

function createAuditSdkClient(MockHandler $mock): WorkOS
{
    return new WorkOS(
        apiKey: 'sk_test_key',
        clientId: 'client_test_123',
        handler: HandlerStack::create($mock),
    );
}

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
    config(['workos.features.audit_logs' => true]);

    Route::middleware(['workos.audit'])->get('/audit-test', fn () => 'OK');
    Route::middleware(['workos.audit:custom.action'])->get('/audit-custom', fn () => 'Custom');
    Route::middleware(['workos.audit'])->get('/audit-test/{id}', fn ($id) => "Resource {$id}");
    Route::middleware(['workos.audit'])->get('/audit-fail', fn () => abort(500));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('registers audit middleware alias', function () {
    $router = app('router');

    expect($router->hasMiddlewareGroup('workos.audit') || isset($router->getMiddleware()['workos.audit']))->toBeTrue();
});

it('logs audit event through middleware on successful request', function () {
    $session = createAuditTestSession(organizationId: 'org_test_123');

    $guzzleMock = new MockHandler([new Response(200, [], json_encode(['success' => true]))]);

    $this->app->singleton(AuditLogger::class, function ($app) use ($guzzleMock) {
        return new AuditLogger(
            createAuditSdkClient($guzzleMock),
            $app->make(SessionManager::class)
        );
    });

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');

    $response = $this->actingAsWorkOSUser($session)->get('/audit-test');

    $response->assertOk();
    expect($guzzleMock->count())->toBe(0);
});

it('does not log audit event on failed request', function () {
    $session = createAuditTestSession(organizationId: 'org_test_123');

    $guzzleMock = new MockHandler;

    $this->app->singleton(AuditLogger::class, function ($app) use ($guzzleMock) {
        return new AuditLogger(
            createAuditSdkClient($guzzleMock),
            $app->make(SessionManager::class)
        );
    });

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);

    $response = $this->actingAsWorkOSUser($session)->get('/audit-fail');

    $response->assertStatus(500);
});

it('uses custom action when specified in middleware', function () {
    $session = createAuditTestSession(organizationId: 'org_test_123');

    $history = [];
    $guzzleMock = new MockHandler([new Response(200, [], json_encode(['success' => true]))]);
    $handler = HandlerStack::create($guzzleMock);
    $handler->push(Middleware::history($history));

    $sdkClient = new WorkOS(apiKey: 'sk_test_key', clientId: 'client_test_123', handler: $handler);

    $this->app->singleton(AuditLogger::class, function ($app) use ($sdkClient) {
        return new AuditLogger($sdkClient, $app->make(SessionManager::class));
    });

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');

    $response = $this->actingAsWorkOSUser($session)->get('/audit-custom');

    $response->assertOk();
    expect($history)->toHaveCount(1);
    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['event']['action'])->toBe('custom.action');
});

it('includes organization id in audit event', function () {
    $session = createAuditTestSession(organizationId: 'org_test_123');

    $history = [];
    $guzzleMock = new MockHandler([new Response(200, [], json_encode(['success' => true]))]);
    $handler = HandlerStack::create($guzzleMock);
    $handler->push(Middleware::history($history));

    $sdkClient = new WorkOS(apiKey: 'sk_test_key', clientId: 'client_test_123', handler: $handler);

    $this->app->singleton(AuditLogger::class, function ($app) use ($sdkClient) {
        return new AuditLogger($sdkClient, $app->make(SessionManager::class));
    });

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');

    $response = $this->actingAsWorkOSUser($session)->get('/audit-test');

    $response->assertOk();
    expect($history)->toHaveCount(1);
    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['organization_id'])->toBe('org_test_123');
});

it('is no-op when feature is disabled', function () {
    config(['workos.features.audit_logs' => false]);

    $session = createAuditTestSession(organizationId: 'org_test_123');
    $guzzleMock = new MockHandler;

    $this->app->singleton(AuditLogger::class, function ($app) use ($guzzleMock) {
        return new AuditLogger(
            createAuditSdkClient($guzzleMock),
            $app->make(SessionManager::class)
        );
    });

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);

    $response = $this->actingAsWorkOSUser($session)->get('/audit-test');

    $response->assertOk();
});

it('is no-op when no organization context', function () {
    $session = createAuditTestSession(organizationId: null);
    $guzzleMock = new MockHandler;

    $this->app->singleton(AuditLogger::class, function ($app) use ($guzzleMock) {
        return new AuditLogger(
            createAuditSdkClient($guzzleMock),
            $app->make(SessionManager::class)
        );
    });

    $sessionManager = $this->mock(SessionManager::class);
    $sessionManager->shouldReceive('getValidSession')->andReturn($session);
    $sessionManager->shouldReceive('getSession')->andReturn($session);
    $sessionManager->shouldReceive('getOrganizationId')->andReturn(null);

    $response = $this->actingAsWorkOSUser($session)->get('/audit-test');

    $response->assertOk();
});

// WorkOSFake tests
it('WorkOSFake captures audit events', function () {
    $fake = new WorkOSFake;

    $fake->audit('user.login', [['type' => 'user', 'id' => '123']]);
    $fake->audit('document.view', [['type' => 'document', 'id' => '456']]);

    expect($fake->getAuditedEvents())->toHaveCount(2);
});

it('WorkOSFake assertAudited passes when event logged', function () {
    $fake = new WorkOSFake;

    $fake->audit('user.login');

    $fake->assertAudited('user.login');
});

it('WorkOSFake assertAudited with callback passes when callback returns true', function () {
    $fake = new WorkOSFake;

    $fake->audit('user.login', [['type' => 'user', 'id' => '123']], ['ip' => '127.0.0.1']);

    $fake->assertAudited('user.login', function ($event) {
        return $event['metadata']['ip'] === '127.0.0.1';
    });
});

it('WorkOSFake assertNotAudited passes when event not logged', function () {
    $fake = new WorkOSFake;

    $fake->audit('user.login');

    $fake->assertNotAudited('user.logout');
});

it('WorkOSFake assertAuditedCount verifies count', function () {
    $fake = new WorkOSFake;

    $fake->audit('event.one');
    $fake->audit('event.two');
    $fake->audit('event.three');

    $fake->assertAuditedCount(3);
});

it('WorkOSFake clearAuditedEvents resets state', function () {
    $fake = new WorkOSFake;

    $fake->audit('event.one');
    $fake->audit('event.two');

    $fake->clearAuditedEvents();

    expect($fake->getAuditedEvents())->toBeEmpty();
});

// HasAuditTrail trait tests
it('HasAuditTrail generates correct audit target', function () {
    $model = new IntegrationAuditableModel;

    $target = $model->toAuditTarget();

    expect($target['type'])->toBe('integrationauditablemodel');
    expect($target['id'])->toBe('42');
    expect($target['name'])->toBe('Test Resource');
});
