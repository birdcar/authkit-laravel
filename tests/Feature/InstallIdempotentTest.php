<?php

declare(strict_types=1);

use Authkit\Authkit\AuthkitServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * This is deliberately the only test file that writes to the Testbench skeleton's
 * .env / .env.example paths, so the suite stays safe under --parallel.
 *
 * The config file that `authkit:install` publishes into the skeleton's config/
 * directory is intentionally NOT deleted afterwards. Testbench's LoadConfiguration
 * bootstrapper globs that directory and require()s every match on each app boot, so
 * deleting a file another parallel worker has already globbed makes that worker die
 * on a missing include; leaving it only adds this package's own schema with
 * identical defaults. `composer clear` (testbench package:purge-skeleton) removes it.
 *
 * The tradeoff: once that file exists locally, any assertion that merely reads
 * config('authkit.*') could be satisfied by it rather than by the provider's
 * mergeConfigFrom. ExampleTest covers the merge explicitly against a cleared key
 * so that path stays honest.
 */
beforeEach(function (): void {
    $this->envPath = base_path('.env');
    $this->envExamplePath = base_path('.env.example');

    // Both files can pre-exist in the skeleton (.env.example ships with it, and
    // .env appears once anyone has run `composer serve`) — preserve and restore.
    $this->originalEnv = is_file($this->envPath)
        ? file_get_contents($this->envPath)
        : null;
    $this->originalEnvExample = is_file($this->envExamplePath)
        ? file_get_contents($this->envExamplePath)
        : null;

    file_put_contents($this->envPath, "APP_NAME=Testbench\n");
    file_put_contents($this->envExamplePath, "APP_NAME=Testbench\n");
});

afterEach(function (): void {
    $restore = function (?string $original, string $path): void {
        if (is_string($original)) {
            file_put_contents($path, $original);
        } elseif (is_file($path)) {
            unlink($path);
        }
    };

    $restore($this->originalEnv, $this->envPath);
    $restore($this->originalEnvExample, $this->envExamplePath);

    // Published migrations, unlike the published config above, MUST be removed.
    // The skeleton's database/migrations is a default migration path, so a copy
    // left behind makes `artisan migrate` apply this package's migrations twice —
    // once from the package path, once from the published copy — and a DB-backed
    // test dies on "duplicate column name". The config-file race that argues for
    // leaving that file behind does not apply: migration files are read by a
    // running migrator, not glob-required on boot.
    //
    // Derived from the package's own migration directory rather than hardcoded, so
    // this keeps working as later phases add migrations. vendor:publish renames
    // each copy with a fresh timestamp, hence matching on the descriptive suffix.
    foreach ((array) glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $packageMigration) {
        $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename((string) $packageMigration));

        foreach ((array) glob(database_path('migrations').'/*_'.$suffix) as $published) {
            if (is_string($published) && is_file($published)) {
                unlink($published);
            }
        }
    }
});

it('registers the config publish group under the authkit-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(AuthkitServiceProvider::class, 'authkit-config');

    expect($paths)->toHaveCount(1);
    expect(realpath((string) array_key_first($paths)))
        ->toBe(realpath(dirname(__DIR__, 2).'/config/authkit.php'));
    expect(reset($paths))->toBe(config_path('authkit.php'));
});

it('no longer registers the pre-rename authkit-laravel-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(AuthkitServiceProvider::class, 'authkit-laravel-config'))->toBe([]);
});

it('leaves a published config identical to the package schema', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    expect(is_file(config_path('authkit.php')))->toBeTrue();

    // authkit:install publishes without --force and this file never deletes the
    // skeleton copy (deleting races other parallel workers), so a copy left by an
    // earlier run would still hold the old schema once config/authkit.php changes
    // — which Phase 2 will do. Republish with --force so the content assertion
    // tracks the current schema instead of going red on a stale local vendor tree.
    $this->artisan('vendor:publish', ['--tag' => 'authkit-config', '--force' => true])
        ->assertSuccessful();

    expect(md5_file(config_path('authkit.php')))
        ->toBe(md5_file(dirname(__DIR__, 2).'/config/authkit.php'));
});

