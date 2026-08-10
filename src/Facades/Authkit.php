<?php

declare(strict_types=1);

namespace Authkit\Authkit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static ?\Illuminate\Database\Eloquent\Model currentOrganization()
 * @method static \Authkit\Authkit\Authorization\RoleManager roles()
 * @method static \Authkit\Authkit\Authorization\PermissionManager permissions()
 * @method static \Authkit\Authkit\Authorization\ResourceManager resources()
 * @method static bool check(string $permissionSlug, string $resourceExternalId, string $resourceTypeSlug, ?string $organizationMembershipId = null, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, ?string $organizationId = null, ?\WorkOS\RequestOptions $options = null)
 *
 * @see \Authkit\Authkit\Authkit
 */
class Authkit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Authkit\Authkit\Authkit::class;
    }
}
