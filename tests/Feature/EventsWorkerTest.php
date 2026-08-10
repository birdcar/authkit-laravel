<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use Authkit\Authkit\Events\EventBatchProcessor;
use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Models\WorkosEventCursor;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use WorkOS\Exception\ServerException;

uses(UsesWorkosMockHandler::class);

// Test path note: emulate 0.6.0 serves GET /events in an SDK-parseable shape
// (one smoke test below keeps that wire fidelity), but it returns 200 with the
// full list for an unresolvable `after` cursor — never 400/404 — and does not
// enforce the range_start timestamp format, so the stale-cursor fallback and
// format assertions are MockHandler-backed per the contract's sanctioned
// downgrade ("MockHandler fakes only where emulate lacks coverage").

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function workosEvent(string $type, array $data, string $id, string $createdAt = '2026-08-06T12:00:00.000Z'): array
{
    return [
        'object' => 'event',
        'id' => $id,
        'event' => $type,
        'data' => $data,
        'created_at' => $createdAt,
    ];
}

function eventsPageResponse(array $events): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => $events,
        'list_metadata' => ['before' => null, 'after' => null],
    ]));
}

function runProcessorOnce(int $batchLimit = 100): int
{
    return app(EventBatchProcessor::class)->runOnce(WorkosEventCursor::current(), $batchLimit);
}

it('returns zero and leaves the cursor untouched on an empty batch', function (): void {
    Event::fake();
    $this->fakeWorkosResponses([eventsPageResponse([])]);

    expect(runProcessorOnce())->toBe(0);

    $cursor = WorkosEventCursor::current()->fresh();
    expect($cursor?->last_event_id)->toBeNull()
        ->and($cursor?->last_event_occurred_at)->toBeNull();
});

it('dispatches a one-event batch and advances the cursor to that event', function (): void {
    Event::fake();
    $this->fakeWorkosResponses([eventsPageResponse([
        workosEvent('user.created', ['id' => 'user_01AAA', 'email' => 'a@acme.com'], 'event_01AAA'),
    ])]);

    expect(runProcessorOnce())->toBe(1);

    Event::assertDispatched(UserCreated::class, fn (UserCreated $event): bool => $event->id === 'event_01AAA'
        && $event->resourceId() === 'user_01AAA');

    expect(WorkosEventCursor::current()->fresh()?->last_event_id)->toBe('event_01AAA');
});

it('commits the cursor to the LAST event of a multi-event batch and respects batch_limit', function (): void {
    Event::fake();
    $this->fakeWorkosResponses([eventsPageResponse([
        workosEvent('user.created', ['id' => 'user_01AAA'], 'event_01AAA'),
        workosEvent('organization.created', ['id' => 'org_01BBB', 'name' => 'Acme'], 'event_01BBB'),
        workosEvent('organization_domain.created', ['id' => 'org_domain_01CCC', 'organization_id' => 'org_01BBB', 'domain' => 'acme.com'], 'event_01CCC', '2026-08-06T12:00:05.000Z'),
    ])]);

    expect(runProcessorOnce(50))->toBe(3);

    Event::assertDispatched(UserCreated::class);
    Event::assertDispatched(OrganizationCreated::class);
    Event::assertDispatched(OrganizationDomainCreated::class);

    $cursor = WorkosEventCursor::current()->fresh();
    expect($cursor?->last_event_id)->toBe('event_01CCC')
        ->and($cursor?->last_event_occurred_at?->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-08-06T12:00:05.000Z');

    parse_str($this->workosRequestHistory[0]['request']->getUri()->getQuery(), $query);
    expect($query['limit'])->toBe('50')
        ->and($query['order'])->toBe('asc');
});

it('dispatches GenericWorkosEvent for out-of-scope types mixed into a batch', function (): void {
    Event::fake();
    $this->fakeWorkosResponses([eventsPageResponse([
        workosEvent('dsync.user.created', ['id' => 'directory_user_01AAA'], 'event_01AAA'),
    ])]);

    expect(runProcessorOnce())->toBe(1);

    Event::assertDispatched(GenericWorkosEvent::class, fn (GenericWorkosEvent $event): bool => $event->type === 'dsync.user.created');
});

it('polls forward from the stored cursor via after with asc ordering', function (): void {
    Event::fake();
    WorkosEventCursor::current()->commit('event_01PREV', new DateTimeImmutable('2026-08-06T11:00:00.000Z'));

    $this->fakeWorkosResponses([eventsPageResponse([])]);

    runProcessorOnce();

    parse_str($this->workosRequestHistory[0]['request']->getUri()->getQuery(), $query);
    expect($query['after'])->toBe('event_01PREV')
        ->and($query['order'])->toBe('asc')
        ->and($query)->not->toHaveKey('range_start');
});

it('falls back to rangeStart in the exact 3-digit-millisecond UTC format when the stored cursor is stale', function (): void {
    Event::fake();
    config()->set('authkit.max_retries', 0);
    WorkosEventCursor::current()->commit('event_01STALE', new DateTimeImmutable('2026-08-06T11:00:00.000Z'));

    $this->fakeWorkosResponses([
        new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'cursor outside retention window'])),
        eventsPageResponse([]),
    ]);

    runProcessorOnce();

    expect($this->workosRequestHistory)->toHaveCount(2);

    parse_str($this->workosRequestHistory[1]['request']->getUri()->getQuery(), $query);
    expect($query)->not->toHaveKey('after')
        ->and($query['range_start'])->toBe('2026-08-06T11:00:00.000Z')
        ->and($query['range_start'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');

    // The format is load-bearing, not cosmetic: WorkOS rejects date-only and
    // microsecond variants, which this regex would also reject.
    expect('2026-08-06')->not->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and('2026-08-06T11:00:00.00Z')->not->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and('2026-08-06T11:00:00.000000Z')->not->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
});

