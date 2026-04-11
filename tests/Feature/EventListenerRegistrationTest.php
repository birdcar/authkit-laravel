<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;
use WorkOS\Webhook;

beforeEach(function () {
    config(['workos.webhook_secret' => 'whsec_test_secret']);
    Event::fake();
});

it('dispatches typed event when routed to webhooks', function () {
    config(['workos.events.routing.categories.user' => 'webhooks']);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(WorkOSUserCreated::class);
});

it('does not dispatch typed event when routed to events_api', function () {
    config(['workos.events.routing.categories.user' => 'events_api']);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WorkOSUserCreated::class);
});

it('dispatches typed event when routed to both', function () {
    config(['workos.events.routing.categories.user' => 'both']);

    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WebhookReceived::class);
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

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WorkOSUserCreated::class);
});
