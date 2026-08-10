<?php

declare(strict_types=1);

namespace Authkit\Authkit\Support\Jwt\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The JWKS document could not be fetched and no cached copy exists — our
 * infrastructure failed to verify, not the caller presenting bad credentials.
 * Deliberately NOT a {@see JwtVerificationException} subclass: conflating the
 * two would hide a WorkOS outage behind what looks like every client having a
 * bad token (spec-phase-10 failure mode F-jwks-down — 503, never 401).
 */
final class JwksUnavailableException extends RuntimeException
{
    public static function fetchFailed(string $jwksUrl, Throwable $previous): self
    {
        return new self("Failed to fetch the JWKS document from [{$jwksUrl}]: {$previous->getMessage()}", 0, $previous);
    }

    public static function unexpectedStatus(string $jwksUrl, int $status): self
    {
        return new self("The JWKS endpoint [{$jwksUrl}] responded with HTTP {$status}.");
    }

    public static function invalidDocument(string $jwksUrl): self
    {
        return new self("The JWKS endpoint [{$jwksUrl}] returned a body that is not a JSON object.");
    }
}
