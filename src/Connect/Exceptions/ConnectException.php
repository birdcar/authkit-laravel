<?php

declare(strict_types=1);

namespace Authkit\Authkit\Connect\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The single exception surface for Connect operations: wraps SDK/Guzzle
 * exceptions so neither ever needs to be caught by name in consuming code.
 */
final class ConnectException extends RuntimeException
{
    public static function organizationIdRequired(): self
    {
        return new self(
            'createM2MApplication requires a non-blank organizationId — M2M applications are organization-scoped at the WorkOS API level. '
            .'Resolve the organization first (e.g. via Authkit::currentOrganization()) before creating the application.',
        );
    }

    public static function operationFailed(Throwable $previous): self
    {
        return new self("Connect operation failed: {$previous->getMessage()}", 0, $previous);
    }
}
