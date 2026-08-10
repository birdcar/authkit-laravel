<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// Generated files land in the Testbench skeleton's app/Listeners — shared
// across parallel workers, so class names are prefixed and the directory is
// swept after each case.

afterEach(function (): void {
    File::deleteDirectory(app_path('Listeners'));
});

it('generates a typed listener for a bounded event, with the idempotency contract in its doc-comment', function (): void {
    $this->artisan('make:workos-listener', ['name' => 'Phase4TypedListener', '--event' => 'UserCreated'])
        ->assertSuccessful();

    $path = app_path('Listeners/Phase4TypedListener.php');
    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);
    expect($contents)->toContain('use Authkit\Authkit\Events\Workos\UserCreated;')
        ->and($contents)->toContain('public function handle(UserCreated $event): void')
        ->and($contents)->toContain('at-least-once')
        ->and($contents)->toContain('Keep this handler idempotent')
        ->and($contents)->not->toContain('GenericWorkosEvent');
});

it('generates a GenericWorkosEvent listener when --event is omitted, including the dsync branching example', function (): void {
    $this->artisan('make:workos-listener', ['name' => 'Phase4GenericListener'])
        ->assertSuccessful();

    $contents = File::get(app_path('Listeners/Phase4GenericListener.php'));
    expect($contents)->toContain('use Authkit\Authkit\Events\GenericWorkosEvent;')
        ->and($contents)->toContain('public function handle(GenericWorkosEvent $event): void')
        ->and($contents)->toContain("if (\$event->type === 'dsync.user.created') { ... }")
        ->and($contents)->toContain('at-least-once');
});

it('falls back to the generic stub for an unrecognized --event value instead of erroring', function (): void {
    $this->artisan('make:workos-listener', ['name' => 'Phase4FallbackListener', '--event' => 'TotallyMadeUp'])
        ->assertSuccessful();

    $contents = File::get(app_path('Listeners/Phase4FallbackListener.php'));
    expect($contents)->toContain('use Authkit\Authkit\Events\GenericWorkosEvent;')
        ->and($contents)->toContain('public function handle(GenericWorkosEvent $event): void');
});

it('treats a real out-of-scope WorkOS type string as generic, never as an error', function (): void {
    $this->artisan('make:workos-listener', ['name' => 'Phase4DsyncListener', '--event' => 'dsync.user.created'])
        ->assertSuccessful();

    $contents = File::get(app_path('Listeners/Phase4DsyncListener.php'));
    expect($contents)->toContain('use Authkit\Authkit\Events\GenericWorkosEvent;');
});

it('refuses to overwrite an existing listener without --force, mirroring core make:listener', function (): void {
    $this->artisan('make:workos-listener', ['name' => 'Phase4DuplicateListener', '--event' => 'UserCreated'])
        ->assertSuccessful();

    File::put(app_path('Listeners/Phase4DuplicateListener.php'), '<?php // sentinel: must survive');

    // Core make:* generators report "already exists" as a console error while
    // still exiting 0 (GeneratorCommand::handle() returns false, which the
    // framework casts to exit code 0) — the contract is the error message and,
    // above all, the untouched file.
    $this->artisan('make:workos-listener', ['name' => 'Phase4DuplicateListener', '--event' => 'UserCreated'])
        ->expectsOutputToContain('already exists');

    expect(File::get(app_path('Listeners/Phase4DuplicateListener.php')))->toContain('sentinel: must survive');
});

it('overwrites with --force', function (): void {
    $this->artisan('make:workos-listener', ['name' => 'Phase4ForceListener'])
        ->assertSuccessful();

    File::put(app_path('Listeners/Phase4ForceListener.php'), '<?php // sentinel: should be replaced');

    $this->artisan('make:workos-listener', ['name' => 'Phase4ForceListener', '--event' => 'UserCreated', '--force' => true])
        ->assertSuccessful();

    expect(File::get(app_path('Listeners/Phase4ForceListener.php')))->toContain('use Authkit\Authkit\Events\Workos\UserCreated;');
});
