<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\FGA;

readonly class FGAResource
{
    public function __construct(
        public string $resourceType,
        public string $resourceId,
    ) {}

    public function toString(): string
    {
        return "{$this->resourceType}:{$this->resourceId}";
    }

    public static function fromString(string $resource): self
    {
        [$type, $id] = explode(':', $resource, 2);

        return new self(resourceType: $type, resourceId: $id);
    }
}
