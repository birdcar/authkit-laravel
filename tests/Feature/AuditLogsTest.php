<?php

declare(strict_types=1);

use Authkit\Authkit\AuditLogs\Exceptions\AuditLogSchemaMismatchException;
use Authkit\Authkit\AuditLogs\Exceptions\InvalidRetentionPeriodException;
use Authkit\Authkit\AuditLogs\Exceptions\MissingOrganizationContextException;
use Authkit\Authkit\AuditLogs\Jobs\CreateAuditLogEventJob;
use Authkit\Authkit\AuditLogs\Support\AuditActorResolver;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Facades\AuditLog;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Post;
use Workbench\Database\Factories\UserFactory;
use WorkOS\Resource\AuditLogAction;
use WorkOS\Resource\AuditLogSchema;
use WorkOS\Resource\AuditLogSchemaActorInput;
use WorkOS\Resource\AuditLogSchemaTargetInput;
use WorkOS\Resource\AuditLogsRetention;

// Test path: MockHandler-primary. Emulate 0.6.0 cannot serve most audit-log
// calls through SDK v9.1 — createEvent nests the event under `event` while
// emulate reads a top-level `action`; action/schema responses lack the
// `schema` key AuditLogAction::fromArray requires; the listActionSchemas and
// PUT-retention routes do not exist — so one wire-fidelity smoke covers the
// parseable calls (empty listActions + GET retention) against live emulate
// and everything else is scripted.

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function auditJwksResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
}

function startAuditLogsEmulate(): EmulateServer
{
    $server = new EmulateServer(port: 4191);
    $server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    return $server;
}

describe('HasAuditLogsTrait', function (): void {
    it('audits the model lifecycle with default action names', function (string $mutation, string $expectedAction): void {
        Queue::fake();
        UserFactory::new()->create(['workos_id' => 'user_fixture']);

        Route::post('/audit-mutate', function () use ($mutation) {
            if ($mutation === 'create') {
                Post::create(['title' => 'Audit me']);
            } elseif ($mutation === 'update') {
                Post::query()->createQuietly(['title' => 'Draft'])->update(['title' => 'Final']);
            } elseif ($mutation === 'archive') {
                Post::query()->createQuietly(['title' => 'Draft'])->delete();
            } elseif ($mutation === 'force-delete') {
                Post::query()->createQuietly(['title' => 'Draft'])->forceDelete();
            } else {
                $post = Post::query()->createQuietly(['title' => 'Draft']);
                $post->deleteQuietly();
                $post->restore();
            }

            return response()->noContent();
        });

        $this->fakeWorkosResponses([auditJwksResponse()]);

        $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
            ->post('/audit-mutate')
            ->assertNoContent();

        Queue::assertPushed(CreateAuditLogEventJob::class, function (CreateAuditLogEventJob $job) use ($expectedAction): bool {
            return $job->action === $expectedAction
                && $job->actor->actorId === 'user_fixture'
                && $job->actor->actorType === 'user'
                && $job->actor->organizationId === 'org_ctx'
                && $job->targets[0]['type'] === 'post'
                && $job->targets[0]['id'] !== '';
        });
    })->with([
        'create' => ['create', 'post.create'],
        'update' => ['update', 'post.update'],
        'soft delete audits as archive' => ['archive', 'post.archive'],
        'force delete audits as delete' => ['force-delete', 'post.delete'],
        'restore' => ['restore', 'post.restore'],
    ]);

    it('throws MissingOrganizationContextException when a mutation happens outside org context', function (): void {
        Queue::fake();
        UserFactory::new()->create(['workos_id' => 'user_fixture']);

        Route::post('/audit-mutate-no-org', fn (): Post => Post::create(['title' => 'Orphan']));

        $this->fakeWorkosResponses([auditJwksResponse()]);
        $this->withoutExceptionHandling();

        // Session carries no org_id claim — the trait must refuse loudly
        // rather than send organization_id: null or silently guess.
        expect(fn () => $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
            ->post('/audit-mutate-no-org'))
            ->toThrow(MissingOrganizationContextException::class);

        Queue::assertNothingPushed();
    });
});

