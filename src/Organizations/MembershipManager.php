<?php

declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Testing\Fakes\MembershipsFake;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\OrganizationMembership;
use WorkOS\Resource\OrganizationMembershipStatus;
use WorkOS\Resource\UserOrganizationMembership;
use WorkOS\Service\RoleMultiple;
use WorkOS\Service\RoleSingle;

/**
 * Organization-membership management surface, resolved via
 * Authkit::memberships(). WorkOS stays canonical, but unlike the other
 * passthrough managers every mutation here ALSO upserts the local
 * workos_memberships projection synchronously: membership writes are what
 * apps gate UI on (member lists, the current user's own team list), and
 * waiting for the events pipeline would leave the very request that made the
 * change reading its own stale projection. The upsert is idempotent — the
 * events pipeline re-upserts the same row when it catches up.
 *
 * Not final: {@see MembershipsFake} extends it.
 */
class MembershipManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * Create an active membership for a user in an organization. Pass the
     * role slug explicitly on privileged paths — WorkOS falls back to the
     * environment's default role (typically `member`) when none is given, so
     * an onboarding flow that omits it creates an org whose creator cannot
     * administer it.
     *
     * @param  string|list<string>|null  $role
     */
    public function create(Model|string $organization, Model|Authenticatable|string $user, string|array|null $role = null): OrganizationMembership
    {
        $membership = $this->clients->client()->organizationMembership()->createOrganizationMembership(
            userId: $this->userId($user),
            organizationId: $this->organizationId($organization),
            role: $this->role($role),
        );

        $this->upsertProjection(
            $membership->id,
            $membership->organizationId,
            $membership->userId,
            $membership->role->slug,
            $membership->status->value,
        );

        return $membership;
    }

    public function get(string $membershipId): UserOrganizationMembership
    {
        return $this->clients->client()->organizationMembership()->getOrganizationMembership($membershipId);
    }

    /**
     * List memberships from WorkOS. At least one of organization or user must
     * be provided (the API's own requirement). Items are
     * UserOrganizationMembership instances. For request-time reads prefer the
     * local projection (an org model's memberships() relation or the user's
     * organizations() relation) — this is the live remote view.
     *
     * @param  list<string>|null  $statuses  any of 'active', 'inactive', 'pending'; WorkOS defaults to active only
     */
    public function list(
        Model|string|null $organization = null,
        Model|Authenticatable|string|null $user = null,
        ?array $statuses = null,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        return $this->clients->client()->organizationMembership()->listOrganizationMemberships(
            before: $before,
            after: $after,
            limit: $limit,
            organizationId: $organization !== null ? $this->organizationId($organization) : null,
            statuses: $statuses !== null ? $this->statuses($statuses) : null,
            userId: $user !== null ? $this->userId($user) : null,
        );
    }

    /**
     * Change a membership's role(s).
     *
     * @param  string|list<string>  $role
     */
    public function update(string $membershipId, string|array $role): UserOrganizationMembership
    {
        $membership = $this->clients->client()->organizationMembership()->updateOrganizationMembership(
            $membershipId,
            role: $this->role($role),
        );

        $this->upsertProjection(
            $membership->id,
            $membership->organizationId,
            $membership->userId,
            $membership->role->slug,
            $membership->status->value,
        );

        return $membership;
    }

    /**
     * Permanently delete a membership — the remove-member operation. The
     * projection row goes with it, so member lists reflect the removal on
     * this very request.
     */
    public function delete(string $membershipId): void
    {
        $this->clients->client()->organizationMembership()->deleteOrganizationMembership($membershipId);

        WorkosMembership::query()->where('workos_id', $membershipId)->delete();
    }

    public function deactivate(string $membershipId): OrganizationMembership
    {
        $membership = $this->clients->client()->organizationMembership()->deactivateOrganizationMembership($membershipId);

        $this->upsertProjection(
            $membership->id,
            $membership->organizationId,
            $membership->userId,
            $membership->role->slug,
            $membership->status->value,
        );

        return $membership;
    }

    public function reactivate(string $membershipId): UserOrganizationMembership
    {
        $membership = $this->clients->client()->organizationMembership()->reactivateOrganizationMembership($membershipId);

        $this->upsertProjection(
            $membership->id,
            $membership->organizationId,
            $membership->userId,
            $membership->role->slug,
            $membership->status->value,
        );

        return $membership;
    }

    /**
     * Idempotent by key: the events pipeline's own upsert converges on the
     * same workos_id-keyed row.
     */
    private function upsertProjection(string $membershipId, string $organizationId, string $userId, string $role, string $status): void
    {
        WorkosMembership::query()->updateOrCreate(
            ['workos_id' => $membershipId],
            [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'role' => $role,
                'status' => $status,
            ],
        );
    }

    private function organizationId(Model|string $organization): string
    {
        if (is_string($organization)) {
            if ($organization === '') {
                throw new InvalidArgumentException(
                    'An empty string is not a WorkOS organization id. Pass an org_... id or a synced organization model.',
                );
            }

            return $organization;
        }

        // The literal column, not [authkit.organization.external_id_column] —
        // the same reasoning as BelongsToWorkosOrganizations: the projection
        // and resolver key off workos_id, so honouring the config key here
        // would write memberships the rest of the package cannot match back.
        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'Cannot manage memberships for [%s] #%s: its workos_id is empty — the organization has '
                .'not synced to WorkOS yet.',
                $organization::class,
                is_scalar($key = $organization->getKey()) ? (string) $key : '?',
            ));
        }

        return $workosId;
    }

    private function userId(Model|Authenticatable|string $user): string
    {
        if (is_string($user)) {
            if ($user === '') {
                throw new InvalidArgumentException(
                    'An empty string is not a WorkOS user id. Pass a user_... id or a WorkOS-linked user model.',
                );
            }

            return $user;
        }

        $workosId = $user instanceof Model ? $user->getAttribute('workos_id') : null;

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'Cannot manage memberships for [%s]: it has no workos_id — the user has never been '
                .'linked to a WorkOS user (they get one on first login, or pass the user_... id directly).',
                get_debug_type($user),
            ));
        }

        return $workosId;
    }

    /**
     * @param  string|list<string>|null  $role
     */
    private function role(string|array|null $role): RoleSingle|RoleMultiple|null
    {
        if ($role === null) {
            return null;
        }

        if (is_string($role)) {
            return new RoleSingle($role);
        }

        return new RoleMultiple($role);
    }

    /**
     * @param  list<string>  $statuses
     * @return list<OrganizationMembershipStatus>
     */
    private function statuses(array $statuses): array
    {
        return array_map(
            static fn (string $status): OrganizationMembershipStatus => OrganizationMembershipStatus::from($status),
            $statuses,
        );
    }
}
