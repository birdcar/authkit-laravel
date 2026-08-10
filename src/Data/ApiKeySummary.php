<?php

declare(strict_types=1);

namespace Authkit\Authkit\Data;

use DateTimeImmutable;

/**
 * A listed API key. Structurally incapable of exposing the raw secret — only
 * ApiKeyCreated (the create response) ever carries it; listings get the
 * obfuscated form. This is the raw-value-returned-once contract expressed in
 * the type system, not just in prose.
 */
final readonly class ApiKeySummary
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $obfuscatedValue,
        public array $permissions,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {}
}
