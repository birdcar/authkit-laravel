<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\WebhookVerification;

const ELR_WEBHOOK_SECRET = 'whsec_test_secret';

function signElrPayload(string $payload): string
{
    $timestamp = (string) (time() * 1000);
    $hash = WebhookVerification::computeSignature($timestamp, $payload, ELR_WEBHOOK_SECRET);

    return "t={$timestamp}, v1={$hash}";
}

beforeEach(function () {
    config(['workos.webhook_secret' => ELR_WEBHOOK_SECRET]);
    Event::fake();
});

it('dispatches typed event when routed to webhooks', function () {
    config(['workos.events.routing.categories.user' => 'webhooks']);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $payload = json_encode($webhookData);

    $this->call('POST', '/webhooks/workos', [], [], [], [
        'HTTP_WorkOS-Signature' => signElrPayload($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    Event::assertDispatched(WorkOSEventReceived::class);
    Event::assertDispatched(WorkOSUserCreated::class);
});

it('does not dispatch typed event when routed to events_api', function () {
    config(['workos.events.routing.categories.user' => 'events_api']);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $payload = json_encode($webhookData);

    $this->call('POST', '/webhooks/workos', [], [], [], [
        'HTTP_WorkOS-Signature' => signElrPayload($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    Event::assertDispatched(WorkOSEventReceived::class);
    Event::assertNotDispatched(WorkOSUserCreated::class);
});

it('dispatches typed event when routed to both', function () {
    config(['workos.events.routing.categories.user' => 'both']);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $payload = json_encode($webhookData);

    $this->call('POST', '/webhooks/workos', [], [], [], [
        'HTTP_WorkOS-Signature' => signElrPayload($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    Event::assertDispatched(WorkOSEventReceived::class);
    Event::assertDispatched(WorkOSUserCreated::class);
});

it('respects per-event-type overrides', function () {
    config([
        'workos.events.routing.categories.user' => 'webhooks',
        'workos.events.routing.overrides' => ['user.created' => 'events_api'],
    ]);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $payload = json_encode($webhookData);

    $this->call('POST', '/webhooks/workos', [], [], [], [
        'HTTP_WorkOS-Signature' => signElrPayload($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    Event::assertDispatched(WorkOSEventReceived::class);
    Event::assertNotDispatched(WorkOSUserCreated::class);
});