it('uses the backfill_minutes lookback for the first-boot rangeStart, in the same exact format', function (): void {
    Event::fake();
    config()->set('authkit.events.backfill_minutes', 7);

    $this->fakeWorkosResponses([eventsPageResponse([])]);

    $before = now();
    runProcessorOnce();

    parse_str($this->workosRequestHistory[0]['request']->getUri()->getQuery(), $query);
    expect($query)->not->toHaveKey('after')
        ->and($query['range_start'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');

    $rangeStart = new DateTimeImmutable($query['range_start']);
    expect(abs($rangeStart->getTimestamp() - $before->subMinutes(7)->getTimestamp()))->toBeLessThanOrEqual(5);
});

it('does not misdiagnose a server outage as a stale cursor', function (): void {
    Event::fake();
    config()->set('authkit.max_retries', 0);
    WorkosEventCursor::current()->commit('event_01PREV', new DateTimeImmutable('2026-08-06T11:00:00.000Z'));

    $this->fakeWorkosResponses([
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'internal error'])),
    ]);

    expect(fn (): int => runProcessorOnce())->toThrow(ServerException::class);

    // One request only — no rangeStart fallback fired — and the cursor is untouched.
    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and(WorkosEventCursor::current()->fresh()?->last_event_id)->toBe('event_01PREV');
});

it('exits FAILURE from authkit:work --once on a WorkOS API error without touching the cursor', function (): void {
    Event::fake();
    config()->set('authkit.max_retries', 0);

    $this->fakeWorkosResponses([
        new Response(503, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'unavailable'])),
    ]);

    $this->artisan('authkit:work', ['--once' => true])
        ->expectsOutputToContain('WorkOS Events API error')
        ->assertExitCode(1);

    expect(WorkosEventCursor::current()->fresh()?->last_event_id)->toBeNull();
});

it('processes a batch and exits cleanly via authkit:work --once', function (): void {
    Event::fake();
    $this->fakeWorkosResponses([eventsPageResponse([
        workosEvent('user.created', ['id' => 'user_01AAA'], 'event_01AAA'),
    ])]);

    $this->artisan('authkit:work', ['--once' => true])
        ->expectsOutputToContain('Dispatched 1 event(s).')
        ->assertExitCode(0);

    Event::assertDispatched(UserCreated::class);
    expect(WorkosEventCursor::current()->fresh()?->last_event_id)->toBe('event_01AAA');
});

it('keeps the daemon loop alive across iterations by refreshing (not re-acquiring) its own lock', function (): void {
    // Regression guard for $lock->refresh($ttl): every driver's acquire-style
    // get() succeeds only when the key does NOT exist — regardless of owner —
    // so a regression back to get() would print the lock-loss error and exit
    // FAILURE on the very first loop iteration. Real signals cannot stop the
    // daemon here (trap() is a framework-enforced no-op under unit tests —
    // ArtisanServiceProvider's Signals availability resolver requires
    // ! runningUnitTests()), so the loop is stopped by a sentinel listener
    // exception on the THIRD batch: reaching it proves two full iterations
    // (two successful in-loop refreshes) completed without lock loss.
    Event::listen(UserCreated::class, function (): void {
        throw new RuntimeException('daemon-stop sentinel');
    });

    config()->set('authkit.events.poll_interval', 0);

    $this->fakeWorkosResponses([
        eventsPageResponse([]),
        eventsPageResponse([]),
        eventsPageResponse([
            workosEvent('user.created', ['id' => 'user_01AAA', 'email' => 'stop@acme.com', 'email_verified' => true, 'first_name' => 'Stop', 'last_name' => 'Sentinel'], 'event_01AAA'),
        ]),
    ]);

    try {
        $this->artisan('authkit:work');
        $this->fail('Expected the sentinel listener exception to propagate out of the daemon.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('daemon-stop sentinel');
    }

    // Three fetches happened — the loop survived two empty batches without
    // losing its own lock — and the interrupted third batch did NOT commit.
    expect($this->workosRequestHistory)->toHaveCount(3)
        ->and(WorkosEventCursor::current()->fresh()?->last_event_id)->toBeNull();
});

it('walks seeded events off a real emulate wire and advances the cursor (smoke)', function (): void {
    Event::fake();

    // 4195: each emulate-touching suite owns a unique port so parallel workers
    // never read a sibling suite's instance (4196–4199 are taken).
    $server = new EmulateServer(port: 4195);
    $server->start();

    try {
        config()->set('authkit.emulate.enabled', true);
        config()->set('authkit.emulate.base_url', $server->baseUrl());
        app()->forgetInstance(WorkosClientManagerContract::class);

        $processed = runProcessorOnce();

        // The seed fixture creates one user, one organization, and one domain —
        // three events, each mapped to its typed class over the real wire.
        expect($processed)->toBe(3);
        Event::assertDispatched(UserCreated::class);
        Event::assertDispatched(OrganizationCreated::class);
        Event::assertDispatched(OrganizationDomainCreated::class);

        $cursor = WorkosEventCursor::current()->fresh();
        expect($cursor?->last_event_id)->toStartWith('evt_');

        // A second poll from the committed cursor finds nothing new.
        expect(runProcessorOnce())->toBe(0);
    } finally {
        $server->stop();
    }
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
