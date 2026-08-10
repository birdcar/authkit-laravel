<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use RuntimeException;

/**
 * Thrown when the authkit-key guard validates a key but has no model class to
 * resolve the principal against. A config error, not a per-request auth
 * decision: failing loudly here (naming the exact key) beats the raw TypeError
 * that calling ::query() on a null class-string would produce deep inside the
 * guard — or worse, an unexplained 401 on every keyed request.
 */
final class MissingModelConfigurationException extends RuntimeException
{
    public static function forUserModel(): self
    {
        return new self(
            'Cannot resolve an API-key principal: no user model is configured. '
            .'Set [auth.providers.workos.model] (or [auth.providers.users.model]) to your User model FQCN '
            .'before using the authkit-key guard for user-scoped keys.',
        );
    }

    public static function forOrganizationModel(): self
    {
        return new self(
            'Cannot resolve an API-key principal: [authkit.organization.model] is not configured. '
            .'Set it to your Organization model FQCN before using the authkit-key guard for org-scoped keys.',
        );
    }
}
