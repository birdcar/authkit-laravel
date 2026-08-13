<?php

declare(strict_types=1);

namespace Authkit\Authkit\Groups;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Contracts\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\Group;
use WorkOS\Resource\GroupRoleAssignment;
use WorkOS\Resource\GroupRoleAssignmentList;
use WorkOS\Resource\ReplaceGroupRoleAssignmentEntry;

/**
 * Organization groups surface, resolved via Authkit::groups(): org groups
 * CRUD, group membership, and group role assignments. Pure passthroughs —
 * groups are canonical WorkOS state, never projected locally.
 *
 * Every role-assignment mutation busts the FGA check cache: a group's role
 * on a resource changes effective permissions for every member. The calls
 * are free when the cache feature is off (FgaChecker::forgetCache() is a
 * config-guarded no-op), which is the default.
 */
class GroupManager
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly FgaChecker $fga,
    ) {}

    // --- Org groups CRUD ---

    /**
     * List an organization's groups. Items are Group instances.
     */
    public function list(string $organizationId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->groups()->listOrganizationGroups($organizationId, $before, $after, $limit);
    }

    public function create(string $organizationId, string $name, ?string $description = null): Group
    {
        return $this->clients->client()->groups()->createOrganizationGroup($organizationId, $name, $description);
    }

    public function get(string $organizationId, string $groupId): Group
    {
        return $this->clients->client()->groups()->getOrganizationGroup($organizationId, $groupId);
    }

    public function update(string $organizationId, string $groupId, ?string $name = null, ?string $description = null): Group
    {
        return $this->clients->client()->groups()->updateOrganizationGroup($organizationId, $groupId, $name, $description);
    }

    public function delete(string $organizationId, string $groupId): void
    {
        $this->clients->client()->groups()->deleteOrganizationGroup($organizationId, $groupId);
    }

    // --- Group membership (organization membership <-> group) ---

    /**
     * List the organization memberships in a group. Items are
     * UserOrganizationMembershipBaseListData instances.
     */
    public function members(string $organizationId, string $groupId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->groups()->listGroupOrganizationMemberships($organizationId, $groupId, $before, $after, $limit);
    }

    public function addMember(string $organizationId, string $groupId, string $organizationMembershipId): Group
    {
        return $this->clients->client()->groups()->createGroupOrganizationMembership($organizationId, $groupId, $organizationMembershipId);
    }

    public function removeMember(string $organizationId, string $groupId, string $organizationMembershipId): void
    {
        $this->clients->client()->groups()->deleteGroupOrganizationMembership($organizationId, $groupId, $organizationMembershipId);
    }

    /**
     * Which groups is this organization membership in? Items are Group
     * instances.
     */
    public function forMembership(string $organizationMembershipId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->organizationMembership()->listOrganizationMembershipGroups(
            $organizationMembershipId, $before, $after, $limit,
        );
    }

    // --- Group role assignments (each mutation busts the FGA check cache) ---

    /**
     * List a group's role assignments. Items are GroupRoleAssignment
     * instances.
     */
    public function roleAssignments(string $groupId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->authorization()->listGroupRoleAssignments($groupId, $before, $after, $limit);
    }

    public function roleAssignment(string $groupId, string $roleAssignmentId): GroupRoleAssignment
    {
        return $this->clients->client()->authorization()->getGroupRoleAssignment($groupId, $roleAssignmentId);
    }

    /**
     * Assign a role to the group on a resource — by internal resource id, by
     * external id + type slug, or on the organization itself when all three
     * resource arguments are omitted.
     */
    public function assignRole(
        string $groupId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): GroupRoleAssignment {
        $assignment = $this->clients->client()->authorization()->createGroupRoleAssignment(
            $groupId, $roleSlug, $resourceId, $resourceExternalId, $resourceTypeSlug,
        );

        $this->fga->forgetCache();

        return $assignment;
    }

    /**
     * Replace ALL of the group's role assignments with the provided list —
     * existing assignments not in it are removed.
     *
     * @param  array<int, ReplaceGroupRoleAssignmentEntry>  $roleAssignments
     */
    public function replaceRoleAssignments(string $groupId, array $roleAssignments): GroupRoleAssignmentList
    {
        $result = $this->clients->client()->authorization()->updateGroupRoleAssignments($groupId, $roleAssignments);

        $this->fga->forgetCache();

        return $result;
    }

    public function removeRoleAssignmentsByCriteria(
        string $groupId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): void {
        $this->clients->client()->authorization()->deleteGroupRoleAssignments(
            $groupId, $roleSlug, $resourceId, $resourceExternalId, $resourceTypeSlug,
        );

        $this->fga->forgetCache();
    }

    public function removeRoleAssignment(string $groupId, string $roleAssignmentId): void
    {
        $this->clients->client()->authorization()->deleteGroupRoleAssignment($groupId, $roleAssignmentId);

        $this->fga->forgetCache();
    }
}
