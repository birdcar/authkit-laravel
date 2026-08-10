<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Organizations\MembershipProjectionResolver;
use Workbench\Database\Factories\UserFactory;

beforeEach(function (): void {
    $this->migratePackageDatabase();

    $this->resolver = new MembershipProjectionResolver;
});

it('resolves the membership id for an active (user, organization) pair', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_alice']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_active',
        'organization_id' => 'org_acme',
        'user_id' => 'user_alice',
        'role' => 'admin',
        'status' => 'active',
    ]);

    expect($this->resolver->resolve($user, 'org_acme'))->toBe('om_active');
});

it('ignores inactive and pending memberships for the same pair', function (string $status): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_alice']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_dormant',
        'organization_id' => 'org_acme',
        'user_id' => 'user_alice',
        'role' => 'admin',
        'status' => $status,
    ]);

    expect($this->resolver->resolve($user, 'org_acme'))->toBeNull();
})->with(['inactive', 'pending']);

it('returns null when no membership row exists at all', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_alice']);

    expect($this->resolver->resolve($user, 'org_acme'))->toBeNull();
});

it('returns null for a user who has never linked to WorkOS', function (): void {
    $user = UserFactory::new()->create(); // workos_id null

    expect($this->resolver->resolve($user, 'org_acme'))->toBeNull();
});

it('is the container’s default ResolvesOrganizationMembershipId binding', function (): void {
    expect(app(ResolvesOrganizationMembershipId::class))->toBeInstanceOf(MembershipProjectionResolver::class);
});

it('honors a config override naming a different resolver class', function (): void {
    config()->set('authkit.authorization.membership_resolver', MembershipProjectionResolver::class);

    expect(app(ResolvesOrganizationMembershipId::class))->toBeInstanceOf(MembershipProjectionResolver::class);
});
