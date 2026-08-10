<?php

declare(strict_types=1);

namespace Authkit\Authkit\Jobs;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\OrganizationSyncFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use WorkOS\Exception\ConflictException;
use WorkOS\Exception\NotFoundException;
use WorkOS\Exception\UnprocessableEntityException;
use WorkOS\Resource\Organization;
use WorkOS\Service\Organizations;

/**
 * Idempotent by construction: looks the remote org up by external_id before
 * creating, and no-ops entirely when the local row already carries a
 * workos_id (e.g. the login-time projection listener beat this job to it).
 */
final class CreateWorkosOrganization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** The org row was deleted before this job ran — silently drop it, not an error. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Model $organization)
    {
        $this->tries = (int) config('authkit.organization.retry.tries', 5);

        // Force queueing after the enclosing DB transaction commits. Assigned,
        // not redeclared: Queueable already defines $afterCommit, and a typed
        // redeclaration is a fatal trait-composition conflict.
        $this->afterCommit = true;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('authkit.organization.retry.backoff', [10, 30, 60, 300, 900]);

        return array_values(array_map(
            static fn (mixed $seconds): int => is_numeric($seconds) ? (int) $seconds : 0,
            is_array($backoff) ? $backoff : [],
        ));
    }

    public function handle(WorkosClientManager $clients): void
    {
        if ($this->organization->getAttribute('workos_id') !== null) {
            return; // already synced (e.g. the login-time listener beat us to it)
        }

        $key = $this->organization->getKey();
        $externalId = is_scalar($key) ? (string) $key : '';
        $organizations = $clients->client()->organizations();

        try {
            $remote = $organizations->getOrganizationByExternalId($externalId);
        } catch (NotFoundException) {
            $remote = $this->createRemote($organizations, $externalId);
        }

        // saveQuietly: a system-internal linkage write, not an app-observable
        // state change — no spurious updated/saved events for app listeners.
        $this->organization->forceFill(['workos_id' => $remote->id])->saveQuietly();
    }

    private function createRemote(Organizations $organizations, string $externalId): Organization
    {
        try {
            return $organizations->createOrganization(
                name: $this->organizationName($externalId),
                externalId: $externalId,
            );
        } catch (ConflictException|UnprocessableEntityException) {
            // Lost a create-vs-create race: another process's create beat ours
            // past WorkOS's external_id-uniqueness check. Re-run the lookup —
            // the winner's record now exists.
            return $organizations->getOrganizationByExternalId($externalId);
        }
    }

    /**
     * The observer only attaches to models using HasWorkosOrganization, but the
     * job's own contract is any Model — so the trait hook is duck-typed, with
     * the trait's own default (name attribute, then key) as the fallback.
     */
    private function organizationName(string $externalId): string
    {
        if (method_exists($this->organization, 'workosOrganizationName')) {
            $name = $this->organization->workosOrganizationName();

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $name = $this->organization->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : $externalId;
    }

    public function failed(?Throwable $exception): void
    {
        event(new OrganizationSyncFailed($this->organization, $exception));
    }
}
