<?php

declare(strict_types=1);

namespace Authkit\Authkit\Facades;

use Authkit\Authkit\Vault\VaultManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \WorkOS\Resource\ObjectMetadata set(array<string, string> $keyContext, string $name, string $value)
 * @method static \WorkOS\Resource\VaultObject get(string $name)
 * @method static \WorkOS\Resource\VaultObject find(string $id)
 * @method static \WorkOS\Resource\ObjectWithoutValue update(string $id, string $value, ?string $versionCheck = null)
 * @method static void delete(string $id, ?string $versionCheck = null)
 * @method static \WorkOS\Resource\ObjectWithoutValue metadata(string $id)
 * @method static \WorkOS\Resource\VersionListResponse versions(string $id)
 * @method static \WorkOS\PaginatedResponse list(?int $limit = null, ?string $before = null, ?string $after = null, ?\WorkOS\Resource\VaultOrder $order = null, ?string $search = null, ?\DateTimeImmutable $updatedAfter = null)
 *
 * @see VaultManager
 */
class Vault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
