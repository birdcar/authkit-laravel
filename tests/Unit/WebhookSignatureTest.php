<?php

declare(strict_types=1);

use WorkOS\AuthKit\Http\Controllers\WebhookController;

beforeEach(function () {
    config(['workos.webhook_secret' => 'whsec_test_secret']);
});

it('maps user created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.created'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated::class);
});

it('maps user updated event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.updated'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated::class);
});

it('maps user deleted event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.deleted'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSUserDeleted::class);
});

it('maps organization created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization.created'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationCreated::class);
});

it('maps organization updated event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization.updated'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationUpdated::class);
});

it('maps organization deleted event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization.deleted'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationDeleted::class);
});

it('maps membership created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization_membership.created'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipCreated::class);
});

it('maps membership updated event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization_membership.updated'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipUpdated::class);
});

it('maps membership deleted event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization_membership.deleted'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipDeleted::class);
});

it('maps session created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['session.created'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps authentication email verification succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.email_verification_succeeded'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps authentication magic auth succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.magic_auth_succeeded'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps authentication mfa succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.mfa_succeeded'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps authentication oauth succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.oauth_succeeded'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps authentication password succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.password_succeeded'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps authentication sso succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.sso_succeeded'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated::class);
});

it('maps session revoked event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.session_revoked'])
        ->toBe(\WorkOS\AuthKit\Events\Webhooks\WorkOSSessionRevoked::class);
});

it('has all expected event mappings', function () {
    expect(WebhookController::EVENT_MAP)->toHaveCount(17);
});

it('returns null for unknown event types', function () {
    expect(WebhookController::EVENT_MAP['unknown.event'] ?? null)->toBeNull();
});
