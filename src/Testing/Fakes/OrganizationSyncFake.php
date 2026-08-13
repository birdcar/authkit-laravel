<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Jobs\CreateWorkosOrganization;
use Authkit\Authkit\Jobs\DeleteWorkosOrganization;
use Authkit\Authkit\Observers\WorkosOrganizationObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Testing\Fakes\BusFake;
use LogicException;

/**
 * Captures the organization-sync pipeline without HTTP. Unlike the manager
 * fakes there is no manager class here — sync IS the two queued jobs that
 * {@see WorkosOrganizationObserver} dispatches when
 * a HasWorkosOrganization model is created or deleted — so this fake scopes
 * Laravel's own Bus fake to exactly those job classes: every other job in the
 * application still dispatches (and runs) normally.
 *
 * A captured job never runs, so the local row keeps a null workos_id. Tests
 * that need the synced end-state call {@see completeSync()}, which applies
 * exactly the local effect the real job would have (forceFill + saveQuietly).
 */
final class OrganizationSyncFake
{
    private int $sequence = 0;

    public function __construct()
    {
        Bus::fake([CreateWorkosOrganization::class, DeleteWorkosOrganization::class]);
    }

    /**
     * Assert remote creation was requested — optionally for one specific
     * local organization row.
     */
    public function assertSyncRequested(?Model $organization = null): void
    {
        if ($organization === null) {
            $this->bus()->assertDispatched(CreateWorkosOrganization::class);

            return;
        }

        $this->bus()->assertDispatched(
            CreateWorkosOrganization::class,
            static fn (CreateWorkosOrganization $job): bool => $job->organization->is($organization),
        );
    }

    /**
     * Assert remote deletion was requested — optionally for one specific
     * WorkOS organization id.
     */
    public function assertDeleteRequested(?string $workosOrganizationId = null): void
    {
        if ($workosOrganizationId === null) {
            $this->bus()->assertDispatched(DeleteWorkosOrganization::class);

            return;
        }

        $this->bus()->assertDispatched(
            DeleteWorkosOrganization::class,
            static fn (DeleteWorkosOrganization $job): bool => $job->workosOrganizationId === $workosOrganizationId,
        );
    }

    public function assertNothingSyncRequested(): void
    {
        $this->bus()->assertNotDispatched(CreateWorkosOrganization::class);
        $this->bus()->assertNotDispatched(DeleteWorkosOrganization::class);
    }

    /**
     * Resolved lazily at assertion time, never captured at construction: a
     * consumer calling Bus::fake() AFTER Authkit::fake() installs an outer
     * BusFake that records subsequent dispatches itself — a handle captured
     * here would go blind to them. The trade: jobs are recorded by whichever
     * fake was current at dispatch time, so dispatches made BEFORE a later
     * Bus::fake() are unreachable from it — dispatch and assert on the same
     * side of any Bus::fake() call (docs/testing.md states this constraint).
     */
    private function bus(): BusFake
    {
        $dispatcher = Bus::getFacadeRoot();

        if (! $dispatcher instanceof BusFake) {
            throw new LogicException(
                'The Bus dispatcher is no longer a fake — something restored the real dispatcher after '
                .'Authkit::fake() installed the organization-sync capture, so there is nothing to assert against.',
            );
        }

        return $dispatcher;
    }

    /**
     * Apply the local effect the captured CreateWorkosOrganization job would
     * have had: the row gets its workos_id, written quietly. Returns the id
     * so the test can act against it.
     */
    public function completeSync(Model $organization, ?string $workosOrganizationId = null): string
    {
        $workosOrganizationId ??= 'org_fake_'.++$this->sequence;

        $organization->forceFill(['workos_id' => $workosOrganizationId])->saveQuietly();

        return $workosOrganizationId;
    }
}
