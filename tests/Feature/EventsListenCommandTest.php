<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated;

beforeEach(function () {
    Event::fake();
    config([
        'workos.events.routing.categories.user' => 'events_api',
        'workos.events.poll_interval' => 0,
    ]);
});

it('fails when API key is not configured', function () {
    config(['workos.api_key' => null]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('API key not configured')
        ->assertFailed();
});

it('exits with success when no event types configured for events_api', function () {
    config(['workos.events.routing.categories.user' => 'webhooks']);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('No event types configured')
        ->assertSuccessful();
});

it('polls events API and dispatches events with --once', function () {
    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [
                [
                    'id' => 'event_01',
                    'event' => 'user.created',
                    'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
                ],
                [
                    'id' => 'event_02',
                    'event' => 'user.updated',
                    'data' => ['id' => 'user_123', 'email' => 'updated@example.com'],
                ],
            ],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(WebhookReceived::class, 2);
    Event::assertDispatched(WorkOSUserCreated::class);
    Event::assertDispatched(WorkOSUserUpdated::class);

    expect(Cache::get('workos.events.cursor'))->toBe('event_02');
});

it('persists cursor after each event', function () {
    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [
                [
                    'id' => 'event_first',
                    'event' => 'user.created',
                    'data' => ['id' => 'user_1'],
                ],
                [
                    'id' => 'event_second',
                    'event' => 'user.created',
                    'data' => ['id' => 'user_2'],
                ],
            ],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    expect(Cache::get('workos.events.cursor'))->toBe('event_second');
});

it('resumes from cached cursor', function () {
    Cache::put('workos.events.cursor', 'event_previous');

    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['after'] ?? null) === 'event_previous'
            && ! isset($data['range_start']);
    });
});

it('sends range_start with --since on first run', function () {
    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', [
        '--once' => true,
        '--since' => '2024-06-15',
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->data()['range_start'] === '2024-06-15';
    });
});

it('uses lookback_days when no cursor and no --since', function () {
    config(['workos.events.lookback_days' => 3]);

    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('bootstrapping from 3 days ago')
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        return isset($request->data()['range_start']);
    });
});

it('handles API error with --once', function () {
    Http::fake([
        'api.workos.com/events*' => Http::response(null, 500),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->expectsOutputToContain('API request failed')
        ->assertSuccessful();
});

it('uses stored cursor as after parameter on subsequent runs', function () {
    Cache::put('workos.events.cursor', 'event_from_previous_run');

    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [
                [
                    'id' => 'event_new',
                    'event' => 'user.created',
                    'data' => ['id' => 'user_1'],
                ],
            ],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['after'] ?? null) === 'event_from_previous_run'
            && ! isset($data['range_start']);
    });

    expect(Cache::get('workos.events.cursor'))->toBe('event_new');
});

it('only requests events_api-routed event types', function () {
    config([
        'workos.events.routing.categories.user' => 'events_api',
        'workos.events.routing.categories.organization' => 'webhooks',
    ]);

    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $events = $request->data()['events'] ?? [];

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

        return $hasUser && ! $hasOrg;
    });
});

it('dispatches WebhookReceived for unknown event types', function () {
    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [
                [
                    'id' => 'event_unknown',
                    'event' => 'some.unknown.event',
                    'data' => ['id' => 'test_123'],
                ],
            ],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->event === 'some.unknown.event';
    });

    Event::assertNotDispatched(WorkOSUserCreated::class);
});

it('sends correct request parameters', function () {
    config(['workos.events.limit' => 50]);

    Http::fake([
        'api.workos.com/events*' => Http::response([
            'data' => [],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    $this->artisan('workos:events-listen', ['--once' => true])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return str_starts_with($url, 'https://api.workos.com/events')
            && ($query['limit'] ?? null) === '50'
            && ($query['order'] ?? null) === 'asc';
    });
});
