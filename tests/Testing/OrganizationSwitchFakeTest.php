<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Models\WorkosMembership;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.organization.model', Organization::class);

    $this->organization = Organization::query()->createQuietly(['name' => 'Target', 'workos_id' => 'org_target']);

    $this->user = User::query()->create(['name' => 'Switcher', 'email' => 'switcher@example.com']);
    $this->user->forceFill(['workos_id' => 'user_abc'])->saveQuietly();
    $this->user->refresh();
});

it('switches the fake session into the target org when a projected membership exists', function (): void {
    $fake = Authkit::fake(['organization-switch']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_1',
        'organization_id' => 'org_target',
        'user_id' => 'user_abc',
        'role' => 'admin',
        'status' => 'active',
    ]);

    Authkit::actingAs($this->user, ['organization' => 'org_before', 'role' => 'member']);

    expect(Authkit::switchToOrganization($this->organization))->toBeTrue();

    $fake->organizationSwitch()->assertSwitched('org_target');

    // The fake collapsed the redirect: claims already show the target org and
    // the role the projection carries for it.
    expect(Authkit::currentOrganization()?->getAttribute('workos_id'))->toBe('org_target')
        ->and(auth()->user()?->can('admin'))->toBeTrue();
});

it('refuses when the user holds no active membership in the target org', function (): void {
    Authkit::fake(['organization-switch']);

    Authkit::actingAs($this->user, ['organization' => 'org_before']);

    expect(Authkit::switchToOrganization('org_target'))->toBeFalse();
});

it('treats an inactive membership as no membership', function (): void {
    Authkit::fake(['organization-switch']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_1',
        'organization_id' => 'org_target',
        'user_id' => 'user_abc',
        'role' => 'admin',
        'status' => 'inactive',
    ]);

    Authkit::actingAs($this->user);

    expect(Authkit::switchToOrganization('org_target'))->toBeFalse();
});

it('reports no session when no fake session is installed', function (): void {
    $fake = Authkit::fake(['organization-switch']);

    expect(Authkit::switchToOrganization('org_target'))->toBeFalse();

    // Still recorded: the attempt happened, whatever its outcome.
    $fake->organizationSwitch()->assertSwitched('org_target');
});

it('refuse() scripts the rejected-refresh path regardless of memberships', function (): void {
    $fake = Authkit::fake(['organization-switch']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_1',
        'organization_id' => 'org_target',
        'user_id' => 'user_abc',
        'role' => 'admin',
        'status' => 'active',
    ]);

    Authkit::actingAs($this->user);

    $fake->organizationSwitch()->refuse();

    expect(Authkit::switchToOrganization('org_target'))->toBeFalse();

    $fake->organizationSwitch()->allow();

    expect(Authkit::switchToOrganization('org_target'))->toBeTrue();
});

it('asserts nothing switched', function (): void {
    $fake = Authkit::fake(['organization-switch']);

    $fake->organizationSwitch()->assertNothingSwitched();

    Authkit::actingAs($this->user);
    Authkit::switchToOrganization('org_target');

    $fake->organizationSwitch()->assertNothingSwitched();
})->throws(AssertionFailedError::class);