it('appends every WorkOS env key exactly once', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    $contents = (string) file_get_contents($this->envPath);

    foreach (['WORKOS_API_KEY', 'WORKOS_CLIENT_ID', 'WORKOS_REDIRECT_URI', 'WORKOS_COOKIE_PASSWORD', 'WORKOS_BASE_URL', 'AUTHKIT_EMULATE_ENABLED', 'AUTHKIT_EMULATE_BASE_URL'] as $key) {
        expect(substr_count($contents, $key.'='))->toBe(1);
    }
});

it('seeds the emulate keys off so a fresh install talks to real WorkOS', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    $contents = (string) file_get_contents($this->envPath);

    expect($contents)->toContain('AUTHKIT_EMULATE_ENABLED=false');
    expect($contents)->toContain('AUTHKIT_EMULATE_BASE_URL=http://localhost:4100');
});

it('generates a 32 byte cookie password for .env but never for .env.example', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    $env = (string) file_get_contents($this->envPath);
    $example = (string) file_get_contents($this->envExamplePath);

    expect($example)->toContain("WORKOS_COOKIE_PASSWORD=\n");

    preg_match('/WORKOS_COOKIE_PASSWORD=(.+)/', $env, $matches);

    expect($matches)->toHaveCount(2);
    expect(strlen((string) base64_decode(trim($matches[1]), true)))->toBe(32);
});

it('leaves .env byte identical when run a second time', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();
    $afterFirstRun = file_get_contents($this->envPath);

    $this->artisan('authkit:install')->assertSuccessful();

    expect(file_get_contents($this->envPath))->toBe($afterFirstRun);
});

it('seeds the redirect uri with the default callback path', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    // Asserted as a literal because envKeys() hardcodes it; it is not derived
    // from authkit.routes.*. Phase 2 owns keeping the two in step when it wires
    // the real callback route.
    expect(file_get_contents($this->envPath))
        ->toContain('WORKOS_REDIRECT_URI=${APP_URL}/authkit/callback');

    expect(config('authkit.routes.prefix').'/'.config('authkit.routes.paths.callback'))
        ->toBe('authkit/callback');
});

it('warns and lists the keys when no .env file exists', function (): void {
    unlink($this->envPath);

    $this->artisan('authkit:install')
        ->expectsOutputToContain('No .env file was found.')
        ->expectsOutputToContain('WORKOS_COOKIE_PASSWORD')
        ->assertSuccessful();
});

it('does not warn about a missing .env on an idempotent re-run', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    $this->artisan('authkit:install')
        ->doesntExpectOutputToContain('No .env file was found.')
        ->assertSuccessful();
});

it('migrates the auto-loaded package migrations without publishing', function (): void {
    $this->loadLaravelMigrations();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasColumn('users', 'workos_id'))->toBeTrue();
});

it('publishes migrations verbatim so the migrator dedupes them by name', function (): void {
    $this->artisan('authkit:install')->assertSuccessful();

    // Every published copy must keep its package filename: re-timestamped
    // copies (publishesMigrations + update_date_on_publish) register as NEW
    // migrations next to the auto-loaded package path, so `migrate` runs the
    // same DDL twice and dies on a duplicate column.
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $packageMigration) {
        expect(is_file(database_path('migrations/'.basename($packageMigration))))->toBeTrue();
    }
});

it('migrates cleanly after authkit:install publishes the migrations', function (): void {
    // The pre-fix failure mode: publishing re-timestamped the copies, so the
    // migrator saw the package path AND the published copies as distinct
    // pending migrations, ran identical DDL twice, and died on a duplicate
    // column. Verbatim names make the name-keyed dedupe collapse them.
    $this->loadLaravelMigrations();

    $this->artisan('authkit:install')->assertSuccessful();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasColumn('users', 'workos_id'))->toBeTrue();
});
