<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Contracts\Session\Session;
use WorkOS\AuditLogs;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\Concerns\HasAuditTrail;
use WorkOS\AuthKit\Audit\Contracts\Auditable;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;

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
    $this->auditLogs = Mockery::mock(AuditLogs::class);
    $this->sessionStore = Mockery::mock(Session::class);
    $this->sessionManager = new SessionManager($this->sessionStore);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

function createAuditSession(?string $organizationId = 'org_test_123'): array
{
    return [
        'user_id' => 'user_123',
        'access_token' => 'token_abc',
        'refresh_token' => null,
        'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        'session_id' => 'session_456',
        'roles' => [],
        'permissions' => [],
        'organization_id' => $organizationId,
        'impersonator' => null,
    ];
}

it('is a no-op when feature is disabled', function () {
    config(['workos.features.audit_logs' => false]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());
    $this->auditLogs->shouldNotReceive('createEvent');

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('user.login');
});

it('is a no-op when no organization context', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession(null));
    $this->auditLogs->shouldNotReceive('createEvent');

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('user.login');
});

it('sends event when feature is enabled and has organization', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->withArgs(function ($orgId, $event) {
            return $orgId === 'org_test_123'
                && $event['action']['type'] === 'user.login'
                && $event['action']['name'] === 'User login';
        });

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('user.login');
});

it('allows override of actor id', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->withArgs(function ($orgId, $event) {
            return $event['actor']['id'] === 'custom_actor_id';
        });

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('user.login', actorId: 'custom_actor_id');
});

it('normalizes auditable model targets', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

    $model = new AuditableModel;

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->withArgs(function ($orgId, $event) {
            return count($event['targets']) === 1
                && $event['targets'][0]['type'] === 'auditablemodel'
                && $event['targets'][0]['id'] === '42'
                && $event['targets'][0]['name'] === 'Test Model';
        });

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('resource.update', targets: [$model]);
});

it('normalizes array targets', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->withArgs(function ($orgId, $event) {
            return count($event['targets']) === 1
                && $event['targets'][0]['type'] === 'document'
                && $event['targets'][0]['id'] === '123'
                && $event['targets'][0]['name'] === 'My Doc';
        });

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('document.view', targets: [
        ['type' => 'document', 'id' => 123, 'name' => 'My Doc'],
    ]);
});

it('includes metadata', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->withArgs(function ($orgId, $event) {
            return $event['metadata']['custom_key'] === 'custom_value';
        });

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
    $logger->log('user.action', metadata: ['custom_key' => 'custom_value']);
});

it('catches and reports API exceptions', function () {
    config(['workos.features.audit_logs' => true]);

    $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

    $exception = new Exception('API Error');

    $this->auditLogs->shouldReceive('createEvent')
        ->once()
        ->andThrow($exception);

    $logger = new AuditLogger($this->auditLogs, $this->sessionManager);

    // Should not throw
    $logger->log('user.action');

    expect(true)->toBeTrue();
});

it('humanizes action names correctly', function () {
    config(['workos.features.audit_logs' => true]);

    $testCases = [
        'user.login' => 'User login',
        'document_created' => 'Document created',
        'api.key.rotated' => 'Api key rotated',
    ];

    foreach ($testCases as $input => $expected) {
        $this->sessionStore->shouldReceive('get')->with('workos_session')->andReturn(createAuditSession());

        $this->auditLogs->shouldReceive('createEvent')
            ->once()
            ->withArgs(function ($orgId, $event) use ($expected) {
                return $event['action']['name'] === $expected;
            });

        $logger = new AuditLogger($this->auditLogs, $this->sessionManager);
        $logger->log($input);
    }
});
