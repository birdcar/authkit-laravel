<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use WorkOS\AuthKit\Install\EnvManager;
use WorkOS\AuthKit\Support\DetectionResult;

beforeEach(function () {
    $this->envPath = sys_get_temp_dir().'/test-'.uniqid().'.env';
    $this->envManager = new EnvManager($this->envPath);
});

afterEach(function () {
    if (file_exists($this->envPath)) {
        unlink($this->envPath);
    }
});

it('plans to add missing env vars', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [],
    );

    $changes = $this->envManager->planChanges($detection);

    expect($changes['add'])->toHaveKeys(['WORKOS_CLIENT_ID', 'WORKOS_API_KEY', 'WORKOS_REDIRECT_URI'])
        ->and($changes['modify'])->toBe([]);
});

it('does not add duplicate WORKOS_CLIENT_ID', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [
            'WORKOS_CLIENT_ID' => 'existing_client_id',
        ],
    );

    $changes = $this->envManager->planChanges($detection);

    expect($changes['add'])->not->toHaveKey('WORKOS_CLIENT_ID')
        ->and($changes['add'])->toHaveKeys(['WORKOS_API_KEY', 'WORKOS_REDIRECT_URI']);
});

it('does not add any vars when all present', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [
            'WORKOS_CLIENT_ID' => 'client_id',
            'WORKOS_API_KEY' => 'api_key',
            'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
        ],
    );

    $changes = $this->envManager->planChanges($detection);

    expect($changes['add'])->toBe([])
        ->and($changes['modify'])->toBe([]);
});

it('applies changes to env file', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [],
    );

    // Create initial env file
    file_put_contents($this->envPath, "APP_NAME=Laravel\nAPP_ENV=local\n");

    $this->envManager->applyChanges($detection);

    $contents = file_get_contents($this->envPath);

    expect($contents)->toContain('WORKOS_CLIENT_ID=')
        ->and($contents)->toContain('WORKOS_API_KEY=')
        ->and($contents)->toContain('WORKOS_REDIRECT_URI=')
        ->and($contents)->toContain('# WorkOS');
});

it('does not duplicate section header', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [
            'WORKOS_CLIENT_ID' => 'existing',
        ],
    );

    // Create initial env file with existing WorkOS var
    file_put_contents($this->envPath, "APP_NAME=Laravel\nWORKOS_CLIENT_ID=existing\n");

    $this->envManager->applyChanges($detection);

    $contents = file_get_contents($this->envPath);

    // Should not add section header since WORKOS_ already exists
    expect($contents)->not->toContain('# WorkOS');
});

it('handles missing env file', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [],
    );

    // Don't create the env file
    $this->envManager->applyChanges($detection);

    // Should create the file
    expect(file_exists($this->envPath))->toBeTrue();

    $contents = file_get_contents($this->envPath);
    expect($contents)->toContain('WORKOS_CLIENT_ID=');
});

it('does not modify file when no changes needed', function () {
    $detection = new DetectionResult(
        hasLaravelWorkos: false,
        hasBreeze: false,
        hasJetstream: false,
        hasFortify: false,
        hasExistingWorkosConfig: false,
        hasServicesWorkosConfig: false,
        envVars: [
            'WORKOS_CLIENT_ID' => 'client_id',
            'WORKOS_API_KEY' => 'api_key',
            'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
        ],
    );

    $originalContent = "APP_NAME=Laravel\n";
    file_put_contents($this->envPath, $originalContent);

    $this->envManager->applyChanges($detection);

    $contents = file_get_contents($this->envPath);
    expect($contents)->toBe($originalContent);
});
