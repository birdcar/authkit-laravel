<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Exceptions;

use RuntimeException;

/**
 * The user simply has not connected this provider — an expected business
 * state with its own named type so callers branch on the exception class,
 * never on "some exception happened" (which would silently conflate this
 * with a WorkOS outage).
 */
final class PipesAccountNotConnectedException extends RuntimeException
{
    private function __construct(string $message, public readonly string $providerSlug, public readonly string $userId)
    {
        parent::__construct($message);
    }

    public static function forProvider(string $providerSlug, string $userId): self
    {
        return new self(
            sprintf('User "%s" has not connected provider "%s".', $userId, $providerSlug),
            $providerSlug,
            $userId,
        );
    }
}
