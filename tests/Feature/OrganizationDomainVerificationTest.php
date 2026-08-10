<?php

declare(strict_types=1);

use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Models\WorkosOrganizationDomain;
use Illuminate\Support\Facades\Log;

// Test path: none (pure event/listener) — the typed Laravel events are
// dispatched directly, exactly as either transport (authkit:work poller or
// verified webhook) would, and the domains projection is asserted in the DB.
// No WorkOS wire call is involved.

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function seedPendingDomainProjection(): WorkosOrganizationDomain
{
    return WorkosOrganizationDomain::query()->create([
        'workos_id' => 'org_domain_01TEST',
        'organization_id' => 'org_01TEST',
        'domain' => 'acme.com',
        'state' => 'pending',
        'verification_prefix' => 'prefix-1',
        'verification_token' => 'token-1',
    ]);
}

describe('OrganizationDomainVerification', function (): void {
    it('marks the projection verified and clears the spent token fields', function (): void {
        seedPendingDomainProjection();

        event(new OrganizationDomainVerified(
            id: 'event_01VERIFIED',
            payload: ['id' => 'org_domain_01TEST', 'organization_id' => 'org_01TEST', 'domain' => 'acme.com', 'state' => 'verified'],
            occurredAt: new DateTimeImmutable,
        ));

        $domain = WorkosOrganizationDomain::query()->firstWhere('workos_id', 'org_domain_01TEST');

        expect($domain?->state)->toBe('verified')
            ->and($domain?->verification_prefix)->toBeNull()
            ->and($domain?->verification_token)->toBeNull()
            ->and($domain?->domain)->toBe('acme.com')
            ->and($domain?->organization_id)->toBe('org_01TEST');
    });

    it('marks the projection failed on verification_failed regardless of reason code', function (string $reason): void {
        seedPendingDomainProjection();

        // Real verification_failed payloads carry `reason` and never a
        // top-level state — the listener stamps state=failed itself.
        event(new OrganizationDomainVerificationFailed(
            id: 'event_01FAILED',
            payload: ['id' => 'org_domain_01TEST', 'reason' => $reason],
            occurredAt: new DateTimeImmutable,
        ));

        $domain = WorkosOrganizationDomain::query()->firstWhere('workos_id', 'org_domain_01TEST');

        expect($domain?->state)->toBe('failed')
            ->and($domain?->domain)->toBe('acme.com')
            ->and($domain?->verification_token)->toBe('token-1');
    })->with([
        'verification period expired' => ['domain_verification_period_expired'],
        'verified by another organization' => ['domain_verified_by_other_organization'],
    ]);

    it('warns and creates no row for an event about a domain never projected', function (): void {
        Log::spy();

        event(new OrganizationDomainVerified(
            id: 'event_01UNKNOWN',
            payload: ['id' => 'org_domain_01NEVER', 'state' => 'verified'],
            occurredAt: new DateTimeImmutable,
        ));

        expect(WorkosOrganizationDomain::query()->count())->toBe(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'authkit: domain-verification event for unknown domain projection row'
                && $context['workos_id'] === 'org_domain_01NEVER'
                && $context['state'] === 'verified')
            ->once();
    });

    it('treats a row deleted concurrently with the event as a no-op, not an error', function (): void {
        Log::spy();

        $domain = seedPendingDomainProjection();
        $domain->delete();

        event(new OrganizationDomainVerificationFailed(
            id: 'event_01RACE',
            payload: ['id' => 'org_domain_01TEST', 'reason' => 'domain_verification_period_expired'],
            occurredAt: new DateTimeImmutable,
        ));

        expect(WorkosOrganizationDomain::query()->count())->toBe(0);

        Log::shouldHaveReceived('warning')->once();
    });
});
