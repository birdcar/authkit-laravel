<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use Authkit\Authkit\Support\WorkosClientManager;
use Illuminate\Config\Repository;
use WorkOS\WorkOS;

/**
 * These tests deliberately defer invoking `php artisan config:cache` in-process.
 *
 * ConfigCacheCommand::getFreshConfiguration() builds a second application from
 * bootstrap/app.php, which calls Container::setInstance() and replaces the global
 * container. Under Testbench that replacement app is the bare skeleton with no
 * package provider, so after the command `app()` and `config()` resolve against
 * an application this package was never registered with — and, because Pest
 * randomises execution order, the swapped container leaks into sibling tests.
 *
 * That is recoverable by snapshotting and restoring the container, but the
 * substitute below covers the property `config:cache` actually requires of a
 * package — that every config value survives var_export() — without the hazard.
 * An end-to-end `config:cache` smoke check against a real app belongs with the
 * Phase 13 CI recipe; see this phase's handoff notes.
 */
function authkitConfigSchema(): array
{
    return require dirname(__DIR__, 2).'/config/authkit.php';
}

it('ships a config schema containing only var_export-safe values', function (): void {
    $offenders = [];

    $walk = function (array $values, string $path) use (&$walk, &$offenders): void {
        foreach ($values as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($value)) {
                $walk($value, $keyPath);

                continue;
            }

            if ($value !== null && ! is_scalar($value)) {
                $offenders[] = $keyPath;
            }
        }
    };

    $walk(authkitConfigSchema(), '');

    expect($offenders)->toBe([]);
});

it('builds a working client from config that has been through a var_export round trip', function (): void {
    $schema = authkitConfigSchema();
    $schema['api_key'] = 'sk_cached';
    $schema['client_id'] = 'client_cached';

    $path = tempnam(sys_get_temp_dir(), 'authkit-cached-config-');
    file_put_contents($path, '<?php return '.var_export(['authkit' => $schema], true).';'.PHP_EOL);

    $restored = require $path;
    unlink($path);

    expect($restored['authkit']['base_url'])->toBe('https://api.workos.com');

    $manager = WorkosClientManager::fromConfig(new Repository($restored));

    expect($manager->client())->toBeInstanceOf(WorkOS::class);
});

it('resolves the client manager from the container on a normal boot', function (): void {
    expect(config('authkit.base_url'))->toBe('https://api.workos.com');
    expect(app(WorkosClientManagerContract::class)->client())->toBeInstanceOf(WorkOS::class);
});

it('never reads env() from the package source', function (): void {
    $sourceDirectory = dirname(__DIR__, 2).'/src';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
    );

    $offenders = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            continue;
        }

        // Matches a call to the global env() helper while ignoring getenv() and
        // any ->env() method call, neither of which reads Laravel's env layer.
        // Env::get()/Env::getOrFail() violate the same config-only doctrine.
        $readsEnv = preg_match('/(?<![\w>])env\s*\(/', $contents) === 1
            || preg_match('/(?<![\w])Env::/', $contents) === 1;

        if ($readsEnv) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
