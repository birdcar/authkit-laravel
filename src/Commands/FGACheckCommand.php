<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\AuthKit\FGA\FGAResource;
use WorkOS\AuthKit\FGA\FGAService;

class FGACheckCommand extends Command
{
    /** @var string */
    protected $signature = 'workos:fga-check
        {userId : WorkOS user ID}
        {permission : Permission slug to check}
        {resource : Resource in resourceType:resourceId format}';

    /** @var string */
    protected $description = 'Check if a user has a permission on a WorkOS FGA resource';

    public function handle(FGAService $fga): int
    {
        /** @var string $resourceStr */
        $resourceStr = $this->argument('resource');
        $resource = FGAResource::fromString($resourceStr);

        /** @var string $userId */
        $userId = $this->argument('userId');
        /** @var string $permission */
        $permission = $this->argument('permission');

        $allowed = $fga->check(
            userId: $userId,
            permission: $permission,
            resourceType: $resource->resourceType,
            resourceId: $resource->resourceId,
        );

        if ($allowed) {
            $this->components->info('Access GRANTED.');
        } else {
            $this->components->warn('Access DENIED.');
        }

        return $allowed ? Command::SUCCESS : Command::FAILURE;
    }
}
