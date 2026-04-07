<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use WorkOS\AuthKit\Install\LaravelWorkosMigrator;

beforeEach(function () {
    $this->migrator = new LaravelWorkosMigrator;
});

afterEach(function () {
    Mockery::close();
});

it('removes workos config from services.php', function () {
    $servicesPath = config_path('services.php');
    File::ensureDirectoryExists(config_path());

    $original = <<<'PHP'
<?php

return [
    'stripe' => [
        'key' => env('STRIPE_KEY'),
    ],

    'workos' => [
        'key' => env('WORKOS_API_KEY'),
        'client_id' => env('WORKOS_CLIENT_ID'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
];
PHP;

    File::put($servicesPath, $original);

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();
    $command->shouldReceive('option')->with('force')->andReturn(true);

    Process::fake([
        'composer remove laravel/workos' => Process::result('', '', 0),
    ]);

    $this->migrator->migrate($command);

    $updated = File::get($servicesPath);
    expect($updated)->not->toContain("'workos'");
    expect($updated)->toContain("'stripe'");
    expect($updated)->toContain("'postmark'");

    File::delete($servicesPath);
});

it('warns when services.php removal regex fails', function () {
    $servicesPath = config_path('services.php');
    File::ensureDirectoryExists(config_path());

    // Non-standard format that won't match the regex patterns
    $nonStandard = <<<'PHP'
<?php

return [
    'workos' => env('WORKOS_API_KEY'),
];
PHP;

    File::put($servicesPath, $nonStandard);

    Process::fake([
        'composer remove laravel/workos' => Process::result('', '', 0),
    ]);

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();
    $command->shouldReceive('warn')
        ->with('Could not automatically remove WorkOS config. Please remove manually.')
        ->once();
    $command->shouldReceive('option')->with('force')->andReturn(true);

    $this->migrator->migrate($command);

    File::delete($servicesPath);
});

it('package removal calls composer remove', function () {
    Process::fake([
        'composer remove laravel/workos' => Process::result('', '', 0),
    ]);

    $servicesPath = config_path('services.php');
    File::ensureDirectoryExists(config_path());
    File::put($servicesPath, '<?php return [];');

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();
    $command->shouldReceive('option')->with('force')->andReturn(true);

    $this->migrator->migrate($command);

    Process::assertRan(fn ($process) => str_contains($process->command, 'composer remove laravel/workos'));

    File::delete($servicesPath);
});

it('handles composer remove failure gracefully', function () {
    Process::fake([
        'composer remove laravel/workos' => Process::result('', 'Some error', 1),
    ]);

    $servicesPath = config_path('services.php');
    File::ensureDirectoryExists(config_path());
    File::put($servicesPath, '<?php return [];');

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();
    $command->shouldReceive('error')
        ->with('Failed to remove laravel/workos. Please run manually:')
        ->once();
    $command->shouldReceive('option')->with('force')->andReturn(true);

    // Should not throw
    $this->migrator->migrate($command);

    File::delete($servicesPath);
});

it('force mode skips confirmation for package removal', function () {
    Process::fake([
        'composer remove laravel/workos' => Process::result('', '', 0),
    ]);

    $servicesPath = config_path('services.php');
    File::ensureDirectoryExists(config_path());
    File::put($servicesPath, '<?php return [];');

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();
    $command->shouldReceive('option')->with('force')->andReturn(true);
    $command->shouldNotReceive('confirm');

    $this->migrator->migrate($command);

    Process::assertRan(fn ($process) => str_contains($process->command, 'composer remove laravel/workos'));

    File::delete($servicesPath);
});

it('force mode skips confirmation for services.php cleanup', function () {
    $servicesPath = config_path('services.php');
    File::ensureDirectoryExists(config_path());

    File::put($servicesPath, <<<'PHP'
<?php

return [
    'workos' => [
        'key' => env('WORKOS_API_KEY'),
    ],
];
PHP);

    Process::fake([
        'composer remove laravel/workos' => Process::result('', '', 0),
    ]);

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnSelf();
    $command->shouldReceive('info')->andReturnSelf();
    $command->shouldReceive('line')->andReturnSelf();
    $command->shouldReceive('option')->with('force')->andReturn(true);
    $command->shouldNotReceive('confirm');

    $this->migrator->migrate($command);

    $updated = File::get($servicesPath);
    expect($updated)->not->toContain("'workos'");

    File::delete($servicesPath);
});
