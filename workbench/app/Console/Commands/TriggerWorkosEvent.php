<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Models\Organization;

/**
 * Deliberately thin: makes ONE package-API write that produces a real WorkOS
 * event, then prints how to watch the sidecar deliver it. The full
 * poll-and-assert cycle belongs to the events pipeline's own Pest suites —
 * this command exists for a human to observe the round trip live.
 */
final class TriggerWorkosEvent extends Command
{
    protected $signature = 'demo:trigger-event';

    protected $description = 'Makes a package-API write that produces a real WorkOS event, for observing the sidecar + LogWorkosEvents round trip.';

    public function handle(): int
    {
        // Creating a local organization fires HasWorkosOrganization's
        // create-observer, which registers it with WorkOS (createOrganization)
        // — producing an organization.created event with zero direct SDK use.
        $organization = Organization::query()->create([
            'name' => 'Demo Org '.now()->format('His'),
        ]);

        $this->info(sprintf(
            'Created organization #%s (workos_id: %s) through the package trait.',
            (string) $organization->getKey(),
            (string) ($organization->getAttribute('workos_id') ?? 'pending'),
        ));
        $this->info('Run `php artisan authkit:work --once` (or watch a running worker) to see LogWorkosEvents handle the resulting event.');

        return self::SUCCESS;
    }
}
