<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;

beforeEach(function () {
    Route::get('/feature-test', fn () => response()->json(['ok' => true]))
        ->middleware('workos.feature:new-dashboard');

    Route::get('/multi-feature-test', fn () => response()->json(['ok' => true]))
        ->middleware('workos.feature:new-dashboard,beta-feature');
});

it('allows access when feature flag is enabled', function () {
    $mock = Mockery::mock(FeatureFlagService::class);
    $mock->shouldReceive('isEnabled')->with('new-dashboard')->andReturn(true);
    $this->app->instance(FeatureFlagService::class, $mock);

    $this->get('/feature-test')->assertOk();
});

it('blocks access when feature flag is disabled', function () {
    $mock = Mockery::mock(FeatureFlagService::class);
    $mock->shouldReceive('isEnabled')->with('new-dashboard')->andReturn(false);
    $this->app->instance(FeatureFlagService::class, $mock);

    $this->get('/feature-test')->assertStatus(403);
});

it('requires all flags when multiple are specified', function () {
    $mock = Mockery::mock(FeatureFlagService::class);
    $mock->shouldReceive('isEnabled')->with('new-dashboard')->andReturn(true);
    $mock->shouldReceive('isEnabled')->with('beta-feature')->andReturn(false);
    $this->app->instance(FeatureFlagService::class, $mock);

    $this->get('/multi-feature-test')->assertStatus(403);
});

it('allows access when all flags are enabled', function () {
    $mock = Mockery::mock(FeatureFlagService::class);
    $mock->shouldReceive('isEnabled')->with('new-dashboard')->andReturn(true);
    $mock->shouldReceive('isEnabled')->with('beta-feature')->andReturn(true);
    $this->app->instance(FeatureFlagService::class, $mock);

    $this->get('/multi-feature-test')->assertOk();
});
