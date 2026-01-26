<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\AuthKit\Facades\WorkOS;

class SyncUsersCommand extends Command
{
    protected $signature = 'workos:sync-users
        {--organization= : Sync only users from this organization}
        {--limit=100 : Number of users per page}';

    protected $description = 'Sync users from WorkOS';

    public function handle(): int
    {
        $this->info('Syncing users from WorkOS...');

        /** @var class-string $userModel */
        $userModel = config('workos.user_model');
        /** @var string|null $organizationId */
        $organizationId = $this->option('organization');
        $limit = (int) $this->option('limit');

        if (! class_exists($userModel)) {
            $this->error("User model {$userModel} does not exist.");

            return self::FAILURE;
        }

        if (! method_exists($userModel, 'findOrCreateByWorkOS')) {
            $this->error("User model {$userModel} must have a findOrCreateByWorkOS method. Add the HasWorkOSId trait.");

            return self::FAILURE;
        }

        $cursor = null;
        $synced = 0;

        do {
            // listUsers returns [$before, $after, $users]
            /** @var array{0: ?string, 1: ?string, 2: array<\WorkOS\Resource\User>} $response */
            $response = WorkOS::userManagement()->listUsers(
                organizationId: $organizationId,
                limit: $limit,
                after: $cursor,
            );

            [$before, $after, $users] = $response;

            foreach ($users as $workosUser) {
                $userModel::findOrCreateByWorkOS($workosUser->raw);
                $synced++;
            }

            $cursor = $after;

            $this->info("Synced {$synced} users...");

        } while ($cursor !== null);

        $this->info("✓ Synced {$synced} users successfully.");

        return self::SUCCESS;
    }
}
