<?php

declare(strict_types=1);

namespace Authkit\Authkit\Contracts;

interface HasAccessTokenClaims
{
    /**
     * The decoded, signature-verified access-token claims for the current
     * request, or null if there is no authenticated session.
     *
     * @return array<string, mixed>|null
     */
    public function accessTokenClaims(): ?array;
}
