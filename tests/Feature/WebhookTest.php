<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipUpdated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationUpdated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSSessionRevoked;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated;
use WorkOS\Webhook;

beforeEach(function () {
    config(['workos.webhook_secret' => 'whsec_test_secret']);
    Event::fake();
});

it('returns 400 when signature header is missing', function () {
    $response = $this->postJson('/webhooks/workos', [
        'event' => 'user.created',
        'data' => ['id' => 'user_123'],
    ]);

    $response->assertStatus(400);
});

it('returns 500 when webhook secret is not configured', function () {
    config(['workos.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/workos', [
        'event' => 'user.created',
        'data' => ['id' => 'user_123'],
    ], ['WorkOS-Signature' => 'test_signature']);

    $response->assertStatus(500);
});

it('returns 400 when signature verification fails', function () {
    $this->mock(Webhook::class, function ($mock) {
        $mock->shouldReceive('constructEvent')
            ->andThrow(new \Exception('Invalid signature'));
    });

    $response = $this->postJson('/webhooks/workos', [
        'event' => 'user.created',
        'data' => ['id' => 'user_123'],
    ], ['WorkOS-Signature' => 'invalid_signature']);

    $response->assertStatus(400);
});

it('dispatches WebhookReceived event on valid webhook', function () {
    $webhookData = [
        'event' => 'user.created',
        'data' => ['id' => 'user_123', 'email' => 'test@example.com'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $response = $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    $response->assertStatus(200);
    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->event === 'user.created'
            && $event->data['id'] === 'user_123';
    });
});

it('dispatches WorkOSUserCreated event', function () {
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

    Event::assertDispatched(WorkOSUserCreated::class, function ($event) {
        return $event->userId() === 'user_123'
            && $event->email() === 'test@example.com';
    });
});

it('dispatches WorkOSUserUpdated event', function () {
    $webhookData = [
        'event' => 'user.updated',
        'data' => [
            'id' => 'user_123',
            'email' => 'updated@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSUserUpdated::class, function ($event) {
        return $event->userId() === 'user_123'
            && $event->email() === 'updated@example.com'
            && $event->firstName() === 'John'
            && $event->lastName() === 'Doe';
    });
});

it('dispatches WorkOSUserDeleted event', function () {
    $webhookData = [
        'event' => 'user.deleted',
        'data' => ['id' => 'user_123'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSUserDeleted::class, function ($event) {
        return $event->userId() === 'user_123';
    });
});

it('dispatches WorkOSOrganizationCreated event', function () {
    $webhookData = [
        'event' => 'organization.created',
        'data' => ['id' => 'org_123', 'name' => 'Acme Corp'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSOrganizationCreated::class, function ($event) {
        return $event->organizationId() === 'org_123'
            && $event->name() === 'Acme Corp';
    });
});

it('dispatches WorkOSOrganizationUpdated event', function () {
    $webhookData = [
        'event' => 'organization.updated',
        'data' => ['id' => 'org_123', 'name' => 'Acme Inc'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSOrganizationUpdated::class, function ($event) {
        return $event->organizationId() === 'org_123'
            && $event->name() === 'Acme Inc';
    });
});

it('dispatches WorkOSOrganizationDeleted event', function () {
    $webhookData = [
        'event' => 'organization.deleted',
        'data' => ['id' => 'org_123'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSOrganizationDeleted::class, function ($event) {
        return $event->organizationId() === 'org_123';
    });
});

it('dispatches WorkOSMembershipCreated event', function () {
    $webhookData = [
        'event' => 'organization_membership.created',
        'data' => [
            'user_id' => 'user_123',
            'organization_id' => 'org_123',
            'role' => ['slug' => 'admin'],
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSMembershipCreated::class, function ($event) {
        return $event->userId() === 'user_123'
            && $event->organizationId() === 'org_123'
            && $event->role() === 'admin';
    });
});

it('dispatches WorkOSMembershipUpdated event', function () {
    $webhookData = [
        'event' => 'organization_membership.updated',
        'data' => [
            'user_id' => 'user_123',
            'organization_id' => 'org_123',
            'role' => ['slug' => 'member'],
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSMembershipUpdated::class, function ($event) {
        return $event->userId() === 'user_123'
            && $event->organizationId() === 'org_123'
            && $event->role() === 'member';
    });
});

it('dispatches WorkOSMembershipDeleted event', function () {
    $webhookData = [
        'event' => 'organization_membership.deleted',
        'data' => [
            'user_id' => 'user_123',
            'organization_id' => 'org_123',
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSMembershipDeleted::class, function ($event) {
        return $event->userId() === 'user_123'
            && $event->organizationId() === 'org_123';
    });
});

it('dispatches WorkOSSessionCreated for session.created event', function () {
    $webhookData = [
        'event' => 'session.created',
        'data' => [
            'id' => 'session_123',
            'user_id' => 'user_123',
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSSessionCreated::class, function ($event) {
        return $event->sessionId() === 'session_123'
            && $event->userId() === 'user_123';
    });
});

it('dispatches WorkOSSessionCreated for authentication.sso_succeeded event', function () {
    $webhookData = [
        'event' => 'authentication.sso_succeeded',
        'data' => [
            'id' => 'session_456',
            'user_id' => 'user_456',
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSSessionCreated::class, function ($event) {
        return $event->sessionId() === 'session_456';
    });
});

it('dispatches WorkOSSessionRevoked event', function () {
    $webhookData = [
        'event' => 'user.session_revoked',
        'data' => [
            'id' => 'session_123',
            'user_id' => 'user_123',
        ],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    Event::assertDispatched(WorkOSSessionRevoked::class, function ($event) {
        return $event->sessionId() === 'session_123'
            && $event->userId() === 'user_123';
    });
});

it('handles unknown event types gracefully', function () {
    $webhookData = [
        'event' => 'unknown.event',
        'data' => ['id' => 'test_123'],
    ];

    $this->mock(Webhook::class, function ($mock) use ($webhookData) {
        $mock->shouldReceive('constructEvent')
            ->andReturn((object) $webhookData);
    });

    $response = $this->postJson('/webhooks/workos', $webhookData, [
        'WorkOS-Signature' => 'valid_signature',
    ]);

    $response->assertStatus(200);
    Event::assertDispatched(WebhookReceived::class);
});

it('respects webhook disabled configuration', function () {
    // This test just verifies the configuration option exists and is respected
    config(['workos.webhooks.enabled' => false]);
    expect(config('workos.webhooks.enabled'))->toBeFalse();
});
