<?php

declare(strict_types=1);

namespace Authkit\Authkit\Connect\Data;

use DateTimeImmutable;
use WorkOS\Resource\ApplicationCredentialsListItem;

/**
 * A listed client secret: hint only, never the plaintext value — that exists
 * solely on {@see NewConnectApplicationSecret} at creation time.
 */
final readonly class ConnectApplicationSecret
{
    public function __construct(
        public string $id,
        public string $secretHint,
        public ?DateTimeImmutable $lastUsedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function fromSdk(ApplicationCredentialsListItem $secret): self
    {
        return new self(
            id: $secret->id,
            secretHint: $secret->secretHint,
            lastUsedAt: $secret->lastUsedAt,
            createdAt: $secret->createdAt,
            updatedAt: $secret->updatedAt,
        );
    }
}
