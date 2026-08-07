<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

/**
 * The iss/aud check the vendored SDK deliberately leaves undone
 * (`SessionManager::decodeAccessToken()`'s TODO, SessionManager.php:444).
 */
final readonly class JwtClaimsValidator
{
    public function __construct(
        private ?string $expectedIssuer,
        private string $expectedAudience,
    ) {}

    public function validate(AccessTokenClaims $claims): bool
    {
        // The audience-equivalent check is the one that actually stops an auth
        // bypass: a WorkOS environment signs every application's tokens with one
        // JWKS key, so without it a validly-signed, non-expired token minted for
        // application B is accepted by application A's guard. AuthKit tokens carry
        // no `aud` claim — `client_id` serves that purpose.
        if (! hash_equals($this->expectedAudience, $claims->clientId)) {
            return false;
        }

        // Issuer enforcement stays opt-in until the empirical token audit confirms
        // the canonical value (docs/token-audit-findings.md is still TBD and
        // explicitly forbids enforcing an unconfirmed one). Enforcing a guessed
        // issuer would reject every valid session on any environment using a custom
        // AuthKit auth domain — a silent, total lockout. Setting WORKOS_JWT_ISSUER
        // turns the check on with no code change.
        if ($this->expectedIssuer === null || $this->expectedIssuer === '') {
            return true;
        }

        return hash_equals($this->expectedIssuer, $claims->iss);
    }
}
