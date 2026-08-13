<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Groups\GroupManager;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\Group;
use WorkOS\Resource\GroupRoleAssignment;
use WorkOS\Resource\GroupRoleAssignmentList;
use WorkOS\Resource\ReplaceGroupRoleAssignmentEntry;
use WorkOS\Resource\UserOrganizationMembershipBaseListData;

/**
 * An in-memory {@see GroupManager}: group CRUD, membership, and role
 * assignments recorded locally. The production contract that every
 * role-assignment mutation busts the FGA check cache is kept — the bound
 * {@see FgaChecker} (real or fake) still gets its forgetCache() call.
 */
final class GroupsFake extends GroupManager
{
    /** @var array<string, Group> */
    private array $groups = [];

    /** @var array<string, list<string>> organization membership ids keyed by group id */
    private array $members = [];

    /** @var array<string, array<string, GroupRoleAssignment>> assignments keyed by group id then assignment id */
    private array $roleAssignments = [];

    /** @var list<array{group_id: string, membership_id: string}> */
    private array $removedMembers = [];

    /** @var list<string> removed assignment ids */
    private array $removedAssignments = [];

    private int $sequence = 0;

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    public function list(string $organizationId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        $groups = array_values(array_filter(
            $this->groups,
            static fn (Group $group): bool => $group->organizationId === $organizationId,
        ));

        return new PaginatedResponse($groups, []);
    }

