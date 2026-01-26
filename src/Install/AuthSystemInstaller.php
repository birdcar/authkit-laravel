<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuthSystemInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        $this->publishConfig($command);
        $this->publishMigrations($command);
        $this->updateAuthConfig($command);
        $this->displayModelGuidance($command);
    }

    private function publishConfig(Command $command): void
    {
        $command->callSilently('vendor:publish', [
            '--tag' => 'workos-config',
            '--force' => $command->option('force'),
        ]);
        $command->info('Published config/workos.php');
    }

    private function publishMigrations(Command $command): void
    {
        $command->callSilently('vendor:publish', [
            '--tag' => 'workos-migrations',
        ]);
        $command->info('Published migrations');
    }

    private function updateAuthConfig(Command $command): void
    {
        $authConfigPath = config_path('auth.php');

        if (! File::exists($authConfigPath)) {
            $command->warn('Could not find config/auth.php - please add the WorkOS guard manually');

            return;
        }

        $contents = File::get($authConfigPath);

        if (str_contains($contents, "'workos'")) {
            $command->info('WorkOS guard already configured in auth.php');

            return;
        }

        $guardToAdd = <<<'PHP'
        'workos' => [
            'driver' => 'workos',
            'provider' => 'users',
        ],

PHP;

        $providerToAdd = <<<'PHP'
        'workos' => [
            'driver' => 'eloquent',
            'model' => env('WORKOS_USER_MODEL', App\Models\User::class),
        ],

PHP;

        if (str_contains($contents, "'guards' => [")) {
            $result = preg_replace(
                "/('guards'\s*=>\s*\[)/",
                "$1\n{$guardToAdd}",
                $contents
            );
            if ($result !== null) {
                $contents = $result;
            }
        }

        if (str_contains($contents, "'providers' => [")) {
            $result = preg_replace(
                "/('providers'\s*=>\s*\[)/",
                "$1\n{$providerToAdd}",
                $contents
            );
            if ($result !== null) {
                $contents = $result;
            }
        }

        File::put($authConfigPath, $contents);
        $command->info('Updated config/auth.php with WorkOS guard');
    }

    private function displayModelGuidance(Command $command): void
    {
        $command->newLine();
        $command->line('<fg=yellow>Add these traits to your User model:</>');
        $command->line('  <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;</>');
        $command->line('  <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;</>');
    }

    public function describe(): string
    {
        return 'Guards, providers, config, and User model traits';
    }
}
