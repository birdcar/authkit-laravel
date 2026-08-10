<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use Authkit\Authkit\Vault\ResolvesVaultKeyContext;
use RuntimeException;
use Throwable;

/**
 * Thrown when `authkit.vault.key_context_resolver` names something the
 * container cannot resolve into a ResolvesVaultKeyContext — at first
 * resolution, naming the config key, instead of a raw container exception
 * surfacing deep inside the first encrypt call.
 */
final class InvalidVaultKeyContextResolverException extends RuntimeException
{
    public static function forConfiguredClass(string $configuredClass, ?Throwable $previous = null): self
    {
        return new self(
            "The class configured at 'authkit.vault.key_context_resolver' ({$configuredClass}) could not be "
            .'resolved from the container. Confirm the class exists, is autoloadable, and implements '
            .ResolvesVaultKeyContext::class.'.',
            previous: $previous,
        );
    }
}
