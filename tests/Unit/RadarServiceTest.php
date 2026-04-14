<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use WorkOS\AuthKit\Services\RadarService;
use WorkOS\Exception\BadRequestException;
use WorkOS\WorkOS;

function makeRadarClient(MockHandler $mock): WorkOS
{
    $stack = HandlerStack::create($mock);

    return new WorkOS(apiKey: 'sk_test_radar', handler: $stack);
}

it('creates a radar attempt', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'verdict' => 'allow',
            'reason' => 'no_risk_detected',
            'attempt_id' => 'attempt_123',
        ])),
    ]);

    $service = new RadarService(makeRadarClient($mock));
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
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'verdict' => 'allow',
            'attempt_id' => 'attempt_123',
        ])),
    ]);

    $service = new RadarService(makeRadarClient($mock));
    $result = $service->updateAttempt('attempt_123', ['attempt_status' => 'success']);

    expect($result['attempt_id'])->toBe('attempt_123');
});

it('adds to radar list', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Entry already present',
        ])),
    ]);

    $service = new RadarService(makeRadarClient($mock));
    $result = $service->addToList([
        'type' => 'ip_address',
        'action' => 'block',
        'entry' => '1.2.3.4',
    ]);

    expect($result['message'])->toBe('Entry already present');
});

it('removes from radar list', function () {
    $mock = new MockHandler([
        new Response(204),
    ]);

    $service = new RadarService(makeRadarClient($mock));
    $service->removeFromList([
        'type' => 'ip_address',
        'action' => 'block',
        'entry' => '1.2.3.4',
    ]);

    expect($mock->count())->toBe(0);
});

it('throws on API error', function () {
    $mock = new MockHandler([
        new Response(400, [], json_encode(['message' => 'Bad request'])),
    ]);

    $service = new RadarService(makeRadarClient($mock));
    $service->createAttempt([
        'ip_address' => '1.2.3.4',
        'user_agent' => 'Mozilla/5.0',
        'email' => 'test@example.com',
        'auth_method' => 'Password',
        'action' => 'sign-in',
    ]);
})->throws(BadRequestException::class);
