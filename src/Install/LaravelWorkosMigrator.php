<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class LaravelWorkosMigrator
{
    private ?string $servicesContents = null;

    public function migrate(Command $command): void
    {
        $this->loadServicesConfig();
        $this->displayMigrationInfo($command);
        $this->handlePackageRemoval($command);
        $this->handleServicesConfigCleanup($command);
    }

    private function loadServicesConfig(): void
    {
        $servicesPath = config_path('services.php');

        if (File::exists($servicesPath)) {
            $this->servicesContents = File::get($servicesPath);
        }
    }

    private function hasWorkosInServices(): bool
    {
        if ($this->servicesContents === null) {
            return false;
        }

        return str_contains($this->servicesContents, "'workos'")
            || str_contains($this->servicesContents, '"workos"');
    }

    private function displayMigrationInfo(Command $command): void
    {
        if (! $this->hasWorkosInServices()) {
            $command->info('No WorkOS config found in services.php');

            return;
        }

        $command->info('WorkOS config detected in services.php');
        $command->line('  Your existing WORKOS_* environment variables will work with authkit-laravel.');
        $command->line('  The new config/workos.php reads from the same env vars.');
    }

    private function handlePackageRemoval(Command $command): void
    {
        $command->newLine();

        if (! $command->confirm('Remove laravel/workos package?', true)) {
            $command->line('  <fg=yellow>Remember to run:</> <fg=cyan>composer remove laravel/workos</>');

            return;
        }

        $command->line('Removing laravel/workos...');

        $result = Process::run('composer remove laravel/workos');

        if ($result->successful()) {
            $command->info('Removed laravel/workos package');
        } else {
            $command->error('Failed to remove laravel/workos. Please run manually:');
            $command->line('  <fg=cyan>composer remove laravel/workos</>');
        }
    }

    private function handleServicesConfigCleanup(Command $command): void
    {
        if (! $this->hasWorkosInServices() || $this->servicesContents === null) {
            return;
        }

        $command->newLine();

        if (! $command->confirm('Remove WorkOS config from config/services.php?', true)) {
            $command->line('  <fg=yellow>You can manually remove the workos key from config/services.php</>');

            return;
        }

        $updated = $this->removeWorkosFromServices($this->servicesContents);

        if ($updated !== $this->servicesContents) {
            File::put(config_path('services.php'), $updated);
            $command->info('Removed WorkOS config from services.php');
        } else {
            $command->warn('Could not automatically remove WorkOS config. Please remove manually.');
        }
    }

    private function removeWorkosFromServices(string $contents): string
    {
        // Match the 'workos' => [...], array entry including trailing comma and whitespace
        $patterns = [
            // Single-quoted key with array value
            "/\s*'workos'\s*=>\s*\[[^\]]*\],?\n?/s",
            // Double-quoted key with array value
            '/\s*"workos"\s*=>\s*\[[^\]]*\],?\n?/s',
        ];

        foreach ($patterns as $pattern) {
            $updated = preg_replace($pattern, "\n", $contents);
            if ($updated !== null && $updated !== $contents) {
                return $updated;
            }
        }

        return $contents;
    }
}
