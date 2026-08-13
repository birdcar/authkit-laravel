<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Testing\Fakes\OrganizationSyncFake;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\Organization;
use Workbench\Database\Factories\UserFactory;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

it('captures the create job dispatched by the organization observer', function (): void {
    $fake = new OrganizationSyncFake;

    $organization = Organization::query()->create(['name' => 'Acme']);

    $fake->assertSyncRequested();
    $fake->assertSyncRequested($organization);

    expect($organization->getAttribute('workos_id'))->toBeNull();
});

it('captures the delete job with the workos id the row carried', function (): void {
    $fake = new OrganizationSyncFake;

    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_synced']);

    $organization->delete();

    $fake->assertDeleteRequested('org_synced');
});

it('applies the local sync effect through completeSync', function (): void {
    $fake = new OrganizationSyncFake;
    $user = UserFactory::new()->create(['workos_id' => 'user_acting']);

    $organization = Organization::query()->create(['name' => 'Acme']);

    $workosId = $fake->completeSync($organization);

    expect($organization->refresh()->getAttribute('workos_id'))->toBe($workosId);

    // The synced row is now a fully usable acting organization.
    Authkit::actingAs($user, ['organization' => $organization]);

    expect(Authkit::currentOrganization()?->is($organization))->toBeTrue();
});

it('asserts nothing was requested with laravel bus failure messages', function (): void {
    $fake = new OrganizationSyncFake;

    $fake->assertNothingSyncRequested();

    Organization::query()->create(['name' => 'Acme']);

    expect(fn () => $fake->assertNothingSyncRequested())->toThrow(AssertionFailedError::class);
});

it('leaves unrelated jobs dispatching normally', function (): void {
    new OrganizationSyncFake;

    $ran = false;

    $job = new class($ran)
    {
        public function __construct(public bool &$flag) {}

        public function handle(): void
        {
            $this->flag = true;
        }
    };

    dispatch_sync($job);

    expect($ran)->toBeTrue();
});
