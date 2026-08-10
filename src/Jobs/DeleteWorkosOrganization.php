<?php

declare(strict_types=1);

namespace Authkit\Authkit\Jobs;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\OrganizationSyncFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use WorkOS\Exception\NotFoundException;

/**
 * Deliberately carries a plain string, not the Eloquent model: this job exists
 * to run *after* the local row is already gone, and SerializesModels re-fetches
 * model arguments by primary key at dequeue time — a model argument would make
 * the delete job permanently unable to run.
 */
final class DeleteWorkosOrganization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(public readonly string $workosOrganizationId)
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
        try {
            $clients->client()->organizations()->deleteOrganization($this->workosOrganizationId);
        } catch (NotFoundException) {
            // Already gone remotely (manual dashboard delete, or a retried job
            // whose earlier attempt actually succeeded) — deleting twice is a no-op.
        }
    }

    public function failed(?Throwable $exception): void
    {
        event(new OrganizationSyncFailed(null, $exception, $this->workosOrganizationId));
    }
}
