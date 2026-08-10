<?php

declare(strict_types=1);

use Authkit\Authkit\Events\EventBatchProcessor;
use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Models\WorkosEventCursor;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\User;

uses(UsesWorkosMockHandler::class);

// The named crash/resume suite (success criterion: --filter=EventsWorkerResume).
//
// "Kill worker mid-batch" without OS signals: the crash IS an uncaught listener
// exception, which leaves behind exactly what a SIGKILL mid-dispatch would —
// partial listener side effects and NO cursor commit. MockHandler-backed so the
// same 10-event batch is replayed byte-identically on all CI platforms
// (including Windows, where emulate is unavailable).

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

it('replays the whole batch after a mid-batch crash with zero missed and zero duplicate side effects', function (): void {
    // 10 sequential events: 8 typed (user.created) + 2 generic (dsync.*).
    $events = [];

    foreach (range(1, 8) as $i) {
        $events[] = [
            'object' => 'event',
            'id' => sprintf('event_%02d', $i),
            'event' => 'user.created',
            'data' => [
                'id' => sprintf('user_%02d', $i),
                'email' => sprintf('u%d@acme.com', $i),
                'email_verified' => true,
                'first_name' => 'User',
                'last_name' => (string) $i,
            ],
            'created_at' => sprintf('2026-08-06T12:00:%02d.000Z', $i),
        ];
    }

    foreach ([9, 10] as $i) {
        $events[] = [
            'object' => 'event',
            'id' => sprintf('event_%02d', $i),
            'event' => 'dsync.user.created',
            'data' => ['id' => sprintf('directory_user_%02d', $i)],
            'created_at' => sprintf('2026-08-06T12:00:%02d.000Z', $i),
        ];
    }

    $makePage = fn (): Response => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => $events,
        'list_metadata' => ['before' => null, 'after' => null],
    ]));

    // The SAME page is served for both fetches (as two distinct Response
    // instances — a PSR-7 body stream exhausts after one read): the cursor
    // never moved after the crash, so the poller re-requests the identical
    // batch — exactly what the real API would return for the unchanged
    // `after` cursor.
    $this->fakeWorkosResponses([$makePage(), $makePage()]);

    // Cursor state preceding this batch, so "unchanged" is a concrete value.
    WorkosEventCursor::current()->commit('event_00PREV', new DateTimeImmutable('2026-08-06T11:59:59.000Z'));

    // Temporary test listener that throws on its 6th typed-event invocation
    // within the run — the simulated crash.
    $typedInvocations = 0;
    $crashArmed = true;
    Event::listen(UserCreated::class, function () use (&$typedInvocations, &$crashArmed): void {
        $typedInvocations++;

        if ($crashArmed && $typedInvocations === 6) {
            throw new RuntimeException('simulated mid-batch crash');
        }
    });

    $processor = app(EventBatchProcessor::class);
    $cursor = WorkosEventCursor::current();

    // --- The crash run ---
    try {
        $processor->runOnce($cursor, 100);
        $this->fail('Expected the simulated mid-batch crash to propagate.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('simulated mid-batch crash');
    }

    // Cursor UNCHANGED — still pointing at whatever preceded this batch.
    $fresh = WorkosEventCursor::current()->fresh();
    expect($fresh?->last_event_id)->toBe('event_00PREV');

    // The first 5 events' idempotent side effects DID apply: those listeners
    // ran and committed before the 6th one threw. (Event 6's projection row
    // may also exist — the package upsert runs before the crashing test
    // listener — which is precisely the partial-batch state a crash leaves.)
    foreach (range(1, 5) as $i) {
        expect(User::query()->where('workos_id', sprintf('user_%02d', $i))->count())->toBe(1);
    }

    // Nothing past the crash point ran.
    foreach (range(7, 8) as $i) {
        expect(User::query()->where('workos_id', sprintf('user_%02d', $i))->exists())->toBeFalse();
    }

    // --- The restart ---
    $crashArmed = false;

    $processed = $processor->runOnce(WorkosEventCursor::current(), 100);

    // The SAME 10-event batch was re-fetched (cursor hadn't moved)...
    expect($processed)->toBe(10)
        ->and($this->workosRequestHistory)->toHaveCount(2);

    // ...zero missed: every typed event's side effect is now applied...
    foreach (range(1, 8) as $i) {
        expect(User::query()->where('workos_id', sprintf('user_%02d', $i))->count())->toBe(1);
    }

    // ...zero duplicates: the 5 events that already ran once produced no
    // second row (idempotent upsert), and the projection holds exactly the 8
    // typed events' rows.
    expect(User::query()->count())->toBe(8);

    // And only now does the cursor commit — to the batch's last event.
    $committed = WorkosEventCursor::current()->fresh();
    expect($committed?->last_event_id)->toBe('event_10')
        ->and($committed?->last_event_occurred_at?->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-08-06T12:00:10.000Z');
});
