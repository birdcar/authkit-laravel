<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\Role;
use WorkOS\Resource\UserRoleAssignment;

/**
 * Environment/organization role CRUD plus resource-scoped role assignment,
 * resolved via Authkit::roles(). Thin 1:1 pass-throughs over the SDK's
 * Authorization service — no branching logic, no local state.
 *
 * Mutating methods accept the SDK's own ?RequestOptions so a retried call can
 * carry an idempotency key (spec-phase-5 Failure Mode 12); RequestOptions is a
 * plain value object, not a service type, so accepting it directly is
 * consistent with returning SDK resources like Role.
 *
 * Deliberately absent: deleteEnvironmentRole (no such SDK/API method —
 * environment roles are undeletable via the API) and default org-level role
 * changes (OrganizationMembershipService territory, owned by the memberships
 * projection, not the Authorization service this class wraps).
 */
final class RoleManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * @return array<int, Role>
     */
    public function environment(): array
    {
        return $this->clients->client()->authorization()->listEnvironmentRoles()->data;
    }

    public function createEnvironmentRole(string $slug, string $name, ?string $description = null, ?string $resourceTypeSlug = null, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->createEnvironmentRole($slug, $name, $description, $resourceTypeSlug, $options);
    }

    public function getEnvironmentRole(string $slug): Role
    {
        return $this->clients->client()->authorization()->getEnvironmentRole($slug);
    }

    public function updateEnvironmentRole(string $slug, ?string $name = null, ?string $description = null, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->updateEnvironmentRole($slug, $name, $description, $options);
    }

    public function addEnvironmentRolePermission(string $slug, string $permissionSlug, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->addEnvironmentRolePermission($slug, $permissionSlug, $options);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function setEnvironmentRolePermissions(string $slug, array $permissions, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->setEnvironmentRolePermissions($slug, $permissions, $options);
    }

    /**
     * @return array<int, Role>
     */
    public function forOrganization(string $organizationId): array
    {
        return $this->clients->client()->authorization()->listOrganizationRoles($organizationId)->data;
    }

    public function createOrganizationRole(string $organizationId, string $name, ?string $slug = null, ?string $description = null, ?string $resourceTypeSlug = null, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->createOrganizationRole($organizationId, $name, $slug, $description, $resourceTypeSlug, $options);
    }

    public function getOrganizationRole(string $organizationId, string $slug): Role
    {
        return $this->clients->client()->authorization()->getOrganizationRole($organizationId, $slug);
    }

    public function updateOrganizationRole(string $organizationId, string $slug, ?string $name = null, ?string $description = null, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->updateOrganizationRole($organizationId, $slug, $name, $description, $options);
    }

    public function deleteOrganizationRole(string $organizationId, string $slug, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->deleteOrganizationRole($organizationId, $slug, $options);
    }

    public function addOrganizationRolePermission(string $organizationId, string $slug, string $permissionSlug, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->addOrganizationRolePermission($organizationId, $slug, $permissionSlug, $options);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function setOrganizationRolePermissions(string $organizationId, string $slug, array $permissions, ?RequestOptions $options = null): Role
    {
        return $this->clients->client()->authorization()->setOrganizationRolePermissions($organizationId, $slug, $permissions, $options);
    }

    public function removeOrganizationRolePermission(string $organizationId, string $slug, string $permissionSlug, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->removeOrganizationRolePermission($organizationId, $slug, $permissionSlug, $options);
    }

    public function assign(string $organizationMembershipId, string $roleSlug, ResourceTarget $resource, ?RequestOptions $options = null): UserRoleAssignment
    {
        return $this->clients->client()->authorization()->assignRole(
            $organizationMembershipId,
            $roleSlug,
            $resource->toSdkTarget(),
            $options,
        );
    }

    public function remove(string $organizationMembershipId, string $roleSlug, ResourceTarget $resource, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->removeRole($organizationMembershipId, $roleSlug, $resource->toSdkTarget(), $options);
    }

    public function removeAssignment(string $organizationMembershipId, string $roleAssignmentId, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->removeRoleAssignment($organizationMembershipId, $roleAssignmentId, $options);
    }

    /**
     * A page of UserRoleAssignment resources for the membership.
     */
    public function assignmentsFor(string $organizationMembershipId): PaginatedResponse
    {
        return $this->clients->client()->authorization()->listRoleAssignments($organizationMembershipId);
    }
}
