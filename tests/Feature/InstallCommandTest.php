<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Helpers\DetectionResultFactory;
use WorkOS\AuthKit\Install\EnvManager;
use WorkOS\AuthKit\Install\MigrationPlanGenerator;
use WorkOS\AuthKit\Support\EnvironmentDetector;

beforeEach(function () {
    // Clean up any published files from previous tests
    if (File::exists(config_path('workos.php'))) {
        File::delete(config_path('workos.php'));
    }
});

afterEach(function () {
    // Clean up published config
    if (File::exists(config_path('workos.php'))) {
        File::delete(config_path('workos.php'));
    }
    Mockery::close();
});

it('displays detection summary before install', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('Environment Detection')
        ->assertExitCode(0);
});

it('displays fresh install message when no existing auth', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('No existing auth setup detected')
        ->assertExitCode(0);
});

it('displays existing auth warning when Breeze detected', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withBreeze());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('Existing auth packages detected')
        ->expectsOutputToContain('Laravel Breeze')
        ->assertExitCode(0);
});

it('mini install publishes only config file', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('Running minimal install')
        ->expectsOutputToContain('Published config/workos.php')
        ->expectsOutputToContain('Minimal install complete')
        ->assertExitCode(0);

    expect(File::exists(config_path('workos.php')))->toBeTrue();
});

it('mini install does not run migrations', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->doesntExpectOutputToContain('Published migrations')
        ->assertExitCode(0);
});

it('mini install displays next steps including env vars', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('WORKOS_API_KEY')
        ->expectsOutputToContain('WORKOS_CLIENT_ID')
        ->expectsOutputToContain('WORKOS_REDIRECT_URI')
        ->expectsOutputToContain('AUTH_GUARD')
        ->assertExitCode(0);
});

it('mini install skips env vars already configured', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withAllEnvVars());

    $this->app->instance(EnvironmentDetector::class, $detector);

    // When all env vars are present, it should skip showing them
    $this->artisan('workos:install --mini')
        ->doesntExpectOutputToContain('Add to your')
        ->assertExitCode(0);
});

it('force option overwrites existing config with mini install', function () {
    // Create an existing config file
    File::ensureDirectoryExists(config_path());
    File::put(config_path('workos.php'), '<?php return ["existing" => true];');

    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withExistingWorkosConfig());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini --force')
        ->expectsOutputToContain('Published config/workos.php')
        ->assertExitCode(0);

    // The config should have been overwritten
    $contents = File::get(config_path('workos.php'));
    expect($contents)->not->toContain('existing');
});

it('displays detected env vars', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall([
        'WORKOS_API_KEY' => 'sk_test_123',
    ]));

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('Environment variables found')
        ->expectsOutputToContain('WORKOS_API_KEY')
        ->assertExitCode(0);
});

it('displays workos setup detection', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::fresh([
        'hasLaravelWorkos' => true,
        'hasExistingWorkosConfig' => true,
    ]));

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install --mini')
        ->expectsOutputToContain('WorkOS setup detected')
        ->expectsOutputToContain('laravel/workos package')
        ->expectsOutputToContain('config/workos.php exists')
        ->assertExitCode(0);
});

// Wizard flow tests

it('wizard asks component selection questions', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withAllEnvVars());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->expectsOutputToContain('No components selected')
        ->assertExitCode(0);
});

it('wizard installs routes component when selected', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withAllEnvVars());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'yes')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->expectsOutputToContain('Auth routes enabled')
        ->assertExitCode(0);
});

it('wizard asks laravel/workos strategy when detected', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::fresh([
        'hasLaravelWorkos' => true,
        'envVars' => [
            'WORKOS_API_KEY' => 'sk_test',
            'WORKOS_CLIENT_ID' => 'client_test',
            'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
        ],
    ]));

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install')
        ->expectsOutputToContain('laravel/workos package detected')
        ->expectsChoice('How should we proceed?', 'keep', [
            'replace' => 'Replace entirely (migrate config, remove package)',
            'augment' => 'Augment/extend (add authkit features alongside)',
            'keep' => 'Keep both (install alongside, no migration)',
        ])
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->assertExitCode(0);
});

it('wizard confirms env changes before applying', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock EnvManager to not actually write to file
    $envManager = Mockery::mock(EnvManager::class);
    $envManager->shouldReceive('planChanges')->andReturn([
        'add' => ['WORKOS_CLIENT_ID' => '', 'WORKOS_API_KEY' => ''],
        'modify' => [],
    ]);
    $envManager->shouldReceive('applyChanges')->once();
    $this->app->instance(EnvManager::class, $envManager);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'yes')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->expectsOutputToContain('The following .env changes will be made')
        ->expectsConfirmation('Proceed with these changes?', 'yes')
        ->assertExitCode(0);
});

