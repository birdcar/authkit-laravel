<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Pennant\Feature;
use Workbench\App\Models\User;

/**
 * Feature Flags demo, console context: there is no HTTP session and no JWT
 * claim to read here, so the workos Pennant driver takes its WorkOS-API
 * fallback path — the same call a queued job would make. Pairs with the
 * /demo/flags route, which proves the claim-first HTTP path.
 */
final class CheckFeatureFlagsForUser extends Command
{
    protected $signature = 'demo:feature-flags {user : Local user id or email}';

    protected $description = 'Checks demo-flag for a user with no HTTP session — proves the WorkOS-API fallback path.';

    public function handle(): int
    {
        $identifier = $this->argument('user');

        if ($identifier === '') {
            $this->error('Pass a local user id or email.');

            return self::FAILURE;
        }

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('id', $identifier)
            ->first();

        if ($user === null) {
            $this->error(sprintf('No local user matches [%s]. Seed one first: php artisan db:seed', $identifier));

            return self::FAILURE;
        }

        $active = Feature::store('workos')->for($user)->active('demo-flag');

        $this->line(sprintf(
            'demo-flag is %s for %s — resolved via the WorkOS API fallback (no session, no claims).',
            $active ? 'ACTIVE' : 'inactive',
            (string) $user->getAttribute('email'),
        ));

        return self::SUCCESS;
    }
}
