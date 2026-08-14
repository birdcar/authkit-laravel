<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Models\WorkosMembership;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\Organization;
use WorkOS\Resource\OrganizationMembership;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

it('mints a membership on create and upserts the projection like the real manager', function (): void {
    $fake = Authkit::fake(['memberships']);

    $membership = Authkit::memberships()->create('org_acme', 'user_abc', role: 'admin');

    expect($membership->organizationId)->toBe('org_acme')
        ->and($membership->userId)->toBe('user_abc')
        ->and($membership->role->slug)->toBe('admin')
        ->and($membership->status->value)->toBe('active');

    expect(WorkosMembership::query()->where('workos_id', $membership->id)->first())
        ->not->toBeNull()
        ->organization_id->toBe('org_acme')
        ->user_id->toBe('user_abc')
        ->role->toBe('admin')
        ->status->toBe('active');

    $fake->memberships()->assertCreated('org_acme', 'user_abc');
    $fake->memberships()->assertCreated('org_acme', 'user_abc', fn (OrganizationMembership $created): bool => $created->role->slug === 'admin');
});

it('applies the environment default role when none is given', function (): void {
    Authkit::fake(['memberships']);

    $membership = Authkit::memberships()->create('org_acme', 'user_abc');

    expect($membership->role->slug)->toBe('member');
});

it('reads memberships back through the projection relations', function (): void {
    Authkit::fake(['memberships']);

    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acme']);

    Authkit::memberships()->create($organization, 'user_abc', role: 'admin');

    expect($organization->memberships()->count())->toBe(1)
        ->and($organization->memberships()->first()?->getAttribute('role'))->toBe('admin');
});

it('updates a role and converges the projection', function (): void {
    $fake = Authkit::fake(['memberships']);

    $membership = $fake->memberships()->seed('org_acme', 'user_abc', role: 'member');

    $updated = Authkit::memberships()->update($membership->id, role: 'admin');

    expect($updated->role->slug)->toBe('admin')
        ->and(WorkosMembership::query()->where('workos_id', $membership->id)->value('role'))->toBe('admin');

    $fake->memberships()->assertUpdated($membership->id, 'admin');
});

it('deletes a membership and its projection row', function (): void {
    $fake = Authkit::fake(['memberships']);

    $membership = $fake->memberships()->seed('org_acme', 'user_abc');

    Authkit::memberships()->delete($membership->id);

    expect(WorkosMembership::query()->where('workos_id', $membership->id)->exists())->toBeFalse();

    $fake->memberships()->assertDeleted($membership->id);
});

it('throws on deleting a membership that does not exist, like the real API', function (): void {
    Authkit::fake(['memberships']);

    Authkit::memberships()->delete('om_missing');
})->throws(InvalidArgumentException::class);

it('seeding is fixture state, not a recorded create', function (): void {
    $fake = Authkit::fake(['memberships']);

    $fake->memberships()->seed('org_acme', 'user_abc');

    $fake->memberships()->assertNothingCreated();
});

it('lists only active memberships by default and honours filters', function (): void {
    $fake = Authkit::fake(['memberships']);

    $active = $fake->memberships()->seed('org_acme', 'user_active');
    $inactive = $fake->memberships()->seed('org_acme', 'user_inactive', attributes: ['status' => 'inactive']);
    $fake->memberships()->seed('org_other', 'user_active');

    $defaults = Authkit::memberships()->list(organization: 'org_acme');

    expect(array_map(static fn ($each) => $each->id, $defaults->data))->toBe([$active->id]);

    $all = Authkit::memberships()->list(organization: 'org_acme', statuses: ['active', 'inactive']);

    expect($all->data)->toHaveCount(2);

    $byUser = Authkit::memberships()->list(user: 'user_active');

    expect($byUser->data)->toHaveCount(2);
});

it('deactivate and reactivate transition status through the projection', function (): void {
    $fake = Authkit::fake(['memberships']);

    $membership = $fake->memberships()->seed('org_acme', 'user_abc');

    Authkit::memberships()->deactivate($membership->id);

    expect(WorkosMembership::query()->where('workos_id', $membership->id)->value('status'))->toBe('inactive');

    Authkit::memberships()->reactivate($membership->id);

    expect(WorkosMembership::query()->where('workos_id', $membership->id)->value('status'))->toBe('active');
});

it('fails assertCreated loudly when nothing matched', function (): void {
    $fake = Authkit::fake(['memberships']);

    $fake->memberships()->assertCreated('org_acme', 'user_abc');
})->throws(AssertionFailedError::class);
