<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\WorkosClientManager;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuthorizationPermission;
use WorkOS\Resource\Permission;

/**
 * Environment-scoped permission CRUD, resolved via Authkit::permissions().
 * There is no org-scoped permission CRUD in the SDK — permissions are
 * environment-global, optionally tagged with a resourceTypeSlug.
 *
 * Mutating methods accept the SDK's own ?RequestOptions so a retried call can
 * carry an idempotency key (spec-phase-5 Failure Mode 12).
 */
final class PermissionManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * @return array<int, AuthorizationPermission>
     */
    public function all(): array
    {
        $permissions = [];

        foreach ($this->clients->client()->authorization()->listPermissions()->data as $permission) {
            if ($permission instanceof AuthorizationPermission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public function create(string $slug, string $name, ?string $description = null, ?string $resourceTypeSlug = null, ?RequestOptions $options = null): Permission
    {
        return $this->clients->client()->authorization()->createPermission($slug, $name, $description, $resourceTypeSlug, $options);
    }

    public function get(string $slug): AuthorizationPermission
    {
        return $this->clients->client()->authorization()->getPermission($slug);
    }

    public function update(string $slug, ?string $name = null, ?string $description = null, ?RequestOptions $options = null): AuthorizationPermission
    {
        return $this->clients->client()->authorization()->updatePermission($slug, $name, $description, $options);
    }

    public function delete(string $slug, ?RequestOptions $options = null): void
    {
        $this->clients->client()->authorization()->deletePermission($slug, $options);
    }
}
