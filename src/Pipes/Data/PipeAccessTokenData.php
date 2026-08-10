<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Data;

use DateTimeImmutable;
use WorkOS\Resource\DataIntegrationAccessTokenResponseAccessToken;

/**
 * A valid provider access token vended by WorkOS. WorkOS refreshes behind
 * this call — by the time an instance exists, the token is usable as-is.
 */
final readonly class PipeAccessTokenData
{
    public function __construct(
        public string $accessToken,
        public ?DateTimeImmutable $expiresAt,
        /** @var array<string> */
        public array $scopes,
        /** @var array<string> */
        public array $missingScopes,
    ) {}

    public static function fromResponse(DataIntegrationAccessTokenResponseAccessToken $token): self
    {
        return new self(
            accessToken: $token->accessToken,
            expiresAt: $token->expiresAt,
            scopes: $token->scopes,
            missingScopes: $token->missingScopes,
        );
    }
}
