<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use WorkOS\AuthKit\Support\DetectionResult;

class WizardFlow
{
    /** @var array<string> */
    private array $selectedComponents = [];

    private ?string $laravelWorkosStrategy = null;

    private bool $migrationsConfirmed = false;

    public function __construct(
        private RouteInstaller $routeInstaller,
        private AuthSystemInstaller $authSystemInstaller,
        private WebhookInstaller $webhookInstaller,
        private LaravelWorkosMigrator $migrator,
        private EnvManager $envManager,
        private MigrationPlanGenerator $planGenerator,
    ) {}

    public function run(Command $command, DetectionResult $detection): int
    {
        // Step 1: Handle laravel/workos if detected
        if ($detection->hasLaravelWorkos) {
            $this->laravelWorkosStrategy = $this->askLaravelWorkosStrategy($command);
        }

        // Step 2: Generate migration plan if existing auth detected
        if ($detection->hasExistingAuth()) {
            $this->handleMigrationPlan($command, $detection);
        }

        // Step 3: Ask which components to install
        $this->selectedComponents = $this->askComponentSelection($command);

        if (empty($this->selectedComponents)) {
            $command->warn('No components selected. Installation cancelled.');

            return Command::SUCCESS;
        }

        // Step 4: Show env var plan and confirm
        if (! $this->confirmEnvChanges($command, $detection)) {
            $command->warn('Installation cancelled.');

            return Command::FAILURE;
        }

        // Step 5: Show migration plan and confirm (only if auth-system selected)
        if (in_array('auth-system', $this->selectedComponents)) {
            $this->migrationsConfirmed = $this->confirmMigrations($command);
        }

        // Step 6: Execute installation
        return $this->executeInstallation($command, $detection);
    }

    private function handleMigrationPlan(Command $command, DetectionResult $detection): void
    {
        $this->planGenerator->displaySummary($command, $detection);

        $planPath = $this->planGenerator->generate($detection, base_path());

        if ($planPath !== null) {
            $command->info("Migration plan saved to: {$planPath}");
            $command->newLine();
        }
    }

    private function askLaravelWorkosStrategy(Command $command): string
    {
        $command->newLine();
        $command->warn('laravel/workos package detected');
        $command->newLine();

        /** @var string $choice */
        $choice = $command->choice(
            'How should we proceed?',
            [
                'replace' => 'Replace entirely (migrate config, remove package)',
                'augment' => 'Augment/extend (add authkit features alongside)',
                'keep' => 'Keep both (install alongside, no migration)',
            ],
            'replace'
        );

        return $choice;
    }

    /**
     * @return array<string>
     */
    private function askComponentSelection(Command $command): array
    {
        $command->newLine();
        $command->info('Select which components to install:');
        $command->newLine();

        $components = [];

        if ($command->confirm('Install auth routes? (login, callback, logout)', true)) {
            $components[] = 'routes';
        }

        if ($command->confirm('Install full auth system? (guards, providers, User model guidance)', true)) {
            $components[] = 'auth-system';
        }

        if ($command->confirm('Install webhooks? (user sync, event handlers)', true)) {
            $components[] = 'webhooks';
        }

        return $components;
    }

    private function confirmEnvChanges(Command $command, DetectionResult $detection): bool
    {
        $changes = $this->envManager->planChanges($detection);

        if (empty($changes['add']) && empty($changes['modify'])) {
            $command->newLine();
            $command->info('No .env changes needed - all required variables are present.');

            return true;
        }

        $command->newLine();
        $command->info('The following .env changes will be made:');
        $command->newLine();

        if (! empty($changes['add'])) {
            $command->line('  <fg=green>Add:</> '.implode(', ', array_keys($changes['add'])));
        }

        if (! empty($changes['modify'])) {
            $command->line('  <fg=yellow>Modify:</> '.implode(', ', array_keys($changes['modify'])));
        }

        $command->newLine();

        return $command->confirm('Proceed with these changes?', true);
    }

    private function confirmMigrations(Command $command): bool
    {
        $command->newLine();

        return $command->confirm('Run migrations now?', true);
    }

    private function executeInstallation(Command $command, DetectionResult $detection): int
    {
        $command->newLine();
        $command->info('Installing WorkOS AuthKit...');
        $command->newLine();

        // Execute laravel/workos strategy if applicable
        if ($this->laravelWorkosStrategy === 'replace') {
            $this->migrator->migrate($command);
        }

        // Install selected components
        foreach ($this->selectedComponents as $component) {
            match ($component) {
                'routes' => $this->routeInstaller->install($command),
                'auth-system' => $this->authSystemInstaller->install($command),
                'webhooks' => $this->webhookInstaller->install($command),
                default => null,
            };
        }

        // Apply env changes
        $this->envManager->applyChanges($detection);

        // Run migrations if confirmed
        if ($this->migrationsConfirmed) {
            $command->newLine();
            $command->call('migrate');
        }

        $command->newLine();
        $command->info('WorkOS AuthKit installed successfully!');

        return Command::SUCCESS;
    }

    /**
     * Get selected components (for testing).
     *
     * @return array<string>
     */
    public function getSelectedComponents(): array
    {
        return $this->selectedComponents;
    }

    /**
     * Get laravel/workos strategy (for testing).
     */
    public function getLaravelWorkosStrategy(): ?string
    {
        return $this->laravelWorkosStrategy;
    }
}
