<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->listenerPath = app_path('Listeners');

    if (File::isDirectory($this->listenerPath)) {
        File::cleanDirectory($this->listenerPath);
    }
});

afterEach(function () {
    if (File::isDirectory($this->listenerPath)) {
        File::deleteDirectory($this->listenerPath);
    }
});

it('generates a single-event listener with --events flag', function () {
    $this->artisan('workos:make-listener', [
        'name' => 'SyncUser',
        '--events' => ['WorkOSUserCreated'],
    ])->assertSuccessful();

    $path = app_path('Listeners/SyncUser.php');
    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);
    expect($contents)
        ->toContain('declare(strict_types=1)')
        ->toContain('namespace App\Listeners')
        ->toContain('use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;')
        ->toContain('use WorkOS\AuthKit\Listeners\Concerns\HandlesWorkOSEvents;')
        ->toContain('use HandlesWorkOSEvents;')
        ->toContain('public function handle(WorkOSUserCreated $event): void');
});

it('generates a multi-event listener with union type hint', function () {
    $this->artisan('workos:make-listener', [
        'name' => 'SyncUser',
        '--events' => ['WorkOSUserCreated', 'WorkOSUserUpdated'],
    ])->assertSuccessful();

    $contents = File::get(app_path('Listeners/SyncUser.php'));
    expect($contents)
        ->toContain('use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;')
        ->toContain('use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;')
        ->toContain('public function handle(WorkOSUserCreated|WorkOSUserUpdated $event): void');
});

it('generates a catch-all listener for WorkOSEventReceived', function () {
    $this->artisan('workos:make-listener', [
        'name' => 'HandleAllEvents',
        '--events' => ['WorkOSEventReceived'],
    ])->assertSuccessful();

    $contents = File::get(app_path('Listeners/HandleAllEvents.php'));
    expect($contents)
        ->toContain('use WorkOS\AuthKit\Events\WorkOSEventReceived;')
        ->toContain('public function handle(WorkOSEventReceived $event): void');
});

it('suggests SyncUser name for user events', function () {
    $this->artisan('workos:make-listener', [
        '--events' => ['WorkOSUserCreated', 'WorkOSUserUpdated'],
    ])
        ->expectsQuestion('Listener class name', 'SyncUser')
        ->assertSuccessful();

    expect(File::exists(app_path('Listeners/SyncUser.php')))->toBeTrue();
});

it('suggests HandleWorkOSEvents for mixed-domain events', function () {
    $this->artisan('workos:make-listener', [
        '--events' => ['WorkOSUserCreated', 'WorkOSOrganizationCreated'],
    ])
        ->expectsQuestion('Listener class name', 'HandleWorkOSEvents')
        ->assertSuccessful();

    expect(File::exists(app_path('Listeners/HandleWorkOSEvents.php')))->toBeTrue();
});

it('prompts before overwriting existing file', function () {
    File::ensureDirectoryExists(app_path('Listeners'));
    File::put(app_path('Listeners/SyncUser.php'), '<?php // existing');

    $this->artisan('workos:make-listener', [
        'name' => 'SyncUser',
        '--events' => ['WorkOSUserCreated'],
    ])
        ->expectsConfirmation(
            'File '.app_path('Listeners/SyncUser.php').' already exists. Overwrite?',
            'no'
        )
        ->assertSuccessful();

    expect(File::get(app_path('Listeners/SyncUser.php')))->toBe('<?php // existing');
});

it('overwrites file when confirmed', function () {
    File::ensureDirectoryExists(app_path('Listeners'));
    File::put(app_path('Listeners/SyncUser.php'), '<?php // old');

    $this->artisan('workos:make-listener', [
        'name' => 'SyncUser',
        '--events' => ['WorkOSUserCreated'],
    ])
        ->expectsConfirmation(
            'File '.app_path('Listeners/SyncUser.php').' already exists. Overwrite?',
            'yes'
        )
        ->assertSuccessful();

    expect(File::get(app_path('Listeners/SyncUser.php')))->toContain('class SyncUser');
});

it('creates Listeners directory if it does not exist', function () {
    File::deleteDirectory(app_path('Listeners'));

    $this->artisan('workos:make-listener', [
        'name' => 'SyncUser',
        '--events' => ['WorkOSUserCreated'],
    ])->assertSuccessful();

    expect(File::isDirectory(app_path('Listeners')))->toBeTrue()
        ->and(File::exists(app_path('Listeners/SyncUser.php')))->toBeTrue();
});

it('returns failure when no events match', function () {
    $this->artisan('workos:make-listener', [
        'name' => 'BadListener',
        '--events' => ['NonExistentEvent'],
    ])->assertFailed();
});
