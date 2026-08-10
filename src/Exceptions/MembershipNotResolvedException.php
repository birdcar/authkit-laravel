<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use RuntimeException;

/**
 * Thrown when an FGA check cannot resolve a WorkOS organization membership for
 * the current (user, organization) context — named and loud, so a projection
 * that hasn't synced yet is distinguishable from a legitimate deny (false).
 */
final class MembershipNotResolvedException extends RuntimeException
{
    public static function forContext(int|string $userId, string $organizationId): self
    {
        return new self(sprintf(
            'No WorkOS organization membership could be resolved for user [%s] in organization [%s]. '
            .'Bind %s to a real implementation once the memberships projection is available, '
            .'or pass organizationMembershipId explicitly to Authkit::check().',
            $userId,
            $organizationId,
            ResolvesOrganizationMembershipId::class,
        ));
    }
}
