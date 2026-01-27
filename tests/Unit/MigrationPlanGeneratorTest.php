<?php

declare(strict_types=1);

use WorkOS\AuthKit\Install\MigrationPlanGenerator;
use WorkOS\AuthKit\Install\Plans\BreezeMigrationPlan;
use WorkOS\AuthKit\Install\Plans\FortifyMigrationPlan;
use WorkOS\AuthKit\Install\Plans\JetstreamMigrationPlan;
use WorkOS\AuthKit\Tests\Helpers\DetectionResultFactory;

beforeEach(function () {
    $this->storagePath = sys_get_temp_dir().'/workos-test-'.uniqid();
    mkdir($this->storagePath, 0777, true);
    $this->generator = new MigrationPlanGenerator($this->storagePath);
});

afterEach(function () {
    // Clean up test directory
    if (is_dir($this->storagePath)) {
        array_map('unlink', glob($this->storagePath.'/*'));
        rmdir($this->storagePath);
    }
});

test('returns null when no existing auth detected', function () {
    $detection = DetectionResultFactory::freshInstall();

    $result = $this->generator->generate($detection, '/fake/path');

    expect($result)->toBeNull();
});

test('selects Breeze plan when Breeze detected', function () {
    $detection = DetectionResultFactory::withBreeze();

    $plan = $this->generator->selectPlan($detection);

    expect($plan)->toBeInstanceOf(BreezeMigrationPlan::class);
});

test('selects Jetstream plan when Jetstream detected', function () {
    $detection = DetectionResultFactory::withJetstream();

    $plan = $this->generator->selectPlan($detection);

    expect($plan)->toBeInstanceOf(JetstreamMigrationPlan::class);
});

test('selects Fortify plan when Fortify detected', function () {
    $detection = DetectionResultFactory::withFortify();

    $plan = $this->generator->selectPlan($detection);

    expect($plan)->toBeInstanceOf(FortifyMigrationPlan::class);
});

test('prioritizes Jetstream over Breeze when both detected', function () {
    $detection = DetectionResultFactory::fresh([
        'hasBreeze' => true,
        'hasJetstream' => true,
    ]);

    $plan = $this->generator->selectPlan($detection);

    expect($plan)->toBeInstanceOf(JetstreamMigrationPlan::class);
});

test('prioritizes Breeze over Fortify when both detected', function () {
    $detection = DetectionResultFactory::fresh([
        'hasBreeze' => true,
        'hasFortify' => true,
    ]);

    $plan = $this->generator->selectPlan($detection);

    expect($plan)->toBeInstanceOf(BreezeMigrationPlan::class);
});

test('generates migration plan file when existing auth detected', function () {
    $detection = DetectionResultFactory::withBreeze();

    $result = $this->generator->generate($detection, '/fake/path');

    expect($result)->toBe($this->storagePath.'/workos-migration-plan.md');
    expect(file_exists($result))->toBeTrue();
    expect(file_get_contents($result))->toContain('Laravel Breeze');
});

test('Breeze plan generates valid markdown', function () {
    $plan = new BreezeMigrationPlan;
    $markdown = $plan->generate('/fake/path');

    expect($markdown)
        ->toContain('# Migration Plan: Laravel Breeze to WorkOS AuthKit')
        ->toContain('## Pre-Migration Checklist')
        ->toContain('## Step 1: Files to Remove')
        ->toContain('## Step 2: Database Changes')
        ->toContain('## Step 3: Update User Model')
        ->toContain('## Rollback Plan');
});

test('Jetstream plan generates valid markdown', function () {
    $plan = new JetstreamMigrationPlan;
    $markdown = $plan->generate('/fake/path');

    expect($markdown)
        ->toContain('# Migration Plan: Laravel Jetstream to WorkOS AuthKit')
        ->toContain('Teams Feature')
        ->toContain('API Tokens Feature')
        ->toContain('## Step-by-Step Migration');
});

test('Fortify plan generates valid markdown', function () {
    $plan = new FortifyMigrationPlan;
    $markdown = $plan->generate('/fake/path');

    expect($markdown)
        ->toContain('# Migration Plan: Laravel Fortify to WorkOS AuthKit')
        ->toContain('Fortify is a headless authentication backend')
        ->toContain('## Migration Steps');
});

test('Breeze plan returns correct risk level', function () {
    $plan = new BreezeMigrationPlan;

    expect($plan->getRiskLevel())->toBe('Medium - Existing user authentication will change');
});

test('Jetstream plan returns correct risk level', function () {
    $plan = new JetstreamMigrationPlan;

    expect($plan->getRiskLevel())->toBe('High - Jetstream has many integrated features');
});

test('Fortify plan returns correct risk level', function () {
    $plan = new FortifyMigrationPlan;

    expect($plan->getRiskLevel())->toBe('Medium - Fortify is headless, less to remove');
});

test('Breeze plan returns correct summary', function () {
    $plan = new BreezeMigrationPlan;

    expect($plan->getSummary())->toBe('Laravel Breeze');
});

test('Jetstream plan returns correct summary', function () {
    $plan = new JetstreamMigrationPlan;

    expect($plan->getSummary())->toBe('Laravel Jetstream');
});

test('Fortify plan returns correct summary', function () {
    $plan = new FortifyMigrationPlan;

    expect($plan->getSummary())->toBe('Laravel Fortify');
});