    public function create(string $organizationId, string $name, ?string $description = null): Group
    {
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $group = Group::fromArray([
            'object' => 'group',
            'id' => 'group_fake_'.++$this->sequence,
            'organization_id' => $organizationId,
            'name' => $name,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->groups[$group->id] = $group;
        $this->members[$group->id] = [];
        $this->roleAssignments[$group->id] = [];

        return $group;
    }

    public function get(string $organizationId, string $groupId): Group
    {
        $group = $this->entry($groupId);

        if ($group->organizationId !== $organizationId) {
            throw new InvalidArgumentException(
                "Fake group [{$groupId}] belongs to [{$group->organizationId}], not [{$organizationId}].",
            );
        }

        return $group;
    }

    public function update(string $organizationId, string $groupId, ?string $name = null, ?string $description = null): Group
    {
        $current = $this->get($organizationId, $groupId);

        $updated = Group::fromArray([
            'object' => 'group',
            'id' => $current->id,
            'organization_id' => $current->organizationId,
            'name' => $name ?? $current->name,
            'description' => $description ?? $current->description,
            'created_at' => $current->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'updated_at' => (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED),
        ]);

        return $this->groups[$groupId] = $updated;
    }

    public function delete(string $organizationId, string $groupId): void
    {
        $this->get($organizationId, $groupId);

        unset($this->groups[$groupId], $this->members[$groupId], $this->roleAssignments[$groupId]);
    }

    public function members(string $organizationId, string $groupId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        $group = $this->get($organizationId, $groupId);

        // Items must be the same resource type production serves — the SDK's
        // listGroupOrganizationMemberships hydrates
        // UserOrganizationMembershipBaseListData, and consumer code reads
        // properties (->id, ->userId) off each item.
        $memberships = array_map(
            fn (string $membershipId): UserOrganizationMembershipBaseListData => $this->membershipFor($membershipId, $group->organizationId),
            $this->members[$groupId] ?? [],
        );

        return new PaginatedResponse($memberships, []);
    }

    public function addMember(string $organizationId, string $groupId, string $organizationMembershipId): Group
    {
        $group = $this->get($organizationId, $groupId);

        if (! in_array($organizationMembershipId, $this->members[$groupId] ?? [], true)) {
            $this->members[$groupId][] = $organizationMembershipId;
        }

        return $group;
    }

    public function removeMember(string $organizationId, string $groupId, string $organizationMembershipId): void
    {
        $this->get($organizationId, $groupId);

        $this->members[$groupId] = array_values(array_filter(
            $this->members[$groupId] ?? [],
            static fn (string $membershipId): bool => $membershipId !== $organizationMembershipId,
        ));

        $this->removedMembers[] = ['group_id' => $groupId, 'membership_id' => $organizationMembershipId];
    }

    public function forMembership(string $organizationMembershipId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        $groups = array_values(array_filter(
            $this->groups,
            fn (Group $group): bool => in_array($organizationMembershipId, $this->members[$group->id] ?? [], true),
        ));

        return new PaginatedResponse($groups, []);
    }

    public function roleAssignments(string $groupId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        $this->entry($groupId);

        return new PaginatedResponse(array_values($this->roleAssignments[$groupId] ?? []), []);
    }

    public function roleAssignment(string $groupId, string $roleAssignmentId): GroupRoleAssignment
    {
        $this->entry($groupId);

        return $this->roleAssignments[$groupId][$roleAssignmentId] ?? throw new InvalidArgumentException(
            "No fake role assignment [{$roleAssignmentId}] exists on group [{$groupId}]. Create one first with assignRole().",
        );
    }

    public function assignRole(
        string $groupId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): GroupRoleAssignment {
        $this->entry($groupId);

        $assignment = $this->makeAssignment($groupId, $roleSlug, $resourceId, $resourceExternalId, $resourceTypeSlug);
        $this->roleAssignments[$groupId][$assignment->id] = $assignment;

        $this->bustFgaCache();

        return $assignment;
    }

    /**
     * @param  array<int, ReplaceGroupRoleAssignmentEntry>  $roleAssignments
     */
    public function replaceRoleAssignments(string $groupId, array $roleAssignments): GroupRoleAssignmentList
    {
        $this->entry($groupId);

        $this->roleAssignments[$groupId] = [];

        foreach ($roleAssignments as $entry) {
            $assignment = $this->makeAssignment(
                $groupId,
                $entry->roleSlug,
                $entry->resourceId,
                $entry->resourceExternalId,
                $entry->resourceTypeSlug,
            );

            $this->roleAssignments[$groupId][$assignment->id] = $assignment;
        }

        $this->bustFgaCache();

        return GroupRoleAssignmentList::fromArray([
            'object' => 'list',
            'data' => array_map(
                static fn (GroupRoleAssignment $assignment): array => $assignment->toArray(),
                array_values($this->roleAssignments[$groupId]),
            ),
            'list_metadata' => ['before' => null, 'after' => null],
        ]);
    }

    public function removeRoleAssignmentsByCriteria(
        string $groupId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): void {
        $this->entry($groupId);

        foreach ($this->roleAssignments[$groupId] ?? [] as $id => $assignment) {
            $matchesRole = $assignment->role->slug === $roleSlug;
            $matchesResource = ($resourceId === null || $assignment->resource->id === $resourceId)
                && ($resourceExternalId === null || $assignment->resource->externalId === $resourceExternalId)
                && ($resourceTypeSlug === null || $assignment->resource->resourceTypeSlug === $resourceTypeSlug);

            if ($matchesRole && $matchesResource) {
                unset($this->roleAssignments[$groupId][$id]);
                $this->removedAssignments[] = $id;
            }
        }

        $this->bustFgaCache();
    }

    public function removeRoleAssignment(string $groupId, string $roleAssignmentId): void
    {
        $this->roleAssignment($groupId, $roleAssignmentId);

        unset($this->roleAssignments[$groupId][$roleAssignmentId]);
        $this->removedAssignments[] = $roleAssignmentId;

        $this->bustFgaCache();
    }

    public function assertGroupCreated(string $name, ?string $organizationId = null): void
    {
        $matches = array_filter(
            $this->groups,
            static fn (Group $group): bool => $group->name === $name
                && ($organizationId === null || $group->organizationId === $organizationId),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected a group named [%s]%s but none matched. %s',
            $name,
            $organizationId !== null ? " in organization [{$organizationId}]" : '',
            $this->describeGroups(),
        ));
    }

    public function assertMemberAdded(string $groupId, string $organizationMembershipId): void
    {
        Assert::assertContains(
            $organizationMembershipId,
            $this->members[$groupId] ?? [],
            "Expected membership [{$organizationMembershipId}] to be in group [{$groupId}], but it is not.",
        );
    }

    public function assertMemberRemoved(string $groupId, string $organizationMembershipId): void
    {
        Assert::assertContains(
            ['group_id' => $groupId, 'membership_id' => $organizationMembershipId],
            $this->removedMembers,
            "Expected membership [{$organizationMembershipId}] to have been removed from group [{$groupId}], but it was not.",
        );
    }

    /**
     * @param  (callable(GroupRoleAssignment): bool)|null  $callback
     */
    public function assertRoleAssigned(string $groupId, string $roleSlug, ?callable $callback = null): void
    {
        $matches = array_filter(
            $this->roleAssignments[$groupId] ?? [],
            static fn (GroupRoleAssignment $assignment): bool => $assignment->role->slug === $roleSlug
                && ($callback === null || $callback($assignment)),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected group [%s] to hold a [%s] role assignment%s, but none matched.',
            $groupId,
            $roleSlug,
            $callback !== null ? ' passing the given callback' : '',
        ));
    }