describe('AuditLogContext', function (): void {
    it('resolves the authenticated actor and the org_id claim eagerly in-request', function (): void {
        UserFactory::new()->create([
            'workos_id' => 'user_fixture',
            'name' => 'Alice Anderson',
            'email' => 'alice@acme.com',
        ]);

        Route::get('/audit-context', function (): array {
            $context = app(AuditActorResolver::class)->resolve();

            return [
                'actorId' => $context->actorId,
                'actorType' => $context->actorType,
                'actorName' => $context->actorName,
                'organizationId' => $context->organizationId,
                'location' => $context->location,
            ];
        });

        $this->fakeWorkosResponses([auditJwksResponse()]);

        $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
            ->get('/audit-context')
            ->assertOk()
            ->assertJson([
                'actorId' => 'user_fixture',
                'actorType' => 'user',
                'actorName' => 'Alice Anderson',
                'organizationId' => 'org_ctx',
                'location' => '127.0.0.1',
            ]);
    });

    it('throws when authenticated without an org_id claim and no explicit organization', function (): void {
        UserFactory::new()->create(['workos_id' => 'user_fixture']);

        Route::get('/audit-context-no-org', fn () => app(AuditActorResolver::class)->resolve());

        $this->fakeWorkosResponses([auditJwksResponse()]);
        $this->withoutExceptionHandling();

        expect(fn () => $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
            ->get('/audit-context-no-org'))
            ->toThrow(MissingOrganizationContextException::class);
    });

    it('falls back to the system actor when unauthenticated but explicitly scoped to an org', function (): void {
        $context = app(AuditActorResolver::class)->resolve(organizationId: 'org_explicit');

        expect($context->actorId)->toBe('system')
            ->and($context->actorType)->toBe('system')
            ->and($context->actorName)->toBeNull()
            ->and($context->organizationId)->toBe('org_explicit');
    });

    it('honors an explicit actor override verbatim', function (): void {
        $context = app(AuditActorResolver::class)->resolve(
            organizationId: 'org_explicit',
            actorOverride: ['id' => 'svc_nightly', 'type' => 'service', 'name' => 'Nightly Job'],
        );

        expect($context->actorId)->toBe('svc_nightly')
            ->and($context->actorType)->toBe('service')
            ->and($context->actorName)->toBe('Nightly Job');
    });

    it('prefers an explicit organizationId over the session claim', function (): void {
        UserFactory::new()->create(['workos_id' => 'user_fixture']);

        Route::get('/audit-context-override', fn (): array => [
            'organizationId' => app(AuditActorResolver::class)->resolve(organizationId: 'org_other')->organizationId,
        ]);

        $this->fakeWorkosResponses([auditJwksResponse()]);

        $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
            ->get('/audit-context-override')
            ->assertOk()
            ->assertJson(['organizationId' => 'org_other']);
    });
});

