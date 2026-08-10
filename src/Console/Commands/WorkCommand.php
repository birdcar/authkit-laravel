<?php

declare(strict_types=1);

namespace Authkit\Authkit\Console\Commands;

use Authkit\Authkit\Events\EventBatchProcessor;
use Authkit\Authkit\Models\WorkosEventCursor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use WorkOS\Exception\WorkOSException;

class WorkCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'authkit:work
        {--once : Process a single batch and exit}';

    /**
     * The command description.
     */
    protected $description = 'Poll the WorkOS Events API and dispatch mapped Laravel events (at-least-once — keep listeners idempotent).';

    private bool $shouldStop = false;

    /**
     * The processor is method-injected (not constructor-injected) on purpose:
     * Artisan instantiates registered commands once per process, so a
     * constructor-held processor would pin whichever client manager existed at
     * console boot — resolving here picks up the current container state.
     */
    public function handle(EventBatchProcessor $processor): int
    {
        // Closure form keeps SIGTERM/SIGINT unevaluated on platforms without
        // pcntl (Windows), where trap() is a documented no-op and the
        // at-least-once idempotency contract — not graceful drain — is the
        // correctness guarantee.
        $this->trap(fn (): array => [SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });

        $ttl = (int) config('authkit.events.lock_ttl', 90);
        $lock = Cache::lock('authkit-events-worker', $ttl);

        if (! $lock->get()) {
            // Loser exits before ANY WorkOS API call — doubled polling would
            // double the duplicate-delivery rate and burn API quota.
            $this->error('Another authkit:work process already holds the lock.');

            return self::FAILURE;
        }

        try {
            do {
                if (! $this->renewLock($lock, $ttl)) {
                    $this->error('Lost the authkit-events-worker lock mid-run — exiting to avoid a split-brain poller.');

                    return self::FAILURE;
                }

                try {
                    $processed = $processor->runOnce(
                        WorkosEventCursor::current(),
                        (int) config('authkit.events.batch_limit', 100),
                    );
                } catch (WorkOSException $e) {
                    // A real outage (5xx, timeout, rate limit): the cursor is
                    // untouched, so retrying the same position loses nothing.
                    $this->error("WorkOS Events API error: {$e->getMessage()}");

                    if ((bool) $this->option('once')) {
                        return self::FAILURE;
                    }

                    sleep((int) config('authkit.events.poll_interval', 5));

                    continue;
                }

                $this->line($processed > 0 ? "Dispatched {$processed} event(s)." : 'No new events.');

                if ((bool) $this->option('once')) {
                    break;
                }

                if ($processed === 0 && ! $this->shouldStop) {
                    sleep((int) config('authkit.events.poll_interval', 5));
                }

                // The signal flag is only ever checked BETWEEN batches: a
                // signal arriving mid-dispatch is indistinguishable from a hard
                // crash mid-batch and is covered by the same at-least-once
                // idempotency contract, not by special-casing signals.
            } while (! $this->shouldStop);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * Extend ownership of the worker lock for another TTL window.
     *
     * Renewal, never bare get(): acquire-style calls succeed only when the
     * key does not exist — regardless of owner — so re-calling get() on a
     * lock this process already holds would always fail and the daemon would
     * exit after its first batch. refresh() extends ownership and still
     * detects real TTL loss (another process's lock, or expired-and-
     * reclaimed) so the caller fails loudly instead of racing a second
     * poller. refresh() only exists on Laravel ≥13.x locks — 12.x renews by
     * release-and-reacquire under the same key, whose microsecond gap is
     * only exposed to a competing worker's single startup probe; a lost
     * race there still fails loudly in the caller.
     */
    private function renewLock(Lock $lock, int $ttl): bool
    {
        if (method_exists($lock, 'refresh')) {
            return (bool) $lock->refresh($ttl);
        }

        $lock->release();

        return (bool) $lock->get();
    }
}
