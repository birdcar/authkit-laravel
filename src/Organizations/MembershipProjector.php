<?php

declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Invitations\InvitationManager;
use Authkit\Authkit\Listeners\UpsertOrganizationAndMembershipFromLogin;
use Authkit\Authkit\Models\WorkosMembership;
use Illuminate\Database\Eloquent\Model;
use WorkOS\Resource\UserOrganizationMembership;

/**
 * Synchronously converges the local projection for one (organization, user)
 * pair: the org row exists (created from the remote record when missing) and
 * the membership row exists (read back from WorkOS when missing). Idempotent
 * — the events pipeline re-upserts the same rows when it catches up.
 *
 * Shared by the login-time backfill listener
 * ({@see UpsertOrganizationAndMembershipFromLogin})
 * and {@see InvitationManager::accept()}, which
 * face the same gap: WorkOS already knows about the membership, the local
 * projection hasn't caught up, and the very next request needs it.
 */
class MembershipProjector
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function ensureProjected(string $organizationId, string $userId): void
    {
        $organizationModel = config('authkit.organization.model');

        if (! is_string($organizationModel) || $organizationModel === ''
            || ! class_exists($organizationModel) || ! is_a($organizationModel, Model::class, true)) {
            return; // app hasn't wired an org model — org context isn't in use
        }

        $organization = $organizationModel::query()->firstWhere('workos_id', $organizationId);

        if ($organization === null) {
            $remote = $this->clients->client()->organizations()->getOrganization($organizationId);

            // forceFill, not create(): trusted data straight from WorkOS must
            // land regardless of how the app's org model declares $fillable —
            // the same reasoning HasWorkosUser::findOrCreateForWorkosUser
            // documents for the user side.
            $organization = $organizationModel::query()->newModelInstance();
            $organization->forceFill([
                'name' => $remote->name,
                'workos_id' => $remote->id,
            ])->save();
            // HasWorkosOrganization's create-observer still fires here — its
            // job's first line ("already synced?") sees workos_id set and no-ops.
        }

        $organizationWorkosId = $organization->getAttribute('workos_id');

        if (is_string($organizationWorkosId) && $organizationWorkosId !== '') {
            $this->syncMembership($organizationWorkosId, $userId);
        }
    }

    private function syncMembership(string $organizationWorkosId, string $userId): void
    {
        $exists = WorkosMembership::query()
            ->where('organization_id', $organizationWorkosId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return; // already projected — the events pipeline keeps it current from here
        }

        $memberships = $this->clients->client()->organizationMembership()->listOrganizationMemberships(
            organizationId: $organizationWorkosId,
            userId: $userId,
        );

        // At most one active membership exists per (org, user) pair under
        // WorkOS's own default status filter; reconciling a genuinely ambiguous
        // multi-membership state is the events pipeline's job, not this
        // projector's.
        $membership = $memberships->data[0] ?? null;

        if (! $membership instanceof UserOrganizationMembership) {
            return; // WorkOS hasn't surfaced the membership via this endpoint yet
        }

        WorkosMembership::query()->create([
            'workos_id' => $membership->id,
            'organization_id' => $organizationWorkosId,
            'user_id' => $userId,
            'role' => $membership->role->slug,
            'status' => $membership->status->value,
        ]);
    }
}