it('wizard cancels when env changes declined', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::freshInstall());

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock EnvManager
    $envManager = Mockery::mock(EnvManager::class);
    $envManager->shouldReceive('planChanges')->andReturn([
        'add' => ['WORKOS_CLIENT_ID' => ''],
        'modify' => [],
    ]);
    $envManager->shouldNotReceive('applyChanges');
    $this->app->instance(EnvManager::class, $envManager);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'yes')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->expectsConfirmation('Proceed with these changes?', 'no')
        ->expectsOutputToContain('Installation cancelled')
        ->assertExitCode(1);
});

it('wizard installs full auth system with migrations', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withAllEnvVars());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'yes')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->expectsOutputToContain('Published config/workos.php')
        ->expectsOutputToContain('Add these traits to your User model')
        ->assertExitCode(0);

    expect(File::exists(config_path('workos.php')))->toBeTrue();
});

it('wizard shows webhook setup instructions', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withAllEnvVars());

    $this->app->instance(EnvironmentDetector::class, $detector);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'yes')
        ->expectsOutputToContain('Webhook route enabled')
        ->expectsOutputToContain('Configure webhook in WorkOS Dashboard')
        ->assertExitCode(0);
});

// Migration plan tests

it('wizard displays migration plan summary when Breeze detected', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::fresh([
        'hasBreeze' => true,
        'envVars' => [
            'WORKOS_API_KEY' => 'sk_test',
            'WORKOS_CLIENT_ID' => 'client_test',
            'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
        ],
    ]));

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock the plan generator to avoid file system operations
    $planGenerator = Mockery::mock(MigrationPlanGenerator::class);
    $planGenerator->shouldReceive('displaySummary')->once();
    $planGenerator->shouldReceive('generate')->andReturn('/tmp/workos-migration-plan.md');
    $this->app->instance(MigrationPlanGenerator::class, $planGenerator);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->assertExitCode(0);
});

it('wizard displays migration plan summary when Jetstream detected', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::fresh([
        'hasJetstream' => true,
        'envVars' => [
            'WORKOS_API_KEY' => 'sk_test',
            'WORKOS_CLIENT_ID' => 'client_test',
            'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
        ],
    ]));

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock the plan generator to avoid file system operations
    $planGenerator = Mockery::mock(MigrationPlanGenerator::class);
    $planGenerator->shouldReceive('displaySummary')->once();
    $planGenerator->shouldReceive('generate')->andReturn('/tmp/workos-migration-plan.md');
    $this->app->instance(MigrationPlanGenerator::class, $planGenerator);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->assertExitCode(0);
});

it('wizard displays migration plan summary when Fortify detected', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::fresh([
        'hasFortify' => true,
        'envVars' => [
            'WORKOS_API_KEY' => 'sk_test',
            'WORKOS_CLIENT_ID' => 'client_test',
            'WORKOS_REDIRECT_URI' => 'http://localhost/callback',
        ],
    ]));

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock the plan generator to avoid file system operations
    $planGenerator = Mockery::mock(MigrationPlanGenerator::class);
    $planGenerator->shouldReceive('displaySummary')->once();
    $planGenerator->shouldReceive('generate')->andReturn('/tmp/workos-migration-plan.md');
    $this->app->instance(MigrationPlanGenerator::class, $planGenerator);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->assertExitCode(0);
});

it('wizard skips migration plan for fresh install', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withAllEnvVars());

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock the plan generator - should NOT be called for fresh install
    $planGenerator = Mockery::mock(MigrationPlanGenerator::class);
    $planGenerator->shouldNotReceive('displaySummary');
    $planGenerator->shouldNotReceive('generate');
    $this->app->instance(MigrationPlanGenerator::class, $planGenerator);

    $this->artisan('workos:install')
        ->expectsConfirmation('Install auth routes? (login, callback, logout)', 'no')
        ->expectsConfirmation('Install full auth system? (guards, providers, User model guidance)', 'no')
        ->expectsConfirmation('Install webhooks? (user sync, event handlers)', 'no')
        ->assertExitCode(0);
});

it('mini install skips migration plan generation', function () {
    $detector = Mockery::mock(EnvironmentDetector::class);
    $detector->shouldReceive('detect')->andReturn(DetectionResultFactory::withBreeze());

    $this->app->instance(EnvironmentDetector::class, $detector);

    // Mock the plan generator - should NOT be called for mini install
    $planGenerator = Mockery::mock(MigrationPlanGenerator::class);
    $planGenerator->shouldNotReceive('displaySummary');
    $planGenerator->shouldNotReceive('generate');
    $this->app->instance(MigrationPlanGenerator::class, $planGenerator);

    $this->artisan('workos:install --mini')
        ->assertExitCode(0);
});
