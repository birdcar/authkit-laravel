<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use InvalidArgumentException;
use WorkOS\SessionManager;

/**
 * Base64url-decodes a JWT's payload segment without re-verifying the signature.
 *
 * SECURITY INVARIANT: only ever call this on a token that
 * {@see SessionManager::authenticate()} has already returned
 * `authenticated: true` for, in the same request, using the identical sealed-cookie
 * string and cookie password. This class performs no signature check of its own —
 * it exists purely to recover the claims (`iss`, `client_id`, `sub`, `jti`, `act`)
 * that the SDK's own return array omits.
 */
final class JwtPayloadDecoder
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed access token: expected 3 JWT segments.');
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Malformed access token: payload segment is not valid JSON.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
