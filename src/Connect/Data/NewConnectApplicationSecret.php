<?php

declare(strict_types=1);

namespace Authkit\Authkit\Connect\Data;

use DateTimeImmutable;
use WorkOS\Resource\NewConnectApplicationSecret as SdkNewConnectApplicationSecret;

/**
 * A freshly-created client secret. `$secret` carries the plaintext value,
 * returned exactly once at creation and never re-fetchable afterwards —
 * matching the SDK resource's own docblock for the underlying field.
 */
final readonly class NewConnectApplicationSecret
{
    public function __construct(
        public string $id,
        public string $secretHint,
        public string $secret,
        public ?DateTimeImmutable $lastUsedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function fromSdk(SdkNewConnectApplicationSecret $secret): self
    {
        return new self(
            id: $secret->id,
            secretHint: $secret->secretHint,
            secret: $secret->secret,
            lastUsedAt: $secret->lastUsedAt,
            createdAt: $secret->createdAt,
            updatedAt: $secret->updatedAt,
        );
    }
}
