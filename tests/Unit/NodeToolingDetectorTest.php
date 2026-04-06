<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use WorkOS\AuthKit\Support\NodeToolingDetector;

uses()->group('serial');

beforeEach(function () {
    $this->detector = new NodeToolingDetector;
    $this->command = Mockery::mock(Command::class);
});

afterEach(function () {
    Mockery::close();
});

// detect() tests

it('returns bunx when bun is available', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 0),
    ]);

    expect($this->detector->detect())->toBe('bunx');
});

it('returns npx when bun unavailable but npx available', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 0),
    ]);

    expect($this->detector->detect())->toBe('npx');
});

it('returns pnpm dlx when only pnpm available', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 0),
    ]);

    expect($this->detector->detect())->toBe('pnpm dlx');
});

it('returns null when no Node runners available', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 1),
    ]);

    expect($this->detector->detect())->toBeNull();
});

it('prefers bun over npx and pnpm', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 0),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 0),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 0),
    ]);

    expect($this->detector->detect())->toBe('bunx');
});

it('prefers npx over pnpm when bun unavailable', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 0),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 0),
    ]);

    expect($this->detector->detect())->toBe('npx');
});

// runInstall() tests

it('runInstall returns false when no runner detected', function () {
    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 1),
    ]);

    $this->command->shouldNotReceive('line');

    $result = $this->detector->runInstall($this->command);

    expect($result)->toBeFalse();
});

it('runInstall calls workos install with correct arguments when using bunx', function () {
    $installArgs = ['bunx', 'workos@latest', 'install', '--integration', 'php-laravel', '--no-branch', '--no-commit'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 0),
        cmdStr($installArgs) => Process::result('', '', 0),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();

    $result = $this->detector->runInstall($this->command);

    expect($result)->toBeTrue();

    Process::assertRan(function ($process) {
        $cmd = cmdArray($process->command);

        return in_array('bunx', $cmd)
            && in_array('workos@latest', $cmd)
            && in_array('install', $cmd)
            && in_array('--integration', $cmd)
            && in_array('php-laravel', $cmd)
            && in_array('--no-branch', $cmd)
            && in_array('--no-commit', $cmd);
    });
});

it('runInstall calls workos install with correct arguments when using npx', function () {
    $installArgs = ['npx', 'workos@latest', 'install', '--integration', 'php-laravel', '--no-branch', '--no-commit'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 0),
        cmdStr($installArgs) => Process::result('', '', 0),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();

    $result = $this->detector->runInstall($this->command);

    expect($result)->toBeTrue();

    Process::assertRan(function ($process) {
        $cmd = cmdArray($process->command);

        return in_array('npx', $cmd)
            && in_array('--no-branch', $cmd)
            && in_array('--no-commit', $cmd);
    });
});

it('runInstall calls workos install with correct arguments when using pnpm dlx', function () {
    $installArgs = ['pnpm', 'dlx', 'workos@latest', 'install', '--integration', 'php-laravel', '--no-branch', '--no-commit'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 0),
        cmdStr($installArgs) => Process::result('', '', 0),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();

    $result = $this->detector->runInstall($this->command);

    expect($result)->toBeTrue();

    Process::assertRan(function ($process) {
        $cmd = cmdArray($process->command);

        return in_array('pnpm', $cmd)
            && in_array('dlx', $cmd)
            && in_array('--no-branch', $cmd)
            && in_array('--no-commit', $cmd);
    });
});

it('runInstall returns false when CLI process fails', function () {
    $installArgs = ['bunx', 'workos@latest', 'install', '--integration', 'php-laravel', '--no-branch', '--no-commit'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 0),
        cmdStr($installArgs) => Process::result('', 'error', 1),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();

    $result = $this->detector->runInstall($this->command);

    expect($result)->toBeFalse();
});

// runDoctor() tests

it('runDoctor calls workos doctor with --skip-ai flag', function () {
    $doctorArgs = ['bunx', 'workos@latest', 'doctor', '--skip-ai'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 0),
        cmdStr($doctorArgs) => Process::result('', '', 0),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();
    $this->command->shouldReceive('warn')->zeroOrMoreTimes();

    $this->detector->runDoctor($this->command);

    Process::assertRan(function ($process) {
        $cmd = cmdArray($process->command);

        return in_array('doctor', $cmd)
            && in_array('--skip-ai', $cmd);
    });
});

it('runDoctor does not throw on failure', function () {
    $doctorArgs = ['bunx', 'workos@latest', 'doctor', '--skip-ai'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 0),
        cmdStr($doctorArgs) => Process::result('', 'error', 1),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();
    $this->command->shouldReceive('warn')->once();

    // Should not throw
    $this->detector->runDoctor($this->command);

    expect(true)->toBeTrue();
});

it('runDoctor with pnpm uses split command', function () {
    $doctorArgs = ['pnpm', 'dlx', 'workos@latest', 'doctor', '--skip-ai'];

    Process::fake([
        cmdStr(['command', '-v', 'bun']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'npx']) => Process::result('', '', 1),
        cmdStr(['command', '-v', 'pnpm']) => Process::result('', '', 0),
        cmdStr($doctorArgs) => Process::result('', '', 0),
    ]);

    $this->command->shouldReceive('line')->zeroOrMoreTimes();
    $this->command->shouldReceive('warn')->zeroOrMoreTimes();

    $this->detector->runDoctor($this->command);

    Process::assertRan(function ($process) {
        $cmd = cmdArray($process->command);

        return in_array('pnpm', $cmd)
            && in_array('dlx', $cmd)
            && in_array('doctor', $cmd)
            && in_array('--skip-ai', $cmd);
    });
});
