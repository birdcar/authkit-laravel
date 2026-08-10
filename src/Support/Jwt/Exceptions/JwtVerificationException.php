<?php

declare(strict_types=1);

namespace Authkit\Authkit\Support\Jwt\Exceptions;

use RuntimeException;

/**
 * A presented JWT failed verification — bad shape, disallowed algorithm,
 * unknown signing key, bad signature, or expiry. Every case here means "the
 * caller's credential is invalid" (a 401 for HTTP callers); an unreachable
 * JWKS endpoint is deliberately NOT one of these — see
 * {@see JwksUnavailableException} — so an infrastructure outage can never be
 * mistaken for a forged token.
 */
class JwtVerificationException extends RuntimeException
{
    public static function malformedToken(): self
    {
        return new self('Malformed JWT: expected three base64url segments with a JSON header and payload.');
    }

    public static function disallowedAlgorithm(): self
    {
        return new self('Disallowed JWT algorithm: only RS256 is accepted.');
    }

    public static function missingKeyId(): self
    {
        return new self('JWT header is missing a kid; refusing to guess which JWKS key to verify against.');
    }

    public static function unknownSigningKey(): self
    {
        return new self('No JWKS key matches the JWT kid, even after a forced JWKS refresh.');
    }

    public static function malformedKey(): self
    {
        return new self('The JWKS key matching the JWT kid is not a well-formed RSA JWK.');
    }

    public static function invalidSignature(): self
    {
        return new self('JWT signature verification failed.');
    }

    public static function expired(): self
    {
        return new self('JWT has expired.');
    }
}
