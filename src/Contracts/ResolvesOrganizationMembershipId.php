<?php

declare(strict_types=1);

namespace Authkit\Authkit\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ResolvesOrganizationMembershipId
{
    /**
     * Resolve the WorkOS organization_membership_id for the given (user, organization)
     * pair, or null if none exists (yet, or ever).
     */
    public function resolve(Authenticatable $user, string $organizationId): ?string;
}
