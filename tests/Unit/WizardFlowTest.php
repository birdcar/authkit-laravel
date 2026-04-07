<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use WorkOS\AuthKit\Install\AuthSystemInstaller;
use WorkOS\AuthKit\Install\EnvManager;
use WorkOS\AuthKit\Install\LaravelWorkosMigrator;
use WorkOS\AuthKit\Install\MigrationPlanGenerator;
use WorkOS\AuthKit\Install\RouteInstaller;
use WorkOS\AuthKit\Install\WebhookInstaller;
use WorkOS\AuthKit\Install\WizardFlow;
use WorkOS\AuthKit\Tests\Helpers\DetectionResultFactory;

beforeEach(function () {
    $this->routeInstaller = Mockery::mock(RouteInstaller::class);
    $this->authSystemInstaller = Mockery::mock(AuthSystemInstaller::class);
    $this->webhookInstaller = Mockery::mock(WebhookInstaller::class);
    $this->migrator = Mockery::mock(LaravelWorkosMigrator::class);
    $this->envManager = Mockery::mock(EnvManager::class);
    $this->planGenerator = Mockery::mock(MigrationPlanGenerator::class);

    $this->wizard = new WizardFlow(
        $this->routeInstaller,
        $this->authSystemInstaller,
        $this->webhookInstaller,
        $this->migrator,
        $this->envManager,
        $this->planGenerator,
    );
});

afterEach(function () {
    Mockery::close();
});

it('skips laravel/workos question when not detected', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(false);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();

    // Should NOT receive choice for laravel/workos strategy
    $command->shouldNotReceive('choice');

    // Component selection - select none
    $command->shouldReceive('confirm')
        ->with('Install auth routes? (login, callback, logout)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install full auth system? (guards, providers, User model guidance)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install webhooks? (user sync, event handlers)', true)
        ->once()
        ->andReturn(false);

    $detection = DetectionResultFactory::freshInstall();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS)
        ->and($this->wizard->getLaravelWorkosStrategy())->toBeNull();
});

it('asks laravel/workos question when detected', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(false);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();

    // Should receive choice for laravel/workos strategy
    $command->shouldReceive('choice')
        ->with('How should we proceed?', Mockery::type('array'), 'replace')
        ->once()
        ->andReturn('keep');

    // Component selection - select none
    $command->shouldReceive('confirm')
        ->with('Install auth routes? (login, callback, logout)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install full auth system? (guards, providers, User model guidance)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install webhooks? (user sync, event handlers)', true)
        ->once()
        ->andReturn(false);

    $detection = DetectionResultFactory::withLaravelWorkos();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS)
        ->and($this->wizard->getLaravelWorkosStrategy())->toBe('keep');
});

it('returns correct components based on confirms', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(false);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    // Component selection - select only routes and webhooks
    $command->shouldReceive('confirm')
        ->with('Install auth routes? (login, callback, logout)', true)
        ->once()
        ->andReturn(true);
    $command->shouldReceive('confirm')
        ->with('Install full auth system? (guards, providers, User model guidance)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install webhooks? (user sync, event handlers)', true)
        ->once()
        ->andReturn(true);

    // Env changes
    $this->envManager->shouldReceive('planChanges')
        ->andReturn(['add' => [], 'modify' => []]);

    // Confirm proceed
    $command->shouldReceive('confirm')
        ->with('Proceed with these changes?', true)
        ->never(); // No env changes, so not asked

    // Execute
    $this->routeInstaller->shouldReceive('install')->once();
    $this->webhookInstaller->shouldReceive('install')->once();
    $this->authSystemInstaller->shouldNotReceive('install');

    $this->envManager->shouldReceive('applyChanges')->once();

    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::freshInstall();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS)
        ->and($this->wizard->getSelectedComponents())->toBe(['routes', 'webhooks']);
});

it('runs migrator when replace strategy selected', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(false);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    // Strategy selection
    $command->shouldReceive('choice')
        ->with('How should we proceed?', Mockery::type('array'), 'replace')
        ->once()
        ->andReturn('replace');

    // Component selection - select routes only
    $command->shouldReceive('confirm')
        ->with('Install auth routes? (login, callback, logout)', true)
        ->once()
        ->andReturn(true);
    $command->shouldReceive('confirm')
        ->with('Install full auth system? (guards, providers, User model guidance)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install webhooks? (user sync, event handlers)', true)
        ->once()
        ->andReturn(false);

    // Env changes
    $this->envManager->shouldReceive('planChanges')
        ->andReturn(['add' => [], 'modify' => []]);

    // Execute
    $this->migrator->shouldReceive('migrate')->once(); // Should be called
    $this->routeInstaller->shouldReceive('install')->once();
    $this->envManager->shouldReceive('applyChanges')->once();

    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::withLaravelWorkos();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS);
});

