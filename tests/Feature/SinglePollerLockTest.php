<?php

declare(strict_types=1);

use Authkit\Authkit\Models\WorkosEventCursor;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

uses(UsesWorkosMockHandler::class);

// The array cache driver supports atomic locks with no external service —
// exactly what the Windows CI lane needs.

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

it('rejects a second poller with FAILURE and zero WorkOS API calls', function (): void {
    // Simulate "worker A already running": hold the lock before invoking the
    // command. The queued response is a tripwire — the history spy must show
    // the rejected worker never touched the API at all.
    $competing = Cache::lock('authkit-events-worker', 90);
    expect($competing->get())->toBeTrue();

    $this->fakeWorkosResponses([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'object' => 'list', 'data' => [], 'list_metadata' => ['before' => null, 'after' => null],
        ])),
    ]);

    try {
        $this->artisan('authkit:work', ['--once' => true])
            ->expectsOutputToContain('Another authkit:work process already holds the lock.')
            ->assertExitCode(1);
    } finally {
        $competing->release();
    }

    expect($this->workosRequestHistory)->toHaveCount(0)
        ->and(WorkosEventCursor::current()->fresh()?->last_event_id)->toBeNull();
});

it('releases the lock on exit so a subsequent worker can run', function (): void {
    $this->fakeWorkosResponses([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'object' => 'list', 'data' => [], 'list_metadata' => ['before' => null, 'after' => null],
        ])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'object' => 'list', 'data' => [], 'list_metadata' => ['before' => null, 'after' => null],
        ])),
    ]);

    $this->artisan('authkit:work', ['--once' => true])->assertExitCode(0);
    $this->artisan('authkit:work', ['--once' => true])->assertExitCode(0);

    expect($this->workosRequestHistory)->toHaveCount(2);
});
