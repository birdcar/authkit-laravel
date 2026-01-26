<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use WorkOS\AuthKit\Support\EnvironmentDetector;

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->basePath = '/test/project';
    $this->detector = new EnvironmentDetector($this->filesystem, $this->basePath);
});

afterEach(function () {
    Mockery::close();
});

it('detects fresh install when no auth packages exist', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode([
            'require' => ['php' => '^8.2'],
        ]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->isFreshInstall())->toBeTrue()
        ->and($result->hasExistingAuth())->toBeFalse()
        ->and($result->hasAnyWorkosSetup())->toBeFalse();
});

it('detects laravel/workos package', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode([
            'require' => [
                'php' => '^8.2',
                'laravel/workos' => '^1.0',
            ],
        ]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasLaravelWorkos)->toBeTrue()
        ->and($result->hasAnyWorkosSetup())->toBeTrue()
        ->and($result->isFreshInstall())->toBeFalse();
});

it('detects laravel/breeze package', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode([
            'require' => [
                'php' => '^8.2',
                'laravel/breeze' => '^2.0',
            ],
        ]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasBreeze)->toBeTrue()
        ->and($result->hasExistingAuth())->toBeTrue()
        ->and($result->isFreshInstall())->toBeFalse();
});

it('detects laravel/jetstream package', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode([
            'require' => [
                'php' => '^8.2',
                'laravel/jetstream' => '^5.0',
            ],
        ]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasJetstream)->toBeTrue()
        ->and($result->hasExistingAuth())->toBeTrue();
});

it('detects laravel/fortify package', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode([
            'require' => [
                'php' => '^8.2',
                'laravel/fortify' => '^1.0',
            ],
        ]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasFortify)->toBeTrue()
        ->and($result->hasExistingAuth())->toBeTrue();
});

it('detects existing workos config file', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode(['require' => []]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(true);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasExistingWorkosConfig)->toBeTrue()
        ->and($result->hasAnyWorkosSetup())->toBeTrue();
});

it('detects workos in services config', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode(['require' => []]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/config/services.php')
        ->andReturn("<?php\nreturn [\n    'workos' => [\n        'api_key' => env('WORKOS_API_KEY'),\n    ],\n];");

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasServicesWorkosConfig)->toBeTrue()
        ->and($result->hasAnyWorkosSetup())->toBeTrue();
});

it('parses env vars with WORKOS prefix', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode(['require' => []]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/.env')
        ->andReturn(<<<'ENV'
APP_NAME=Laravel
APP_ENV=local

WORKOS_API_KEY=sk_test_123
WORKOS_CLIENT_ID=client_456
WORKOS_REDIRECT_URI=http://localhost/callback

DB_CONNECTION=mysql
ENV);

    $result = $this->detector->detect();

    expect($result->envVars)->toHaveCount(3)
        ->and($result->hasEnvVar('WORKOS_API_KEY'))->toBeTrue()
        ->and($result->getEnvVar('WORKOS_API_KEY'))->toBe('sk_test_123')
        ->and($result->hasEnvVar('WORKOS_CLIENT_ID'))->toBeTrue()
        ->and($result->getEnvVar('WORKOS_CLIENT_ID'))->toBe('client_456')
        ->and($result->hasEnvVar('WORKOS_REDIRECT_URI'))->toBeTrue()
        ->and($result->getEnvVar('WORKOS_REDIRECT_URI'))->toBe('http://localhost/callback')
        ->and($result->hasEnvVar('APP_NAME'))->toBeFalse();
});

it('handles quoted env values', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode(['require' => []]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/.env')
        ->andReturn(<<<'ENV'
WORKOS_API_KEY="sk_test_123"
WORKOS_CLIENT_ID='client_456'
ENV);

    $result = $this->detector->detect();

    expect($result->getEnvVar('WORKOS_API_KEY'))->toBe('sk_test_123')
        ->and($result->getEnvVar('WORKOS_CLIENT_ID'))->toBe('client_456');
});

it('skips comment lines in env file', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode(['require' => []]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/.env')
        ->andReturn(<<<'ENV'
# WORKOS_API_KEY=commented_out
WORKOS_API_KEY=real_value
ENV);

    $result = $this->detector->detect();

    expect($result->envVars)->toHaveCount(1)
        ->and($result->getEnvVar('WORKOS_API_KEY'))->toBe('real_value');
});

it('handles missing composer.json gracefully', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasLaravelWorkos)->toBeFalse()
        ->and($result->hasBreeze)->toBeFalse()
        ->and($result->hasJetstream)->toBeFalse()
        ->and($result->hasFortify)->toBeFalse()
        ->and($result->isFreshInstall())->toBeTrue();
});

it('handles malformed composer.json gracefully', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn('{ invalid json }');

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasLaravelWorkos)->toBeFalse()
        ->and($result->hasBreeze)->toBeFalse();
});

it('handles missing env file gracefully', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode(['require' => []]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->envVars)->toBe([]);
});

it('detects dev dependencies', function () {
    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/composer.json')
        ->andReturn(true);

    $this->filesystem->shouldReceive('get')
        ->with('/test/project/composer.json')
        ->andReturn(json_encode([
            'require' => ['php' => '^8.2'],
            'require-dev' => ['laravel/breeze' => '^2.0'],
        ]));

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/workos.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/config/services.php')
        ->andReturn(false);

    $this->filesystem->shouldReceive('exists')
        ->with('/test/project/.env')
        ->andReturn(false);

    $result = $this->detector->detect();

    expect($result->hasBreeze)->toBeTrue();
});
