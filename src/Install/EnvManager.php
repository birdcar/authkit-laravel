<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Support\Facades\File;
use WorkOS\AuthKit\Support\DetectionResult;

class EnvManager
{
    public function __construct(
        private string $envPath,
    ) {}

    /**
     * @return array{add: array<string, string>, modify: array<string, string>}
     */
    public function planChanges(DetectionResult $detection): array
    {
        $required = [
            'WORKOS_CLIENT_ID' => '',
            'WORKOS_API_KEY' => '',
            'WORKOS_REDIRECT_URI' => config('app.url').'/auth/callback',
        ];

        $add = [];

        foreach ($required as $key => $default) {
            if (! $detection->hasEnvVar($key)) {
                $add[$key] = $default;
            }
        }

        return ['add' => $add, 'modify' => []];
    }

    public function applyChanges(DetectionResult $detection): void
    {
        $changes = $this->planChanges($detection);

        if (empty($changes['add'])) {
            return;
        }

        $envContent = File::exists($this->envPath)
            ? File::get($this->envPath)
            : '';

        // Add a section header if we're adding WorkOS vars
        if (! str_contains($envContent, 'WORKOS_')) {
            $envContent = rtrim($envContent)."\n\n# WorkOS\n";
        } else {
            $envContent = rtrim($envContent)."\n";
        }

        foreach ($changes['add'] as $key => $value) {
            $envContent .= "{$key}={$value}\n";
        }

        File::put($this->envPath, $envContent);
    }
}
