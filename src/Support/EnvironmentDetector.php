<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

class EnvironmentDetector
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    public function detect(): DetectionResult
    {
        return new DetectionResult(
            hasLaravelWorkos: $this->detectLaravelWorkos(),
            hasBreeze: $this->detectBreeze(),
            hasJetstream: $this->detectJetstream(),
            hasFortify: $this->detectFortify(),
            hasExistingWorkosConfig: $this->detectExistingWorkosConfig(),
            hasServicesWorkosConfig: $this->detectServicesWorkosConfig(),
            envVars: $this->detectEnvVars(),
        );
    }

    private function detectLaravelWorkos(): bool
    {
        return $this->hasComposerDependency('laravel/workos');
    }

    private function detectBreeze(): bool
    {
        return $this->hasComposerDependency('laravel/breeze');
    }

    private function detectJetstream(): bool
    {
        return $this->hasComposerDependency('laravel/jetstream');
    }

    private function detectFortify(): bool
    {
        return $this->hasComposerDependency('laravel/fortify');
    }

    private function detectExistingWorkosConfig(): bool
    {
        return $this->files->exists($this->basePath.'/config/workos.php');
    }

    private function detectServicesWorkosConfig(): bool
    {
        $servicesPath = $this->basePath.'/config/services.php';

        if (! $this->files->exists($servicesPath)) {
            return false;
        }

        $contents = $this->files->get($servicesPath);

        return str_contains($contents, "'workos'") || str_contains($contents, '"workos"');
    }

    /**
     * @return array<string, string>
     */
    private function detectEnvVars(): array
    {
        $envPath = $this->basePath.'/.env';

        if (! $this->files->exists($envPath)) {
            return [];
        }

        $contents = $this->files->get($envPath);
        $envVars = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Only match WORKOS_ prefixed vars
            if (! str_starts_with($line, 'WORKOS_')) {
                continue;
            }

            // Parse key=value
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);

            // Remove quotes if present
            $value = trim($value, '"\'');

            $envVars[$key] = $value;
        }

        return $envVars;
    }

    private function hasComposerDependency(string $package): bool
    {
        $composerPath = $this->basePath.'/composer.json';

        if (! $this->files->exists($composerPath)) {
            return false;
        }

        $contents = $this->files->get($composerPath);

        try {
            $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning("Failed to parse composer.json: {$e->getMessage()}");

            return false;
        }

        if (! is_array($composer)) {
            return false;
        }

        $require = $composer['require'] ?? [];
        $requireDev = $composer['require-dev'] ?? [];

        return isset($require[$package]) || isset($requireDev[$package]);
    }
}
