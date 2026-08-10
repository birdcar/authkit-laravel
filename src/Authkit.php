<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\PermissionManager;
use Authkit\Authkit\Authorization\ResourceManager;
use Authkit\Authkit\Authorization\RoleManager;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use WorkOS\RequestOptions;

class Authkit
{
    /**
     * The app's local org model row for the current session's org_id claim,
     * or null when there is no current organization. Same source of truth as
     * $request->organization() — both delegate to one resolver instance.
     */
    public function currentOrganization(): ?Model
    {
        return app(CurrentOrganizationResolver::class)->resolve();
    }

    // Authorization accessors are container-resolved rather than
    // constructor-injected: this class is touched by several phases, and
    // additive app() resolution avoids cross-phase constructor collisions.

    public function roles(): RoleManager
    {
        return app(RoleManager::class);
    }

    public function permissions(): PermissionManager
    {
        return app(PermissionManager::class);
    }

    public function resources(): ResourceManager
    {
        return app(ResourceManager::class);
    }

    /**
     * Explicit FGA check via the WorkOS Check API — one network call per
     * invocation, uncached by contract decision. When
     * $organizationMembershipId is omitted, it is resolved from the given (or
     * current) user and organization via the bound membership resolver, and
     * MembershipNotResolvedException is thrown when that fails — a projection
     * that hasn't synced is not a deny.
     */
    public function check(
        string $permissionSlug,
        string $resourceExternalId,
        string $resourceTypeSlug,
        ?string $organizationMembershipId = null,
        ?Authenticatable $user = null,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): bool {
        return app(FgaChecker::class)->check(
            $permissionSlug,
            $resourceExternalId,
            $resourceTypeSlug,
            $organizationMembershipId,
            $user,
            $organizationId,
            $options,
        );
    }
}
