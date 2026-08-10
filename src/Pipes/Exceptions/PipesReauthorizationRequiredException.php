<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Exceptions;

use RuntimeException;

/**
 * The connection exists but its grant no longer covers what the integration
 * requests — surfaced by WorkOS either as a hard `needs_reauthorization`
 * error or as a soft non-empty `missing_scopes` list on an otherwise-active
 * token. Both branches unify here, carrying the ready-to-redirect
 * reauthorization URL so callers need no second round-trip of their own.
 */
final class PipesReauthorizationRequiredException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $providerSlug,
        public readonly string $userId,
        public readonly ?string $organizationId,
        /** @var array<string> */
        public readonly array $missingScopes,
        public readonly string $reauthorizationUrl,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string>  $missingScopes
     */
    public static function forProvider(
        string $providerSlug,
        string $userId,
        ?string $organizationId,
        array $missingScopes,
        string $reauthorizationUrl,
    ): self {
        return new self(
            message: sprintf('Connected account for provider "%s" needs reauthorization.', $providerSlug),
            providerSlug: $providerSlug,
            userId: $userId,
            organizationId: $organizationId,
            missingScopes: $missingScopes,
            reauthorizationUrl: $reauthorizationUrl,
        );
    }
}
