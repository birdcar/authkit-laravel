<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\FGA\FGAService;

beforeEach(function () {
    config(['workos.fga.enabled' => true]);

    // Re-boot to pick up FGA middleware registration
    $provider = new \WorkOS\AuthKit\WorkOSServiceProvider(app());
    $provider->boot();

    Route::get('/fga-test/{projectId}', fn () => response()->json(['ok' => true]))
        ->middleware('workos.fga:view,project,:projectId');

    Route::get('/fga-literal', fn () => response()->json(['ok' => true]))
        ->middleware('workos.fga:view,project,proj_static');
});

it('allows access when FGA check passes', function () {
    $mock = Mockery::mock(FGAService::class);
    $mock->shouldReceive('checkForCurrentUser')
        ->with('view', 'project', 'proj_123')
        ->andReturn(true);
    $this->app->instance(FGAService::class, $mock);

    $this->get('/fga-test/proj_123')->assertOk();
});

it('blocks access when FGA check fails', function () {
    $mock = Mockery::mock(FGAService::class);
    $mock->shouldReceive('checkForCurrentUser')
        ->with('view', 'project', 'proj_123')
        ->andReturn(false);
    $this->app->instance(FGAService::class, $mock);

    $this->get('/fga-test/proj_123')->assertStatus(403);
});

it('resolves route parameters with colon prefix', function () {
    $mock = Mockery::mock(FGAService::class);
    $mock->shouldReceive('checkForCurrentUser')
        ->with('view', 'project', 'proj_dynamic')
        ->andReturn(true);
    $this->app->instance(FGAService::class, $mock);

    $this->get('/fga-test/proj_dynamic')->assertOk();
});

it('uses literal resource ID without colon prefix', function () {
    $mock = Mockery::mock(FGAService::class);
    $mock->shouldReceive('checkForCurrentUser')
        ->with('view', 'project', 'proj_static')
        ->andReturn(true);
    $this->app->instance(FGAService::class, $mock);

    $this->get('/fga-literal')->assertOk();
});
