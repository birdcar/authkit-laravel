<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

final class NodeToolingDetector
{
    /**
     * Probe for available Node package runners in order: bun, npx, pnpm.
     *
     * @return string|null 'bunx', 'npx', 'pnpm dlx', or null
     */
    public function detect(): ?string
    {
        if (Process::run(['command', '-v', 'bun'])->successful()) {
            return 'bunx';
        }

        if (Process::run(['command', '-v', 'npx'])->successful()) {
            return 'npx';
        }

        if (Process::run(['command', '-v', 'pnpm'])->successful()) {
            return 'pnpm dlx';
        }

        return null;
    }

    /**
     * Run `workos install` using the detected Node package runner.
     *
     * @return bool true on success, false if no runner detected or CLI fails
     */
    public function runInstall(Command $command): bool
    {
        $runner = $this->detect();

        if ($runner === null) {
            return false;
        }

        $cmd = $this->buildCommand($runner, ['install', '--integration', 'php-laravel', '--no-branch', '--no-commit']);

        $result = Process::run($cmd, function (string $type, string $output) use ($command): void {
            $command->line($output);
        });

        return $result->successful();
    }

    /**
     * Run `workos doctor` using the detected Node package runner.
     * Failures are warned but not fatal.
     */
    public function runDoctor(Command $command): void
    {
        $runner = $this->detect();

        if ($runner === null) {
            return;
        }

        $cmd = $this->buildCommand($runner, ['doctor', '--skip-ai']);

        $result = Process::run($cmd, function (string $type, string $output) use ($command): void {
            $command->line($output);
        });

        if (! $result->successful()) {
            $command->warn('WorkOS doctor check did not complete successfully.');
        }
    }

    /**
     * Build the command array for the given runner and subcommand arguments.
     *
     * @param  string[]  $subcommand
     * @return string[]
     */
    private function buildCommand(string $runner, array $subcommand): array
    {
        // pnpm dlx is two tokens; all others are single tokens
        $prefix = $runner === 'pnpm dlx'
            ? ['pnpm', 'dlx']
            : [$runner];

        return [...$prefix, 'workos@latest', ...$subcommand];
    }
}
