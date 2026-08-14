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

/**
 * Pushes a local rename to the remote organization. Idempotent by
 * construction: it sends the row's CURRENT name (re-read at dequeue time via
 * SerializesModels), so stacked renames converge on the latest value, and a
 * row that has never synced (no workos_id) is skipped — the create job that
 * is still pending will carry the current name when it runs.
 */
final class UpdateWorkosOrganization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** The org row was deleted before this job ran — silently drop it, not an error. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Model $organization)
    {
        $this->tries = (int) config('authkit.organization.retry.tries', 5);

        // Assigned, not redeclared — see CreateWorkosOrganization::__construct().
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
        $workosId = $this->organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            return; // never synced — the pending create job carries the current name
        }

        $clients->client()->organizations()->updateOrganization(
            id: $workosId,
            name: $this->organizationName(),
        );
    }

    /**
     * Same duck-typed hook resolution as CreateWorkosOrganization.
     */
    private function organizationName(): string
    {
        if (method_exists($this->organization, 'workosOrganizationName')) {
            $name = $this->organization->workosOrganizationName();

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $name = $this->organization->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $key = $this->organization->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    public function failed(?Throwable $exception): void
    {
        event(new OrganizationSyncFailed($this->organization, $exception));
    }
}
