<?php

declare(strict_types=1);

namespace Authkit\Authkit\Console\Commands;

use Authkit\Authkit\Support\EnvFileUpdater;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'authkit:install';

    /**
     * The command description.
     */
    protected $description = 'Publish AuthKit config and migrations, and prepare .env keys.';

    /**
     * Execute the console command.
     */
    public function handle(EnvFileUpdater $updater): int
    {
        $this->components->info('Publishing authkit-laravel resources...');
        $this->call('vendor:publish', ['--tag' => 'authkit-config']);
        $this->publishMigrationsIfNeeded();

        $cookiePassword = base64_encode(random_bytes(32));
        $envPath = base_path('.env');

        $appended = $updater->ensureKeys($envPath, $this->envKeys($cookiePassword));
        $updater->ensureKeys(base_path('.env.example'), $this->envKeys('')); // never write a real secret into .env.example

        // Distinguish "we could not write to .env" from "every key was already
        // there" — the second is the normal idempotent re-run, not a problem.
        // The check is against the file's actual contents rather than
        // is_writable(), so a write that failed for any reason is caught.
        if (! is_file($envPath)) {
            $this->warnSetManually('No .env file was found.');
        } elseif ($appended === [] && ! $this->envKeysArePresent($envPath)) {
            $this->warnSetManually('The .env file could not be updated.');
        }

        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function envKeysArePresent(string $path): bool
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        foreach (array_keys($this->envKeys('')) as $key) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function warnSetManually(string $reason): void
    {
        $this->warn("{$reason} Set these keys manually:");

        foreach (array_keys($this->envKeys('')) as $key) {
            $this->line("  {$key}");
        }
    }

    private function publishMigrationsIfNeeded(): void
    {
        $sourcePath = __DIR__.'/../../../database/migrations';

        if ((glob($sourcePath.'/*.php') ?: []) === []) {
            $this->components->twoColumnDetail('AuthKit migrations', '<fg=gray>NONE TO PUBLISH</>');

            return;
        }

        if ($this->migrationsAlreadyPublished($sourcePath)) {
            $this->components->twoColumnDetail('AuthKit migrations', '<fg=yellow;options=bold>ALREADY PUBLISHED</>');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'authkit-laravel-migrations']);
    }

    /**
     * `database.migrations.update_date_on_publish` defaults to true (Laravel 11+), so
     * vendor:publish always re-timestamps and re-copies migrations on every run unless
     * we check first. Match by stripping each source migration's leading Y_m_d_His_
     * timestamp and globbing the destination for a file already ending in that name.
     */
    private function migrationsAlreadyPublished(string $sourcePath): bool
    {
        foreach (glob($sourcePath.'/*.php') ?: [] as $sourceFile) {
            $descriptor = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($sourceFile));

            if (glob(database_path("migrations/*_{$descriptor}")) === []) {
                return false; // this source migration has never been published
            }
        }

        return true; // every source migration already has a published counterpart (or there are none)
    }

    /** @return array<string, string> */
    private function envKeys(string $cookiePassword): array
    {
        return [
            'WORKOS_API_KEY' => '',
            'WORKOS_CLIENT_ID' => '',
            'WORKOS_REDIRECT_URI' => '${APP_URL}/authkit/callback',
            'WORKOS_COOKIE_PASSWORD' => $cookiePassword,
            'WORKOS_BASE_URL' => 'https://api.workos.com',
            'AUTHKIT_EMULATE_ENABLED' => 'false',
            'AUTHKIT_EMULATE_BASE_URL' => 'http://localhost:4100',
        ];
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps:');
        $this->line('  1. Set WORKOS_API_KEY and WORKOS_CLIENT_ID in .env from your WorkOS dashboard.');
        $this->line('  2. Confirm WORKOS_REDIRECT_URI matches the redirect URI configured in AuthKit.');
        $this->line('  3. For local dev against workos/emulate, set AUTHKIT_EMULATE_ENABLED=true.');
        $this->line('  4. Run php artisan authkit:inspect-token against a real AuthKit login and follow');
        $this->line('     docs/token-audit.md before Phase 2 implementation begins.');
    }
}
