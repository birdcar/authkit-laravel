<?php

declare(strict_types=1);

use Authkit\Authkit\Events\OrganizationSyncFailed;
use Authkit\Authkit\Jobs\CreateWorkosOrganization;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\Organization;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    // Real dequeue mechanics: jobs land in the skeleton's jobs table and run
    // through queue:work, exercising tries/backoff/failed() for real.
    config()->set('queue.default', 'database');
    config()->set('queue.failed.database', 'testing');

    // The SDK internally retries 5xx with sleeps; zero keeps failure tests fast
    // and makes every queued mock response account for exactly one attempt.
    config()->set('authkit.max_retries', 0);
});

function workosServerErrorResponse(): Response
{
    return new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
        'message' => 'Internal server error.',
    ]));
}

it('fires OrganizationSyncFailed once and lands in failed_jobs when tries are exhausted', function (): void {
    config()->set('authkit.organization.retry.tries', 1);

    Event::fake([OrganizationSyncFailed::class]);

    $this->fakeWorkosResponses([workosServerErrorResponse()]);

    $organization = Organization::query()->create(['name' => 'Acme']);

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->run();

    Event::assertDispatchedTimes(OrganizationSyncFailed::class, 1);
    Event::assertDispatched(OrganizationSyncFailed::class, function (OrganizationSyncFailed $event) use ($organization): bool {
        return $event->organization?->is($organization) === true
            && $event->exception !== null;
    });

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($organization->refresh()->workos_id)->toBeNull();
});

it('retries with the configured backoff and succeeds on a later attempt', function (): void {
    config()->set('authkit.organization.retry.tries', 2);
    config()->set('authkit.organization.retry.backoff', [0]);

    Event::fake([OrganizationSyncFailed::class]);

    $this->fakeWorkosResponses([
        workosServerErrorResponse(),
        new Response(404, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'Not found.'])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'org_recovered',
            'name' => 'Acme',
            'domains' => [],
            'metadata' => [],
            'external_id' => '1',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ])),
    ]);

    $organization = Organization::query()->create(['name' => 'Acme']);

    $this->artisan('queue:work', ['--once' => true])->run(); // fails, released for retry
    $this->artisan('queue:work', ['--once' => true])->run(); // succeeds

    Event::assertNotDispatched(OrganizationSyncFailed::class);

    expect($organization->refresh()->workos_id)->toBe('org_recovered')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('silently drops the job when the local row was deleted before it ran', function (): void {
    Event::fake([OrganizationSyncFailed::class]);

    $this->fakeWorkosResponses([]);

    $organization = Organization::query()->create(['name' => 'Ephemeral']);

    expect(DB::table('jobs')->count())->toBe(1);

    // deleteQuietly: no observer, so no DeleteWorkosOrganization job muddies
    // the assertion — only the pending create job is on the queue.
    $organization->deleteQuietly();

    $this->artisan('queue:work', ['--once' => true])->run();

    // A no-op, not a failure: zero HTTP calls, no failed event, queue drained.
    Event::assertNotDispatched(OrganizationSyncFailed::class);

    expect($this->workosRequestHistory)->toHaveCount(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('reads its retry budget and backoff schedule from config', function (): void {
    config()->set('authkit.organization.retry.tries', 7);
    config()->set('authkit.organization.retry.backoff', [5, 15]);

    $job = new CreateWorkosOrganization(new Organization);

    expect($job->tries)->toBe(7)
        ->and($job->backoff())->toBe([5, 15]);
});
