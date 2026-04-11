<?php

declare(strict_types=1);

use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSSessionCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSSessionRevoked;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

beforeEach(function () {
    config(['workos.webhook_secret' => 'whsec_test_secret']);
});

it('maps user created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.created'])
        ->toBe(WorkOSUserCreated::class);
});

it('maps user updated event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.updated'])
        ->toBe(WorkOSUserUpdated::class);
});

it('maps user deleted event to correct class', function () {
    expect(WebhookController::EVENT_MAP['user.deleted'])
        ->toBe(WorkOSUserDeleted::class);
});

it('maps organization created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization.created'])
        ->toBe(WorkOSOrganizationCreated::class);
});

it('maps organization updated event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization.updated'])
        ->toBe(WorkOSOrganizationUpdated::class);
});

it('maps organization deleted event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization.deleted'])
        ->toBe(WorkOSOrganizationDeleted::class);
});

it('maps membership created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization_membership.created'])
        ->toBe(WorkOSMembershipCreated::class);
});

it('maps membership updated event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization_membership.updated'])
        ->toBe(WorkOSMembershipUpdated::class);
});

it('maps membership deleted event to correct class', function () {
    expect(WebhookController::EVENT_MAP['organization_membership.deleted'])
        ->toBe(WorkOSMembershipDeleted::class);
});

it('maps session created event to correct class', function () {
    expect(WebhookController::EVENT_MAP['session.created'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps authentication email verification succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.email_verification_succeeded'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps authentication magic auth succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.magic_auth_succeeded'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps authentication mfa succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.mfa_succeeded'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps authentication oauth succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.oauth_succeeded'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps authentication password succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.password_succeeded'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps authentication sso succeeded event to correct class', function () {
    expect(WebhookController::EVENT_MAP['authentication.sso_succeeded'])
        ->toBe(WorkOSSessionCreated::class);
});

it('maps session revoked event to correct class', function () {
    expect(WebhookController::EVENT_MAP['session.revoked'])
        ->toBe(WorkOSSessionRevoked::class);
});

it('has all expected event mappings', function () {
    expect(WebhookController::EVENT_MAP)->toHaveCount(18);
});

it('returns null for unknown event types', function () {
    expect(WebhookController::EVENT_MAP['unknown.event'] ?? null)->toBeNull();
});
