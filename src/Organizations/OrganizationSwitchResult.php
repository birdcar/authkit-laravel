<?php

declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

enum OrganizationSwitchResult: string
{
    /** The sealed session was refreshed scoped to the target organization. */
    case Switched = 'switched';

    /** The request carries no sealed session cookie — nothing to refresh. */
    case NoSession = 'no-session';

    /**
     * WorkOS refused the refresh: no active membership in the target org, an
     * already-rotated refresh token, or any other rejection. Callers fall
     * back to a full re-authorize redirect carrying the org hint.
     */
    case Refused = 'refused';
}
