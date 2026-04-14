<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;
use WorkOS\AuthKit\Events\WorkOSEventReceived;

beforeEach(function () {
    Event::fake();
    config([
        'workos.events.routing.categories.user' => 'events_api',
        'workos.events.poll_interval' => 0,
    ]);
});

it('exits with success when no event types configured for events_api', function () {
    config([
        'workos.events.routing.categories.user' => 'webhooks',
        'workos.events.routing.categories.dsync' => 'webhooks',
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('No event types configured')
        ->assertSuccessful();
});

it('polls events API and dispatches events with --once', function () {
    $this->queueSdkResponse([
        'data' => [
            [
                'object' => 'event',
                'id' => 'event_01',
                'event' => 'user.created',
                'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
                'created_at' => '2024-01-15T12:00:00.000Z',
            ],
            [
                'object' => 'event',
                'id' => 'event_02',
                'event' => 'user.updated',
                'data' => ['id' => 'user_123', 'email' => 'updated@example.com'],
                'created_at' => '2024-01-15T12:00:01.000Z',
            ],
        ],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(WorkOSEventReceived::class, 2);
    Event::assertDispatched(WorkOSUserCreated::class);
    Event::assertDispatched(WorkOSUserUpdated::class);

    expect(Cache::get('workos.events.cursor'))->toBe('event_02');
});

it('persists cursor after each event', function () {
    $this->queueSdkResponse([
        'data' => [
            [
                'object' => 'event',
                'id' => 'event_first',
                'event' => 'user.created',
                'data' => ['id' => 'user_1'],
                'created_at' => '2024-01-15T12:00:00.000Z',
            ],
            [
                'object' => 'event',
                'id' => 'event_second',
                'event' => 'user.created',
                'data' => ['id' => 'user_2'],
                'created_at' => '2024-01-15T12:00:01.000Z',
            ],
        ],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    expect(Cache::get('workos.events.cursor'))->toBe('event_second');
});

it('resumes from cached cursor', function () {
    Cache::put('workos.events.cursor', 'event_previous');

    $this->queueSdkResponse([
        'data' => [],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    $lastRequest = end($this->guzzleHistory);
    $query = [];
    parse_str((string) $lastRequest['request']->getUri()->getQuery(), $query);

    expect($query['after'] ?? null)->toBe('event_previous');
    expect($query)->not->toHaveKey('range_start');
});

it('sends range_start with --since on first run', function () {
    $this->queueSdkResponse([
        'data' => [],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', [
        '--once' => true,
        '--since' => '2024-06-15',
    ])->assertSuccessful();

    $lastRequest = end($this->guzzleHistory);
    $query = [];
    parse_str((string) $lastRequest['request']->getUri()->getQuery(), $query);

    expect($query['range_start'] ?? null)->toBe('2024-06-15T00:00:00.000Z');
});

it('uses lookback_days when no cursor and no --since', function () {
    config(['workos.events.lookback_days' => 3]);

    $this->queueSdkResponse([
        'data' => [],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('bootstrapping from 3 days ago')
        ->assertSuccessful();

    $lastRequest = end($this->guzzleHistory);
    $query = [];
    parse_str((string) $lastRequest['request']->getUri()->getQuery(), $query);

    expect($query)->toHaveKey('range_start');
    expect($query['range_start'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
});

it('handles API error with --once', function () {
    $this->guzzleMock->append(new Response(500, [], json_encode(['message' => 'Server Error'])));

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('API request failed')
        ->assertSuccessful();
});

it('uses stored cursor as after parameter on subsequent runs', function () {
    Cache::put('workos.events.cursor', 'event_from_previous_run');

    $this->queueSdkResponse([
        'data' => [
            [
                'object' => 'event',
                'id' => 'event_new',
                'event' => 'user.created',
                'data' => ['id' => 'user_1'],
                'created_at' => '2024-01-15T12:00:00.000Z',
            ],
        ],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    $lastRequest = end($this->guzzleHistory);
    $query = [];
    parse_str((string) $lastRequest['request']->getUri()->getQuery(), $query);

    expect($query['after'] ?? null)->toBe('event_from_previous_run');
    expect($query)->not->toHaveKey('range_start');
    expect(Cache::get('workos.events.cursor'))->toBe('event_new');
});

it('only requests events_api-routed event types', function () {
    config([
        'workos.events.routing.categories.user' => 'events_api',
        'workos.events.routing.categories.organization' => 'webhooks',
    ]);

    $this->queueSdkResponse([
        'data' => [],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    $lastRequest = end($this->guzzleHistory);
    $query = [];
    parse_str((string) $lastRequest['request']->getUri()->getQuery(), $query);

    // events[] should contain user event types but not organization types
    $events = $query['events'] ?? [];
    if (is_string($events)) {
        $events = [$events];
    }

    $hasUser = false;
    $hasOrg = false;
    foreach ($events as $event) {
        if (str_starts_with($event, 'user.')) {
            $hasUser = true;
        }
        if (str_starts_with($event, 'organization.')) {
            $hasOrg = true;
        }
    }

    expect($hasUser)->toBeTrue();
    expect($hasOrg)->toBeFalse();
});

it('dispatches WorkOSEventReceived for unknown event types', function () {
    $this->queueSdkResponse([
        'data' => [
            [
                'object' => 'event',
                'id' => 'event_unknown',
                'event' => 'some.unknown.event',
                'data' => ['id' => 'test_123'],
                'created_at' => '2024-01-15T12:00:00.000Z',
            ],
        ],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(WorkOSEventReceived::class, function ($event) {
        return $event->event === 'some.unknown.event';
    });

    Event::assertNotDispatched(WorkOSUserCreated::class);
});

it('sends correct request parameters', function () {
    config(['workos.events.limit' => 50]);

    $this->queueSdkResponse([
        'data' => [],
        'list_metadata' => ['after' => null],
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    $lastRequest = end($this->guzzleHistory);
    $url = (string) $lastRequest['request']->getUri();
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toContain('/events');
    expect($query['limit'] ?? null)->toBe('50');
    expect($query['order'] ?? null)->toBe('asc');
});
