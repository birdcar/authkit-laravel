<?php

declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Models\WorkosMembership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a WorkOS organization_membership_id from the local projection —
 * never a live API call. The whole point of this seam is that an FGA check
 * needs a membership ID cheaply, not a second network round trip per check.
 */
final class MembershipProjectionResolver implements ResolvesOrganizationMembershipId
{
    public function resolve(Authenticatable $user, string $organizationId): ?string
    {
        // getAttribute(), not ->workos_id: degrades to null rather than a
        // fatal "undefined property" when handed an Authenticatable that
        // isn't the app's actual User model (e.g. a test double).
        $userWorkosId = $user instanceof Model ? $user->getAttribute('workos_id') : null;

        if (! is_string($userWorkosId) || $userWorkosId === '') {
            return null;
        }

        // Only an active membership is a valid check subject: WorkOS may
        // reject or treat inactive/pending memberships as non-authorized, so
        // the resolver's contract stays "a real, checkable membership, or null".
        $workosId = WorkosMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userWorkosId)
            ->where('status', 'active')
            ->value('workos_id');

        return is_string($workosId) ? $workosId : null;
    }
}
