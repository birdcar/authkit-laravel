<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves nothing, always. Bind it (or set it as
 * authkit.authorization.membership_resolver) to make implicit FGA membership
 * resolution fail loudly with MembershipNotResolvedException instead of
 * reading the memberships projection — explicit organizationMembershipId
 * arguments to Authkit::check() keep working.
 */
final class NullMembershipResolver implements ResolvesOrganizationMembershipId
{
    public function resolve(Authenticatable $user, string $organizationId): ?string
    {
        return null;
    }
}
