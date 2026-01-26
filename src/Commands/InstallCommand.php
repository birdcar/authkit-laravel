<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\AuthKit\Install\WizardFlow;
use WorkOS\AuthKit\Support\DetectionResult;
use WorkOS\AuthKit\Support\EnvironmentDetector;

class InstallCommand extends Command
{
    protected $signature = 'workos:install
        {--force : Overwrite existing configuration files}
        {--mini : Minimal install - config only with setup instructions}';

    protected $description = 'Install WorkOS AuthKit';

    public function __construct(
        private EnvironmentDetector $detector,
        private WizardFlow $wizard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->detector->detect();

        $this->displayDetectionResults($result);

        if ($this->option('mini')) {
            return $this->handleMiniInstall($result);
        }

        return $this->handleFullInstall($result);
    }

    private function displayDetectionResults(DetectionResult $result): void
    {
        $this->newLine();
        $this->components->info('Environment Detection');
        $this->newLine();

        if ($result->isFreshInstall()) {
            $this->line('  <fg=green>✓</> No existing auth setup detected - fresh install');
        } else {
            if ($result->hasExistingAuth()) {
                $this->line('  <fg=yellow>!</> Existing auth packages detected:');
                if ($result->hasBreeze) {
                    $this->line('      - Laravel Breeze');
                }
                if ($result->hasJetstream) {
                    $this->line('      - Laravel Jetstream');
                }
                if ($result->hasFortify) {
                    $this->line('      - Laravel Fortify');
                }
            }

            if ($result->hasAnyWorkosSetup()) {
                $this->line('  <fg=cyan>✓</> WorkOS setup detected:');
                if ($result->hasLaravelWorkos) {
                    $this->line('      - laravel/workos package');
                }
                if ($result->hasExistingWorkosConfig) {
                    $this->line('      - config/workos.php exists');
                }
                if ($result->hasServicesWorkosConfig) {
                    $this->line('      - WorkOS configured in services.php');
                }
            }
        }

        // Check env vars
        $envVars = $result->envVars;
        if (! empty($envVars)) {
            $this->newLine();
            $this->line('  <fg=cyan>✓</> Environment variables found:');
            foreach (array_keys($envVars) as $key) {
                $this->line("      - {$key}");
            }
        }

        $this->newLine();
    }

    private function handleMiniInstall(DetectionResult $result): int
    {
        $this->info('Running minimal install...');
        $this->newLine();

        $this->publishConfig();
        $this->displayMiniInstructions($result);

        return self::SUCCESS;
    }

    private function displayMiniInstructions(DetectionResult $result): void
    {
        $this->newLine();
        $this->components->info('Minimal install complete!');
        $this->newLine();

        $this->line('<fg=yellow>Next steps:</>');
        $this->newLine();

        $step = 1;

        // Check which env vars are missing
        $requiredVars = ['WORKOS_API_KEY', 'WORKOS_CLIENT_ID', 'WORKOS_REDIRECT_URI'];
        $missingVars = array_filter($requiredVars, fn ($var) => ! $result->hasEnvVar($var));

        if (! empty($missingVars)) {
            $this->line("  <fg=gray>{$step}.</> Add to your <fg=cyan>.env</> file:");
            foreach ($missingVars as $var) {
                $placeholder = match ($var) {
                    'WORKOS_API_KEY' => 'sk_...',
                    'WORKOS_CLIENT_ID' => 'client_...',
                    default => config('app.url', 'http://localhost').'/auth/callback',
                };
                $this->line("     <fg=green>{$var}=</><fg=gray>{$placeholder}</>");
            }
            $this->newLine();
            $step++;
        }

        $this->line("  <fg=gray>{$step}.</> Set WorkOS as default auth guard in <fg=cyan>.env</>:");
        $this->line('     <fg=green>AUTH_GUARD=</><fg=cyan>workos</>');
        $this->newLine();
        $step++;

        $this->line("  <fg=gray>{$step}.</> Add the WorkOS guard to <fg=cyan>config/auth.php</>:");
        $this->line('     <fg=cyan>\'guards\' => [</>');
        $this->line('         <fg=cyan>\'workos\' => [</>');
        $this->line('             <fg=cyan>\'driver\' => \'workos\',</>');
        $this->line('             <fg=cyan>\'provider\' => \'users\',</>');
        $this->line('         <fg=cyan>],</>');
        $this->line('     <fg=cyan>],</>');
        $this->newLine();
        $step++;

        $this->line("  <fg=gray>{$step}.</> Run migrations:");
        $this->line('     <fg=cyan>php artisan migrate</>');
        $this->newLine();
        $step++;

        $this->line("  <fg=gray>{$step}.</> Add the WorkOS traits to your User model:");
        $this->line('     <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;</>');
        $this->line('     <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;</>');
        $this->newLine();

        $this->line('  See documentation: <fg=blue>https://workos.com/docs/authkit</>');
    }

    private function handleFullInstall(DetectionResult $result): int
    {
        return $this->wizard->run($this, $result);
    }

    protected function publishConfig(): void
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'workos-config',
            '--force' => $this->option('force'),
        ]);

        $this->components->info('Published config/workos.php');
    }
}
