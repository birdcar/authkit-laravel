<?php

declare(strict_types=1);

namespace Authkit\Authkit\Data;

use WorkOS\Resource\OrganizationApiKey;
use WorkOS\Resource\OrganizationApiKeyWithValue;
use WorkOS\Resource\UserApiKey;
use WorkOS\Resource\UserApiKeyWithValue;

/**
 * Maps SDK API key resources into the package-owned DTOs, so consumer code
 * never needs a WorkOS\ type hint (the SDK stays invisible past the trait
 * boundary) and the with-value/without-value split stays structural.
 */
final class ApiKeyMapper
{
    public static function fromCreated(UserApiKeyWithValue|OrganizationApiKeyWithValue $resource): ApiKeyCreated
    {
        return new ApiKeyCreated(
            id: $resource->id,
            name: $resource->name,
            value: $resource->value,
            permissions: self::stringPermissions($resource->permissions),
            expiresAt: $resource->expiresAt,
        );
    }

    public static function fromResource(UserApiKey|OrganizationApiKey $resource): ApiKeySummary
    {
        return new ApiKeySummary(
            id: $resource->id,
            name: $resource->name,
            obfuscatedValue: $resource->obfuscatedValue,
            permissions: self::stringPermissions($resource->permissions),
            expiresAt: $resource->expiresAt,
            lastUsedAt: $resource->lastUsedAt,
        );
    }

    /**
     * The SDK types `permissions` as a bare array — normalize to the
     * list<string> the DTOs (and the Gate hook) promise.
     *
     * @param  array<array-key, mixed>  $permissions
     * @return list<string>
     */
    public static function stringPermissions(array $permissions): array
    {
        return array_values(array_filter($permissions, 'is_string'));
    }
}
