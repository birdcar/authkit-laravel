<?php

declare(strict_types=1);

namespace Authkit\Authkit\Data;

use DateTimeImmutable;

/**
 * The result of creating an API key — the ONLY type in this package that
 * carries the raw key value. WorkOS returns the secret exactly once, in the
 * create response; it is never retrievable again, from any endpoint. Persist
 * or display $value immediately, or lose it.
 */
final readonly class ApiKeyCreated
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $value,
        public array $permissions,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
