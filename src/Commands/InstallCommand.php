<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'workos:install {--force : Overwrite existing configuration files}';

    protected $description = 'Install WorkOS AuthKit';

    public function handle(): int
    {
        $this->info('Installing WorkOS AuthKit...');
        $this->newLine();

        $this->publishConfig();
        $this->publishMigrations();
        $this->updateAuthConfig();
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'workos-config',
            '--force' => $this->option('force'),
        ]);

        $this->components->info('Published config/workos.php');
    }

    protected function publishMigrations(): void
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'workos-migrations',
        ]);

        $this->components->info('Published migrations');
    }

    protected function updateAuthConfig(): void
    {
        $authConfigPath = config_path('auth.php');

        if (! File::exists($authConfigPath)) {
            $this->components->warn('Could not find config/auth.php - please add the WorkOS guard manually');

            return;
        }

        $contents = File::get($authConfigPath);

        if (str_contains($contents, "'workos'")) {
            $this->components->info('WorkOS guard already configured in auth.php');

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
        $this->components->info('Updated config/auth.php with WorkOS guard');
    }

    protected function displayNextSteps(): void
    {
        $this->newLine();
        $this->components->info('WorkOS AuthKit installed successfully!');
        $this->newLine();

        $this->line('<fg=yellow>Next steps:</>');
        $this->newLine();

        $this->line('  <fg=gray>1.</> Add to your <fg=cyan>.env</> file:');
        $this->line('     <fg=green>WORKOS_API_KEY=</><fg=gray>sk_...</>');
        $this->line('     <fg=green>WORKOS_CLIENT_ID=</><fg=gray>client_...</>');
        $this->line('     <fg=green>WORKOS_REDIRECT_URI=</><fg=gray>'.config('app.url', 'http://localhost').'/auth/callback</>');
        $this->newLine();

        $this->line('  <fg=gray>2.</> Set WorkOS as default auth guard in <fg=cyan>.env</>:');
        $this->line('     <fg=green>AUTH_GUARD=</><fg=cyan>workos</>');
        $this->newLine();

        $this->line('  <fg=gray>3.</> Run migrations:');
        $this->line('     <fg=cyan>php artisan migrate</>');
        $this->newLine();

        $this->line('  <fg=gray>4.</> Make the password column nullable (WorkOS handles authentication):');
        $this->line('     <fg=cyan>php artisan make:migration make_password_nullable_on_users_table</>');
        $this->line('     <fg=gray>Then add:</> <fg=cyan>$table->string(\'password\')->nullable()->change();</>');
        $this->newLine();

        $this->line('  <fg=gray>5.</> Add the WorkOS traits to your User model:');
        $this->line('     <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;</>');
        $this->line('     <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;</>');
        $this->newLine();

        $this->line('  See documentation: <fg=blue>https://workos.com/docs/authkit</>');
    }
}
