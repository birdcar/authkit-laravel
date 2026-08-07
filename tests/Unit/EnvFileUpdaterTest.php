<?php

declare(strict_types=1);

use Authkit\Authkit\Support\EnvFileUpdater;

beforeEach(function (): void {
    $this->envPath = tempnam(sys_get_temp_dir(), 'authkit-env-');
});

afterEach(function (): void {
    if (is_string($this->envPath) && is_file($this->envPath)) {
        unlink($this->envPath);
    }
});

it('appends every missing key', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Laravel\n");

    $appended = (new EnvFileUpdater)->ensureKeys($this->envPath, [
        'WORKOS_API_KEY' => '',
        'WORKOS_CLIENT_ID' => '',
    ]);

    expect($appended)->toBe(['WORKOS_API_KEY', 'WORKOS_CLIENT_ID']);

    $contents = file_get_contents($this->envPath);

    expect($contents)
        ->toContain('APP_NAME=Laravel')
        ->toContain('WORKOS_API_KEY=')
        ->toContain('WORKOS_CLIENT_ID=');
});

it('is a byte-identical no-op on a second run', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Laravel\n");

    $updater = new EnvFileUpdater;
    $keys = ['WORKOS_API_KEY' => '', 'WORKOS_CLIENT_ID' => ''];

    $updater->ensureKeys($this->envPath, $keys);
    $afterFirstRun = file_get_contents($this->envPath);

    $appended = $updater->ensureKeys($this->envPath, $keys);

    expect($appended)->toBe([]);
    expect(file_get_contents($this->envPath))->toBe($afterFirstRun);
});

it('leaves an existing key with a custom value untouched', function (): void {
    file_put_contents($this->envPath, "WORKOS_API_KEY=sk_live_custom\n");

    $appended = (new EnvFileUpdater)->ensureKeys($this->envPath, [
        'WORKOS_API_KEY' => '',
        'WORKOS_CLIENT_ID' => '',
    ]);

    expect($appended)->toBe(['WORKOS_CLIENT_ID']);

    $contents = file_get_contents($this->envPath);

    expect($contents)
        ->toContain('WORKOS_API_KEY=sk_live_custom')
        ->not->toContain("WORKOS_API_KEY=\n");
});

it('returns an empty array when the file cannot be written', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Laravel\n");
    chmod($this->envPath, 0444);

    expect((new EnvFileUpdater)->ensureKeys($this->envPath, ['WORKOS_API_KEY' => '']))->toBe([]);
    expect(file_get_contents($this->envPath))->toBe("APP_NAME=Laravel\n");
})->skip(
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root ignores the read-only bit',
);

it('returns an empty array for a path that is not a file', function (): void {
    $missing = $this->envPath.'-does-not-exist';

    expect((new EnvFileUpdater)->ensureKeys($missing, ['WORKOS_API_KEY' => '']))->toBe([]);
    expect(is_file($missing))->toBeFalse();
});

it('does not treat a longer key as a match for a shorter one', function (): void {
    file_put_contents($this->envPath, "WORKOS_API_KEY_2=other\n");

    $appended = (new EnvFileUpdater)->ensureKeys($this->envPath, ['WORKOS_API_KEY' => 'appended']);

    expect($appended)->toBe(['WORKOS_API_KEY']);
    expect(file_get_contents($this->envPath))
        ->toContain('WORKOS_API_KEY_2=other')
        ->toContain('WORKOS_API_KEY=appended');
});

it('normalises trailing whitespace so repeated runs stay stable', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Laravel\n\n\n\n");

    $updater = new EnvFileUpdater;

    $updater->ensureKeys($this->envPath, ['WORKOS_API_KEY' => '']);

    expect(file_get_contents($this->envPath))->toBe("APP_NAME=Laravel\n\nWORKOS_API_KEY=\n");
});
