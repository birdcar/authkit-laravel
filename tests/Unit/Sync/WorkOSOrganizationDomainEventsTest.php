<?php

declare(strict_types=1);

use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerificationFailed;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerified;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

it('WorkOSOrganizationDomainCreated exposes typed accessors', function () {
    $event = new WorkOSOrganizationDomainCreated([
        'id' => 'dom_01',
        'organization_id' => 'org_01',
        'domain' => 'example.com',
        'state' => 'pending',
    ]);

    expect($event->domainId())->toBe('dom_01')
        ->and($event->organizationId())->toBe('org_01')
        ->and($event->domain())->toBe('example.com')
        ->and($event->state())->toBe('pending');
});

it('WorkOSOrganizationDomainVerified exposes typed accessors', function () {
    $event = new WorkOSOrganizationDomainVerified([
        'id' => 'dom_02',
        'organization_id' => 'org_01',
        'domain' => 'verified.com',
        'state' => 'verified',
    ]);

    expect($event->state())->toBe('verified');
});

it('WorkOSOrganizationDomainVerificationFailed includes reason', function () {
    $event = new WorkOSOrganizationDomainVerificationFailed([
        'id' => 'dom_03',
        'organization_id' => 'org_01',
        'domain' => 'failed.com',
        'state' => 'failed',
        'reason' => 'DNS TXT record not found',
    ]);

    expect($event->reason())->toBe('DNS TXT record not found')
        ->and($event->state())->toBe('failed');
});

it('WorkOSOrganizationDomainVerificationFailed returns null when reason absent', function () {
    $event = new WorkOSOrganizationDomainVerificationFailed([
        'id' => 'dom_04',
        'organization_id' => 'org_01',
        'domain' => 'failed2.com',
        'state' => 'failed',
    ]);

    expect($event->reason())->toBeNull();
});

it('EVENT_MAP contains all organization_domain event types', function () {
    expect(WebhookController::EVENT_MAP)
        ->toHaveKey('organization_domain.created')
        ->toHaveKey('organization_domain.updated')
        ->toHaveKey('organization_domain.deleted')
        ->toHaveKey('organization_domain.verified')
        ->toHaveKey('organization_domain.verification_failed');
});