    public function assertRoleAssignmentRemoved(string $roleAssignmentId): void
    {
        Assert::assertContains(
            $roleAssignmentId,
            $this->removedAssignments,
            "Expected role assignment [{$roleAssignmentId}] to be removed, but it was not.",
        );
    }

    private function entry(string $groupId): Group
    {
        return $this->groups[$groupId] ?? throw new InvalidArgumentException(
            "No fake group [{$groupId}] exists. Create one first with Authkit::groups()->create().",
        );
    }

    /**
     * A synthetic organization-membership resource for a member id — the
     * embedded user is derived from the membership id so tests can correlate
     * them without extra fixtures.
     */
    private function membershipFor(string $membershipId, string $organizationId): UserOrganizationMembershipBaseListData
    {
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        return UserOrganizationMembershipBaseListData::fromArray([
            'object' => 'organization_membership',
            'id' => $membershipId,
            'user_id' => 'user_of_'.$membershipId,
            'organization_id' => $organizationId,
            'status' => 'active',
            'directory_managed' => false,
            'created_at' => $now,
            'updated_at' => $now,
            'user' => [
                'object' => 'user',
                'id' => 'user_of_'.$membershipId,
                'email' => $membershipId.'@fake.workos.test',
                'email_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function makeAssignment(
        string $groupId,
        string $roleSlug,
        ?string $resourceId,
        ?string $resourceExternalId,
        ?string $resourceTypeSlug,
    ): GroupRoleAssignment {
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        return GroupRoleAssignment::fromArray([
            'object' => 'group_role_assignment',
            'id' => 'group_role_assignment_fake_'.++$this->sequence,
            'group_id' => $groupId,
            'role' => ['slug' => $roleSlug],
            'resource' => [
                'id' => $resourceId ?? 'resource_fake_org',
                'external_id' => $resourceExternalId ?? '',
                'resource_type_slug' => $resourceTypeSlug ?? 'organization',
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Same contract as production: every role-assignment mutation busts the
     * FGA check cache through whatever FgaChecker the container holds.
     */
    private function bustFgaCache(): void
    {
        app(FgaChecker::class)->forgetCache();
    }

    private function describeGroups(): string
    {
        if ($this->groups === []) {
            return 'No groups exist.';
        }

        $names = array_map(static fn (Group $group): string => "[{$group->name}] in [{$group->organizationId}]", $this->groups);

        return 'Existing groups: '.implode(', ', array_values($names)).'.';
    }
}
