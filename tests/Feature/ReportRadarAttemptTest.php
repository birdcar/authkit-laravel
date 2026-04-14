<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Services\RadarService;

beforeEach(function () {
    Route::post('/radar-test', function (\Illuminate\Http\Request $request) {
        return response()->json([
            'ok' => true,
            'verdict' => $request->input('_radar_verdict'),
        ]);
    })->middleware('workos.radar:sign-in');
});

it('passes through when radar is disabled', function () {
    config(['workos.features.radar' => false]);

    $this->postJson('/radar-test', ['email' => 'test@example.com'])
        ->assertOk()
        ->assertJson(['ok' => true, 'verdict' => null]);
});

it('returns 403 when verdict is block', function () {
    config(['workos.features.radar' => true]);

    $mock = Mockery::mock(RadarService::class);
    $mock->shouldReceive('createAttempt')->andReturn([
        'verdict' => 'block',
        'reason' => 'ip_blocklisted',
        'attempt_id' => 'attempt_1',
    ]);
    $this->app->instance(RadarService::class, $mock);

    $this->postJson('/radar-test', ['email' => 'test@example.com'])
        ->assertStatus(403)
        ->assertJson(['message' => 'Access denied.']);
});

it('injects verdict into request when allowed', function () {
    config(['workos.features.radar' => true]);

    $mock = Mockery::mock(RadarService::class);
    $mock->shouldReceive('createAttempt')->andReturn([
        'verdict' => 'allow',
        'reason' => 'no_risk',
        'attempt_id' => 'attempt_2',
    ]);
    $this->app->instance(RadarService::class, $mock);

    $this->postJson('/radar-test', ['email' => 'test@example.com'])
        ->assertOk()
        ->assertJsonPath('verdict.verdict', 'allow');
});

it('injects verdict into request when challenge', function () {
    config(['workos.features.radar' => true]);

    $mock = Mockery::mock(RadarService::class);
    $mock->shouldReceive('createAttempt')->andReturn([
        'verdict' => 'challenge',
        'reason' => 'suspicious_behavior',
        'attempt_id' => 'attempt_3',
    ]);
    $this->app->instance(RadarService::class, $mock);

    $this->postJson('/radar-test', ['email' => 'test@example.com'])
        ->assertOk()
        ->assertJsonPath('verdict.verdict', 'challenge');
});
