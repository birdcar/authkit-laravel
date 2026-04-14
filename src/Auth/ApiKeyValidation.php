<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

readonly class ApiKeyValidation
{
    /**
     * @param  array<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $name,
        public string $obfuscatedValue,
        public array $permissions,
        public ?string $organizationId,
        public ?string $lastUsedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        /** @var array<string, mixed> $owner */
        $owner = $data['owner'] ?? [];

        $organizationId = ($owner['type'] ?? null) === 'organization'
            ? (string) $owner['id']
            : null;

        return new self(
            id: (string) $data['id'],
            ownerType: (string) ($owner['type'] ?? ''),
            ownerId: (string) ($owner['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            obfuscatedValue: (string) ($data['obfuscated_value'] ?? ''),
            permissions: isset($data['permissions']) && is_array($data['permissions'])
                ? $data['permissions']
                : [],
            organizationId: $organizationId,
            lastUsedAt: isset($data['last_used_at']) ? (string) $data['last_used_at'] : null,
            createdAt: (string) ($data['created_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }
}
