<?php

declare(strict_types=1);

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Groups\GroupManager;
use Authkit\Authkit\Testing\Fakes\GroupsFake;
use PHPUnit\Framework\AssertionFailedError;
use WorkOS\Resource\Group;
use WorkOS\Resource\GroupRoleAssignment;
use WorkOS\Resource\ReplaceGroupRoleAssignmentEntry;
use WorkOS\Resource\UserOrganizationMembershipBaseListData;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function groupsFake(): GroupsFake
{
    $fake = new GroupsFake;

    app()->instance(GroupManager::class, $fake);

    return $fake;
}

it('runs group CRUD in memory', function (): void {
    $fake = groupsFake();

    $group = Authkit::groups()->create('org_acme', 'Platform Squad', 'Owns the platform');

    expect($group)->toBeInstanceOf(Group::class)
        ->and($group->organizationId)->toBe('org_acme')
        ->and(Authkit::groups()->get('org_acme', $group->id)->name)->toBe('Platform Squad')
        ->and(Authkit::groups()->list('org_acme')->data)->toHaveCount(1)
        ->and(Authkit::groups()->list('org_other')->data)->toHaveCount(0);

    $renamed = Authkit::groups()->update('org_acme', $group->id, name: 'Core Squad');

    expect($renamed->name)->toBe('Core Squad')
        ->and($renamed->description)->toBe('Owns the platform');

    $fake->assertGroupCreated('Core Squad', 'org_acme');

    Authkit::groups()->delete('org_acme', $group->id);

    expect(Authkit::groups()->list('org_acme')->data)->toHaveCount(0);
});

it('tracks membership both directions', function (): void {
    $fake = groupsFake();

    $group = Authkit::groups()->create('org_acme', 'Platform Squad');

    Authkit::groups()->addMember('org_acme', $group->id, 'om_alice');
    Authkit::groups()->addMember('org_acme', $group->id, 'om_alice'); // idempotent
    Authkit::groups()->addMember('org_acme', $group->id, 'om_bob');

    $members = Authkit::groups()->members('org_acme', $group->id)->data;

    // Items must be readable as resource objects, exactly like production —
    // consumer code does $member->id / $member->userId off each item.
    expect($members)->toHaveCount(2)
        ->and($members[0])->toBeInstanceOf(UserOrganizationMembershipBaseListData::class)
        ->and($members[0]->id)->toBe('om_alice')
        ->and($members[0]->organizationId)->toBe('org_acme')
        ->and($members[0]->user->id)->toBe('user_of_om_alice')
        ->and(Authkit::groups()->forMembership('om_alice')->data)->toHaveCount(1);

    $fake->assertMemberAdded($group->id, 'om_alice');

    Authkit::groups()->removeMember('org_acme', $group->id, 'om_alice');

    expect(Authkit::groups()->members('org_acme', $group->id)->data)->toHaveCount(1)
        ->and(Authkit::groups()->forMembership('om_alice')->data)->toHaveCount(0);

    $fake->assertMemberRemoved($group->id, 'om_alice');
});

it('records role assignments through assign, replace, and remove', function (): void {
    $fake = groupsFake();

    $group = Authkit::groups()->create('org_acme', 'Platform Squad');

    $assignment = Authkit::groups()->assignRole($group->id, 'editor', resourceExternalId: '42', resourceTypeSlug: 'project');

    expect(Authkit::groups()->roleAssignments($group->id)->data)->toHaveCount(1)
        ->and(Authkit::groups()->roleAssignment($group->id, $assignment->id)->role->slug)->toBe('editor');

    $fake->assertRoleAssigned($group->id, 'editor', fn ($held): bool => $held->resource->externalId === '42');

    $replaced = Authkit::groups()->replaceRoleAssignments($group->id, [
        new ReplaceGroupRoleAssignmentEntry('viewer'),
        new ReplaceGroupRoleAssignmentEntry('editor', resourceExternalId: '43', resourceTypeSlug: 'project'),
    ]);

    expect($replaced->data)->toHaveCount(2)
        ->and(Authkit::groups()->roleAssignments($group->id)->data)->toHaveCount(2);

    $fake->assertRoleAssigned($group->id, 'viewer');

    Authkit::groups()->removeRoleAssignmentsByCriteria($group->id, 'editor', resourceExternalId: '43', resourceTypeSlug: 'project');

    expect(Authkit::groups()->roleAssignments($group->id)->data)->toHaveCount(1);

    $remaining = Authkit::groups()->roleAssignments($group->id)->data[0];
    assert($remaining instanceof GroupRoleAssignment);

    Authkit::groups()->removeRoleAssignment($group->id, $remaining->id);

    expect(Authkit::groups()->roleAssignments($group->id)->data)->toHaveCount(0);

    $fake->assertRoleAssignmentRemoved($remaining->id);
});

it('busts the FGA cache on role-assignment mutations like production', function (): void {
    groupsFake();

    $fga = new class extends FgaChecker
    {
        public int $forgotten = 0;

        public function __construct() {}

        public function forgetCache(): void
        {
            $this->forgotten++;
        }
    };

    app()->instance(FgaChecker::class, $fga);

    $group = Authkit::groups()->create('org_acme', 'Platform Squad');
    $assignment = Authkit::groups()->assignRole($group->id, 'editor');
    Authkit::groups()->replaceRoleAssignments($group->id, []);

    expect($fga->forgotten)->toBe(2);
});

it('throws with guidance for unknown groups and assignments', function (): void {
    groupsFake();

    expect(fn (): Group => Authkit::groups()->get('org_acme', 'group_missing'))
        ->toThrow(InvalidArgumentException::class, 'create()');

    $fake = app(GroupManager::class);
    assert($fake instanceof GroupsFake);

    $group = Authkit::groups()->create('org_acme', 'Platform Squad');

    expect(fn () => Authkit::groups()->roleAssignment($group->id, 'gra_missing'))
        ->toThrow(InvalidArgumentException::class, 'assignRole()')
        ->and(fn () => Authkit::groups()->get('org_other', $group->id))
        ->toThrow(InvalidArgumentException::class, 'belongs to');
});

it('fails assertions with readable messages', function (): void {
    $fake = groupsFake();

    expect(fn () => $fake->assertGroupCreated('Ghost Squad'))
        ->toThrow(AssertionFailedError::class, 'No groups exist');

    Authkit::groups()->create('org_acme', 'Platform Squad');

    expect(fn () => $fake->assertGroupCreated('Ghost Squad'))
        ->toThrow(AssertionFailedError::class, 'Existing groups: [Platform Squad] in [org_acme]');
});
