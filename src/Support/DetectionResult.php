<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

final readonly class DetectionResult
{
    /**
     * @param  array<string, string>  $envVars
     */
    public function __construct(
        public bool $hasLaravelWorkos,
        public bool $hasBreeze,
        public bool $hasJetstream,
        public bool $hasFortify,
        public bool $hasExistingWorkosConfig,
        public bool $hasServicesWorkosConfig,
        public array $envVars,
    ) {}

    public function hasExistingAuth(): bool
    {
        return $this->hasBreeze || $this->hasJetstream || $this->hasFortify;
    }

    public function hasAnyWorkosSetup(): bool
    {
        return $this->hasLaravelWorkos || $this->hasExistingWorkosConfig || $this->hasServicesWorkosConfig;
    }

    public function hasEnvVar(string $name): bool
    {
        return isset($this->envVars[$name]);
    }

    public function getEnvVar(string $name): ?string
    {
        return $this->envVars[$name] ?? null;
    }

    public function isFreshInstall(): bool
    {
        return ! $this->hasExistingAuth() && ! $this->hasAnyWorkosSetup();
    }
}
