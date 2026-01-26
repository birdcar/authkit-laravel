<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LaravelWorkosMigrator
{
    public function migrate(Command $command): void
    {
        $this->migrateConfig($command);
        $this->suggestPackageRemoval($command);
    }

    private function migrateConfig(Command $command): void
    {
        $servicesPath = config_path('services.php');

        if (! File::exists($servicesPath)) {
            return;
        }

        // Check if services.php has workos key
        $contents = File::get($servicesPath);

        if (! str_contains($contents, "'workos'") && ! str_contains($contents, '"workos"')) {
            $command->info('No WorkOS config found in services.php');

            return;
        }

        $command->info('WorkOS config detected in services.php');
        $command->line('  Your existing WORKOS_* environment variables will work with authkit-laravel.');
        $command->line('  The new config/workos.php reads from the same env vars.');
    }

    private function suggestPackageRemoval(Command $command): void
    {
        $command->newLine();
        $command->warn('To complete migration, remove laravel/workos:');
        $command->line('  <fg=cyan>composer remove laravel/workos</>');
        $command->newLine();
        $command->line('  You may also want to remove the WorkOS config from config/services.php');
    }
}
