<?php

declare(strict_types=1);

namespace Tests\Helpers;

use WorkOS\AuthKit\Support\DetectionResult;

final class DetectionResultFactory
{
    /**
     * Create a fresh DetectionResult with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fresh(array $overrides = []): DetectionResult
    {
        return new DetectionResult(
            hasLaravelWorkos: $overrides['hasLaravelWorkos'] ?? false,
            hasBreeze: $overrides['hasBreeze'] ?? false,
            hasJetstream: $overrides['hasJetstream'] ?? false,
            hasFortify: $overrides['hasFortify'] ?? false,
            hasExistingWorkosConfig: $overrides['hasExistingWorkosConfig'] ?? false,
            hasServicesWorkosConfig: $overrides['hasServicesWorkosConfig'] ?? false,
            envVars: $overrides['envVars'] ?? [],
        );
    }

    /**
     * Create a DetectionResult for a fresh Laravel install with no auth.
     *
     * @param  array<string, string>  $envVars
     */
    public static function freshInstall(array $envVars = []): DetectionResult
    {
        return self::fresh(['envVars' => $envVars]);
    }

    /**
     * Create a DetectionResult with Breeze detected.
     *
     * @param  array<string, string>  $envVars
     */
    public static function withBreeze(array $envVars = []): DetectionResult
    {
        return self::fresh([
            'hasBreeze' => true,
            'envVars' => $envVars,
        ]);
    }

    /**
     * Create a DetectionResult with Jetstream detected.
     *
     * @param  array<string, string>  $envVars
     */
    public static function withJetstream(array $envVars = []): DetectionResult
    {
        return self::fresh([
            'hasJetstream' => true,
            'envVars' => $envVars,
        ]);
    }

    /**
     * Create a DetectionResult with Fortify detected.
     *
     * @param  array<string, string>  $envVars
     */
    public static function withFortify(array $envVars = []): DetectionResult
    {
        return self::fresh([
            'hasFortify' => true,
            'envVars' => $envVars,
        ]);
    }

    /**
     * Create a DetectionResult with laravel/workos detected.
     *
     * @param  array<string, string>  $envVars
     */
    public static function withLaravelWorkos(array $envVars = []): DetectionResult
    {
        return self::fresh([
            'hasLaravelWorkos' => true,
            'envVars' => $envVars,
        ]);
    }

    /**
     * Create a DetectionResult with all WorkOS env vars configured.
     */
    public static function withAllEnvVars(): DetectionResult
    {
        return self::fresh([
            'envVars' => [
                'WORKOS_API_KEY' => 'sk_test',
                'WORKOS_CLIENT_ID' => 'client_test',
                'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
            ],
        ]);
    }

    /**
     * Create a DetectionResult with existing WorkOS config.
     *
     * @param  array<string, string>  $envVars
     */
    public static function withExistingWorkosConfig(array $envVars = []): DetectionResult
    {
        return self::fresh([
            'hasExistingWorkosConfig' => true,
            'envVars' => $envVars,
        ]);
    }
}
