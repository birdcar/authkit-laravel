<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The contract's workbench success criterion, runnable in CI: the example app
 * calls package APIs only — zero direct references to the WorkOS SDK.
 *
 * Shells out to the real grep with the contract's exact pattern rather than
 * reimplementing the regex in PHP: any drift between "what this test checks"
 * and "what the release-gate criterion checks" would be a bug, and using the
 * identical command removes that entire class of drift. The cost is a POSIX
 * dependency — skipped on Windows, where the ubuntu CI cells carry the gate.
 *
 * The path is resolved from this file, not base_path(): package tests boot
 * the Testbench skeleton, whose base_path() is not this repository.
 */
test('WorkbenchZeroSdkReference: workbench never references the WorkOS SDK directly', function (): void {
    $workbenchPath = dirname(__DIR__, 2).'/workbench';

    expect(is_dir($workbenchPath))->toBeTrue("workbench directory not found at [$workbenchPath]");

    // Mirrors the contract check verbatim: grep -rE '(use |\)WorkOS\' workbench/
    $process = new Process([
        'grep', '-rE', '(use |\\\\)WorkOS\\\\', $workbenchPath,
    ]);
    $process->run();

    // grep's documented exit-code contract: 1 = zero matches (the ONLY
    // passing state), 0 = matches found, 2 = error (e.g. unreadable file) —
    // both non-1 outcomes must fail.
    expect($process->getExitCode())->toBe(
        1,
        "Found direct WorkOS SDK reference(s) in workbench/ (or grep failed):\n"
            .$process->getOutput().$process->getErrorOutput(),
    );
})->skip(PHP_OS_FAMILY === 'Windows', 'grep is POSIX-only; the ubuntu CI cells carry this gate');
