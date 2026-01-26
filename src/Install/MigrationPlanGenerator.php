<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use WorkOS\AuthKit\Install\Plans\BreezeMigrationPlan;
use WorkOS\AuthKit\Install\Plans\FortifyMigrationPlan;
use WorkOS\AuthKit\Install\Plans\JetstreamMigrationPlan;
use WorkOS\AuthKit\Install\Plans\MigrationPlan;
use WorkOS\AuthKit\Support\DetectionResult;

class MigrationPlanGenerator
{
    public function __construct(
        private string $storagePath,
    ) {}

    public function generate(DetectionResult $detection, string $projectPath): ?string
    {
        $plan = $this->selectPlan($detection);

        if ($plan === null) {
            return null;
        }

        $markdown = $plan->generate($projectPath);
        $outputPath = $this->storagePath.'/workos-migration-plan.md';

        File::put($outputPath, $markdown);

        return $outputPath;
    }

    public function displaySummary(Command $command, DetectionResult $detection): void
    {
        $plan = $this->selectPlan($detection);

        if ($plan === null) {
            return;
        }

        $command->newLine();
        $command->warn('Existing authentication system detected');
        $command->line("  <fg=cyan>System:</> {$plan->getSummary()}");
        $command->line("  <fg=cyan>Risk Level:</> {$plan->getRiskLevel()}");
        $command->newLine();
    }

    public function selectPlan(DetectionResult $detection): ?MigrationPlan
    {
        // Priority: Jetstream > Breeze > Fortify (highest risk first)
        if ($detection->hasJetstream) {
            return new JetstreamMigrationPlan;
        }

        if ($detection->hasBreeze) {
            return new BreezeMigrationPlan;
        }

        if ($detection->hasFortify) {
            return new FortifyMigrationPlan;
        }

        return null;
    }
}
