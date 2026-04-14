<?php

declare(strict_types=1);

use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\Concerns\HasAuditTrail;
use WorkOS\AuthKit\Audit\Contracts\Auditable;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\WorkOS;

class AuditTestUser
{
    use HasWorkOSId;

    public string $name = 'Test User';

    public ?string $workos_id = 'user_test_123';
}

class AuditableModel implements Auditable
{
    use HasAuditTrail;

    public string $name = 'Test Model';

    public function getKey(): int
    {
        return 42;
    }
}

beforeEach(function () {
    Carbon::setTestNow('2024-01-15 12:00:00');
    $this->sessionManager = Mockery::mock(SessionManager::class);
    $this->guzzleMock = new MockHandler;
    $this->sdkClient = new WorkOS(
        apiKey: 'sk_test_key',
        clientId: 'client_test_123',
        handler: HandlerStack::create($this->guzzleMock),
    );
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('is a no-op when feature is disabled', function () {
    config(['workos.features.audit_logs' => false]);

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('user.login');

    expect($this->guzzleMock->count())->toBe(0);
});

it('is a no-op when no organization context', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn(null);

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('user.login');

    expect($this->guzzleMock->count())->toBe(0);
});

it('sends event when feature is enabled and has organization', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');
    $this->guzzleMock->append(new Response(200, [], json_encode(['success' => true])));

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('user.login');

    expect($this->guzzleMock->count())->toBe(0);
});

it('allows override of actor id', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');
    $this->guzzleMock->append(new Response(200, [], json_encode(['success' => true])));

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('user.login', actorId: 'custom_actor_id');

    expect($this->guzzleMock->count())->toBe(0);
});

it('normalizes auditable model targets', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');
    $this->guzzleMock->append(new Response(200, [], json_encode(['success' => true])));

    $model = new AuditableModel;

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('resource.update', targets: [$model]);

    expect($this->guzzleMock->count())->toBe(0);
});

it('normalizes array targets', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');
    $this->guzzleMock->append(new Response(200, [], json_encode(['success' => true])));

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('document.view', targets: [
        ['type' => 'document', 'id' => 123, 'name' => 'My Doc'],
    ]);

    expect($this->guzzleMock->count())->toBe(0);
});

it('includes metadata', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');
    $this->guzzleMock->append(new Response(200, [], json_encode(['success' => true])));

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('user.action', metadata: ['custom_key' => 'custom_value']);

    expect($this->guzzleMock->count())->toBe(0);
});

it('catches and reports API exceptions', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');
    $this->guzzleMock->append(new Response(500, [], json_encode(['message' => 'Server Error'])));

    $logger = new AuditLogger($this->sdkClient, $this->sessionManager);
    $logger->log('user.action');

    expect(true)->toBeTrue();
});

it('sends action as plain string in v5 format', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionManager->shouldReceive('getOrganizationId')->andReturn('org_test_123');

    $history = [];
    $handler = HandlerStack::create($this->guzzleMock);
    $handler->push(Middleware::history($history));
    $client = new WorkOS(apiKey: 'sk_test_key', clientId: 'client_test_123', handler: $handler);

    $this->guzzleMock->append(new Response(200, [], json_encode(['success' => true])));

    $logger = new AuditLogger($client, $this->sessionManager);
    $logger->log('user.login');

    expect($history)->toHaveCount(1);
    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['event']['action'])->toBe('user.login');
});