describe('AuditLogFacade', function (): void {
    it('mints a fresh idempotency key per log() call and reuses an explicit one', function (): void {
        Queue::fake();

        AuditLog::log('invoice.paid', [['id' => 'inv_1', 'type' => 'invoice']], organizationId: 'org_explicit');
        AuditLog::log('invoice.paid', [['id' => 'inv_2', 'type' => 'invoice']], organizationId: 'org_explicit');
        AuditLog::log('invoice.paid', [['id' => 'inv_3', 'type' => 'invoice']], organizationId: 'org_explicit', idempotencyKey: 'explicit-key');

        $keys = Queue::pushed(CreateAuditLogEventJob::class)
            ->map(fn (CreateAuditLogEventJob $job): string => $job->idempotencyKey);

        expect($keys)->toHaveCount(3)
            ->and($keys->unique())->toHaveCount(3)
            ->and($keys->last())->toBe('explicit-key')
            ->and($keys->first())->toMatch('/^[0-9a-f-]{36}$/');
    });

    it('creates the event on the wire with the Idempotency-Key header and full payload', function (): void {
        $this->fakeWorkosResponses([
            new Response(201, ['Content-Type' => 'application/json'], '{"success": true}'),
        ]);

        AuditLog::log(
            'invoice.paid',
            targets: [['id' => 'inv_1', 'type' => 'invoice', 'name' => 'Invoice #1']],
            metadata: ['amount' => '100'],
            organizationId: 'org_explicit',
            actor: ['id' => 'billing-bot', 'type' => 'system', 'name' => 'Billing Bot'],
            idempotencyKey: 'idem-123',
        );

        expect($this->workosRequestHistory)->toHaveCount(1);

        $request = $this->workosRequestHistory[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        expect($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/audit_logs/events')
            ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-123')
            ->and($body['organization_id'])->toBe('org_explicit')
            ->and($body['event']['action'])->toBe('invoice.paid')
            ->and($body['event']['actor'])->toMatchArray(['id' => 'billing-bot', 'type' => 'system', 'name' => 'Billing Bot'])
            ->and($body['event']['targets'][0])->toMatchArray(['id' => 'inv_1', 'type' => 'invoice', 'name' => 'Invoice #1'])
            ->and($body['event']['metadata'])->toBe(['amount' => '100']);
    });

    it('rethrows a 4xx rejection as AuditLogSchemaMismatchException and logs the body', function (): void {
        Log::spy();

        $this->fakeWorkosResponses([
            new Response(400, ['Content-Type' => 'application/json'], '{"message": "Audit Log could not be processed", "code": "invalid_audit_log"}'),
        ]);

        expect(fn () => AuditLog::log(
            'invoice.mismatched',
            [['id' => 'inv_1', 'type' => 'wrong_type']],
            organizationId: 'org_explicit',
        ))->toThrow(AuditLogSchemaMismatchException::class);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'authkit: audit log event rejected (schema mismatch)'
                && $context['action'] === 'invoice.mismatched'
                && $context['organization_id'] === 'org_explicit'
                && $context['status'] === 400)
            ->once();
    });

    it('truncates oversized metadata locally before the request leaves the process', function (): void {
        Log::spy();

        $this->fakeWorkosResponses([
            new Response(201, ['Content-Type' => 'application/json'], '{"success": true}'),
        ]);

        $metadata = ['long_note' => str_repeat('x', 501)];

        foreach (range(1, 50) as $i) {
            $metadata["key_{$i}"] = "value {$i}";
        }

        AuditLog::log('invoice.paid', [['id' => 'inv_1', 'type' => 'invoice']], metadata: $metadata, organizationId: 'org_explicit');

        $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

        expect($body['event']['metadata'])->toHaveCount(50)
            ->and(mb_strlen($body['event']['metadata']['long_note']))->toBe(500);

        Log::shouldHaveReceived('warning')->once();
    });
});

describe('AuditLogSchema', function (): void {
    it('registers a schema via exact passthrough and returns the SDK DTO', function (): void {
        $this->fakeWorkosResponses([
            new Response(201, ['Content-Type' => 'application/json'], (string) json_encode([
                'object' => 'audit_log_schema',
                'version' => 1,
                'targets' => [['type' => 'invoice']],
                'created_at' => '2026-01-01T00:00:00.000Z',
            ])),
        ]);

        $schema = AuditLog::createSchema(
            'invoice.paid',
            targets: [new AuditLogSchemaTargetInput(type: 'invoice')],
            actor: new AuditLogSchemaActorInput(metadata: ['type' => 'object']),
            metadata: ['type' => 'object'],
        );

        $request = $this->workosRequestHistory[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        expect($schema)->toBeInstanceOf(AuditLogSchema::class)
            ->and($schema->version)->toBe(1)
            ->and($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/audit_logs/actions/invoice.paid/schemas')
            ->and($body['targets'][0]['type'])->toBe('invoice')
            ->and($body['actor']['metadata'])->toBe(['type' => 'object']);
    });

    it('lists actions as SDK resources', function (): void {
        $this->fakeWorkosResponses([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'data' => [[
                    'object' => 'audit_log_action',
                    'name' => 'invoice.paid',
                    'schema' => [
                        'object' => 'audit_log_schema',
                        'version' => 1,
                        'targets' => [['type' => 'invoice']],
                        'created_at' => '2026-01-01T00:00:00.000Z',
                    ],
                    'created_at' => '2026-01-01T00:00:00.000Z',
                    'updated_at' => '2026-01-01T00:00:00.000Z',
                ]],
                'list_metadata' => ['before' => null, 'after' => null],
            ])),
        ]);

        $actions = AuditLog::listActions(limit: 5);
        $request = $this->workosRequestHistory[0]['request'];

        expect($actions->data)->toHaveCount(1)
            ->and($actions->data[0])->toBeInstanceOf(AuditLogAction::class)
            ->and($actions->data[0]->name)->toBe('invoice.paid')
            ->and($request->getUri()->getPath())->toBe('/audit_logs/actions')
            ->and($request->getUri()->getQuery())->toContain('limit=5')
            ->and($request->getUri()->getQuery())->toContain('order=desc');
    });

    it('lists an action\'s schemas as SDK resources', function (): void {
        $this->fakeWorkosResponses([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'data' => [[
                    'object' => 'audit_log_schema',
                    'version' => 2,
                    'targets' => [['type' => 'invoice']],
                    'created_at' => '2026-01-01T00:00:00.000Z',
                ]],
                'list_metadata' => ['before' => null, 'after' => null],
            ])),
        ]);

        $schemas = AuditLog::listActionSchemas('invoice.paid');

        expect($schemas->data)->toHaveCount(1)
            ->and($schemas->data[0])->toBeInstanceOf(AuditLogSchema::class)
            ->and($schemas->data[0]->version)->toBe(2)
            ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
            ->toBe('/audit_logs/actions/invoice.paid/schemas');
    });

    it('round-trips the emulate wire for the calls emulate can parse (smoke)', function (): void {
        $this->server = startAuditLogsEmulate();

        $actions = AuditLog::listActions();
        $retention = AuditLog::getRetention('org_smoke');

        expect($actions->data)->toBe([])
            ->and($retention)->toBeInstanceOf(AuditLogsRetention::class);
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
});

describe('AuditLogRetention', function (): void {
    it('reads and writes retention through exact passthroughs', function (): void {
        $this->fakeWorkosResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"retention_period_in_days": 30}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"retention_period_in_days": 365}'),
        ]);

        expect(AuditLog::getRetention('org_x')->retentionPeriodInDays)->toBe(30)
            ->and(AuditLog::setRetention('org_x', 365)->retentionPeriodInDays)->toBe(365);

        [$get, $put] = [$this->workosRequestHistory[0]['request'], $this->workosRequestHistory[1]['request']];

        expect($get->getMethod())->toBe('GET')
            ->and($get->getUri()->getPath())->toBe('/organizations/org_x/audit_logs_retention')
            ->and($put->getMethod())->toBe('PUT')
            ->and(json_decode((string) $put->getBody(), true))->toBe(['retention_period_in_days' => 365]);
    });

    it('rejects retention periods other than 30 or 365 before any network call', function (): void {
        $this->fakeWorkosResponses([]);

        expect(fn () => AuditLog::setRetention('org_x', 90))
            ->toThrow(InvalidRetentionPeriodException::class, '[90]');

        expect($this->workosRequestHistory)->toHaveCount(0);
    });
});
