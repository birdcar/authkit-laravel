<?php

declare(strict_types=1);

use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Models\WorkosOrganizationDomain;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Organization;
use Workbench\Database\Factories\UserFactory;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

it('migrates both projection tables with the declared column shapes', function (): void {
    expect(Schema::hasColumns('workos_organization_domains', [
        'id', 'workos_id', 'organization_id', 'domain', 'state', 'verification_prefix', 'verification_token', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('workos_memberships', [
            'id', 'workos_id', 'organization_id', 'user_id', 'role', 'status', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('resolves domains and memberships from seeded rows with no SDK involvement', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acme']);

    WorkosOrganizationDomain::query()->create([
        'workos_id' => 'org_domain_1',
        'organization_id' => 'org_acme',
        'domain' => 'acme.com',
        'state' => 'verified',
    ]);
    WorkosOrganizationDomain::query()->create([
        'workos_id' => 'org_domain_2',
        'organization_id' => 'org_acme',
        'domain' => 'acme.dev',
        'state' => 'pending',
    ]);
    WorkosMembership::query()->create([
        'workos_id' => 'om_1',
        'organization_id' => 'org_acme',
        'user_id' => 'user_alice',
        'role' => 'admin',
        'status' => 'active',
    ]);

    expect($organization->domains()->count())->toBe(2)
        ->and($organization->domains()->pluck('domain')->all())->toContain('acme.com', 'acme.dev')
        ->and($organization->memberships()->count())->toBe(1)
        ->and($organization->memberships()->first()?->getAttribute('role'))->toBe('admin');
});

it('resolves a user’s organizations through the memberships projection', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acme']);
    $user = UserFactory::new()->create(['workos_id' => 'user_alice']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_1',
        'organization_id' => 'org_acme',
        'user_id' => 'user_alice',
        'role' => 'member',
        'status' => 'active',
    ]);

    $first = $user->organizations()->first();

    expect($first?->is($organization))->toBeTrue()
        ->and($first?->getAttribute('pivot')?->getAttribute('workos_id'))->toBe('om_1')
        ->and($first?->getAttribute('pivot')?->getAttribute('role'))->toBe('member')
        ->and($first?->getAttribute('pivot')?->getAttribute('status'))->toBe('active');
});

it('returns empty collections, not errors, for an org with no projected rows', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Lonely', 'workos_id' => 'org_lonely']);

    expect($organization->domains()->get())->toBeEmpty()
        ->and($organization->memberships()->get())->toBeEmpty();
});

it('returns no organizations for a user who has never linked to WorkOS', function (): void {
    $user = UserFactory::new()->create(); // workos_id null

    expect($user->organizations()->get())->toBeEmpty();
});
