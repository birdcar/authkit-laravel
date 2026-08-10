<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Auth\JwtPayloadDecoder;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Models\WorkosMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use WorkOS\Resource\UserOrganizationMembership;

/**
 * Closes the gap between "WorkOS already knows about this org/membership"
 * (dashboard-created, directory-provisioned, invited, or created by another
 * app) and "the local projection has caught up" — synchronously, at login
 * time, before the response is returned, instead of waiting for the events
 * poller's next pass.
 */
final class UpsertOrganizationAndMembershipFromLogin
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function handle(Login $event): void
    {
        try {
            $this->sync($event);
        } catch (Throwable $e) {
            // Never let a projection-backfill failure break a successful login.
            Log::warning('authkit: login-time org/membership projection upsert failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sync(Login $event): void
    {
        // Decodes the freshly-exchanged access token directly: it arrived
        // moments ago from authenticateWithCode()'s HTTPS response body, so the
        // transport is the trust boundary — there is no sealed cookie yet whose
        // signature-verification step could be reused. The DTO is shared with
        // the guard for shape, not because the same security invariant applies.
        $claims = AccessTokenClaims::fromPayload(
            JwtPayloadDecoder::decode($event->response->accessToken),
        );

        if ($claims->organizationId === null) {
            return; // no current org on this login — nothing to project
        }

        $organizationModel = config('authkit.organization.model');

        if (! is_string($organizationModel) || $organizationModel === ''
            || ! class_exists($organizationModel) || ! is_a($organizationModel, Model::class, true)) {
            return; // app hasn't wired an org model — org context isn't in use
        }

        $organization = $organizationModel::query()->firstWhere('workos_id', $claims->organizationId);

        if ($organization === null) {
            $remote = $this->clients->client()->organizations()->getOrganization($claims->organizationId);

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
            $this->syncMembership($claims, $organizationWorkosId);
        }
    }

    private function syncMembership(AccessTokenClaims $claims, string $organizationWorkosId): void
    {
        $exists = WorkosMembership::query()
            ->where('organization_id', $organizationWorkosId)
            ->where('user_id', $claims->sub)
            ->exists();

        if ($exists) {
            return; // already projected — the events pipeline keeps it current from here
        }

        $memberships = $this->clients->client()->organizationMembership()->listOrganizationMemberships(
            organizationId: $organizationWorkosId,
            userId: $claims->sub,
        );

        // At most one active membership exists per (org, user) pair under
        // WorkOS's own default status filter; reconciling a genuinely ambiguous
        // multi-membership state is the events pipeline's job, not this
        // listener's.
        $membership = $memberships->data[0] ?? null;

        if (! $membership instanceof UserOrganizationMembership) {
            return; // WorkOS hasn't surfaced the membership via this endpoint yet
        }

        WorkosMembership::query()->create([
            'workos_id' => $membership->id,
            'organization_id' => $organizationWorkosId,
            'user_id' => $claims->sub,
            'role' => $membership->role->slug,
            'status' => $membership->status->value,
        ]);
    }
}
