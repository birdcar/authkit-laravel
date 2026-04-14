<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Services\RadarService;

beforeEach(function () {
    config([
        'workos.api_key' => 'sk_test_radar',
        'workos.widgets.base_url' => 'https://api.workos.com',
    ]);
});

it('creates a radar attempt', function () {
    Http::fake([
        'api.workos.com/radar/attempts' => Http::response([
            'verdict' => 'allow',
            'reason' => 'no_risk_detected',
            'attempt_id' => 'attempt_123',
        ]),
    ]);

    $service = new RadarService;
    $result = $service->createAttempt([
        'ip_address' => '1.2.3.4',
        'user_agent' => 'Mozilla/5.0',
        'email' => 'test@example.com',
        'auth_method' => 'Password',
        'action' => 'sign-in',
    ]);

    expect($result['verdict'])->toBe('allow')
        ->and($result['attempt_id'])->toBe('attempt_123');
});

it('updates a radar attempt', function () {
    Http::fake([
        'api.workos.com/radar/attempts/attempt_123' => Http::response([
            'verdict' => 'allow',
            'attempt_id' => 'attempt_123',
        ]),
    ]);

    $service = new RadarService;
    $result = $service->updateAttempt('attempt_123', ['verdict_override' => 'allow']);

    expect($result['attempt_id'])->toBe('attempt_123');
});

it('adds to radar list', function () {
    Http::fake([
        'api.workos.com/radar/lists' => Http::response(['id' => 'entry_1']),
    ]);

    $service = new RadarService;
    $result = $service->addToList(['type' => 'ip', 'value' => '1.2.3.4', 'list' => 'blocklist']);

    expect($result['id'])->toBe('entry_1');
});

it('removes from radar list', function () {
    Http::fake([
        'api.workos.com/radar/lists' => Http::response(null, 204),
    ]);

    $service = new RadarService;
    $service->removeFromList(['type' => 'ip', 'value' => '1.2.3.4', 'list' => 'blocklist']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'radar/lists')
            && $request->method() === 'DELETE';
    });
});

it('throws on API error', function () {
    Http::fake([
        'api.workos.com/radar/attempts' => Http::response(['error' => 'bad request'], 400),
    ]);

    $service = new RadarService;
    $service->createAttempt(['ip_address' => '1.2.3.4']);
})->throws(RuntimeException::class, 'WorkOS Radar API error');