it('does not run migrator when augment strategy selected', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(false);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    // Strategy selection
    $command->shouldReceive('choice')
        ->with('How should we proceed?', Mockery::type('array'), 'replace')
        ->once()
        ->andReturn('augment');

    // Component selection - select routes only
    $command->shouldReceive('confirm')
        ->with('Install auth routes? (login, callback, logout)', true)
        ->once()
        ->andReturn(true);
    $command->shouldReceive('confirm')
        ->with('Install full auth system? (guards, providers, User model guidance)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install webhooks? (user sync, event handlers)', true)
        ->once()
        ->andReturn(false);

    // Env changes
    $this->envManager->shouldReceive('planChanges')
        ->andReturn(['add' => [], 'modify' => []]);

    // Execute
    $this->migrator->shouldNotReceive('migrate'); // Should NOT be called
    $this->routeInstaller->shouldReceive('install')->once();
    $this->envManager->shouldReceive('applyChanges')->once();

    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::withLaravelWorkos();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS);
});

it('force mode selects all components without prompting', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(true);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    // Should NOT receive any confirm or choice calls
    $command->shouldNotReceive('confirm');
    $command->shouldNotReceive('choice');

    $this->envManager->shouldReceive('planChanges')->andReturn(['add' => [], 'modify' => []]);
    $this->envManager->shouldReceive('applyChanges')->once();

    $this->routeInstaller->shouldReceive('install')->once();
    $this->authSystemInstaller->shouldReceive('install')->once();
    $this->webhookInstaller->shouldReceive('install')->once();

    // Force auto-confirms migrations so migrate is called (auth-system is selected)
    $command->shouldReceive('call')->with('migrate')->once();
    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::freshInstall();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS)
        ->and($this->wizard->getSelectedComponents())->toBe(['routes', 'auth-system', 'webhooks']);
});

it('force mode auto-confirms env changes', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(true);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    $command->shouldNotReceive('confirm');

    $this->envManager->shouldReceive('planChanges')->andReturn([
        'add' => ['WORKOS_API_KEY' => '', 'WORKOS_CLIENT_ID' => ''],
        'modify' => [],
    ]);
    $this->envManager->shouldReceive('applyChanges')->once();

    $this->routeInstaller->shouldReceive('install')->once();
    $this->authSystemInstaller->shouldReceive('install')->once();
    $this->webhookInstaller->shouldReceive('install')->once();

    // Force auto-confirms migrations so migrate is called (auth-system is selected)
    $command->shouldReceive('call')->with('migrate')->once();
    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::freshInstall();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS);
});

it('force mode auto-confirms migrations', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(true);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    $command->shouldNotReceive('confirm');

    $this->envManager->shouldReceive('planChanges')->andReturn(['add' => [], 'modify' => []]);
    $this->envManager->shouldReceive('applyChanges')->once();

    $this->routeInstaller->shouldReceive('install')->once();
    $this->authSystemInstaller->shouldReceive('install')->once();
    $this->webhookInstaller->shouldReceive('install')->once();

    $command->shouldReceive('call')->with('migrate')->once();
    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::freshInstall();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS);
});

it('force mode auto-selects replace for laravel/workos', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(true);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    $command->shouldNotReceive('choice');
    $command->shouldNotReceive('confirm');

    $this->envManager->shouldReceive('planChanges')->andReturn(['add' => [], 'modify' => []]);
    $this->envManager->shouldReceive('applyChanges')->once();

    $this->migrator->shouldReceive('migrate')->once();
    $this->routeInstaller->shouldReceive('install')->once();
    $this->authSystemInstaller->shouldReceive('install')->once();
    $this->webhookInstaller->shouldReceive('install')->once();

    // Force auto-confirms migrations so migrate is called (auth-system is selected)
    $command->shouldReceive('call')->with('migrate')->once();
    $command->shouldReceive('components->info')->andReturnSelf();

    $detection = DetectionResultFactory::withLaravelWorkos();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::SUCCESS)
        ->and($this->wizard->getLaravelWorkosStrategy())->toBe('replace');
});

it('returns failure when env changes declined', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('option')->with('force')->andReturn(false);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('warn')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();

    // Component selection - select routes
    $command->shouldReceive('confirm')
        ->with('Install auth routes? (login, callback, logout)', true)
        ->once()
        ->andReturn(true);
    $command->shouldReceive('confirm')
        ->with('Install full auth system? (guards, providers, User model guidance)', true)
        ->once()
        ->andReturn(false);
    $command->shouldReceive('confirm')
        ->with('Install webhooks? (user sync, event handlers)', true)
        ->once()
        ->andReturn(false);

    // Env changes exist
    $this->envManager->shouldReceive('planChanges')
        ->andReturn(['add' => ['WORKOS_CLIENT_ID' => ''], 'modify' => []]);

    // Decline env changes
    $command->shouldReceive('confirm')
        ->with('Proceed with these changes?', true)
        ->once()
        ->andReturn(false);

    $detection = DetectionResultFactory::freshInstall();

    $result = $this->wizard->run($command, $detection);

    expect($result)->toBe(Command::FAILURE);
});
