<?php

declare(strict_types=1);

namespace Authkit\Authkit\Policies;

use Authkit\Authkit\Concerns\HasWorkosResource;
use Authkit\Authkit\Contracts\WorkosResource;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Abstract policy base routing every ability to a WorkOS FGA check. Undefined
 * ability methods fall through to __call — Laravel's Gate resolves policy
 * callables via is_callable(), which is true for any method name on a class
 * defining __call, so an empty subclass authorizes every ability generically.
 *
 * Class-string checks ($user->can('create', Project::class)) always deny:
 * the Gate strips the class-string argument before calling the policy, and
 * FGA cannot authorize a resource that doesn't exist yet — pair create-style
 * abilities with an RBAC permission claim instead (spec-phase-5 Failure
 * Mode 7).
 *
 * The current user is deliberately not forwarded to Authkit::check(): the
 * $user the Gate passes IS the authenticated guard user FgaChecker already
 * resolves, so forwarding it could only introduce inconsistency.
 */
abstract class WorkosResourcePolicy
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $ability, array $arguments): bool
    {
        $resource = $arguments[1] ?? null;

        if (! $resource instanceof Model) {
            return false;
        }

        if (! $resource instanceof WorkosResource) {
            throw new LogicException(sprintf(
                '%s must implement %s (use the %s trait) to be authorized by %s.',
                $resource::class,
                WorkosResource::class,
                HasWorkosResource::class,
                static::class,
            ));
        }

        return Authkit::check(
            permissionSlug: $this->permissionSlugFor($ability),
            resourceExternalId: $resource->workosResourceExternalId(),
            resourceTypeSlug: $resource->workosResourceType(),
        );
    }

    /**
     * Override to map ability names to different permission slugs.
     * Default: the ability name IS the permission slug (e.g. the `view`
     * ability checks the `view` permission slug on the resource).
     */
    protected function permissionSlugFor(string $ability): string
    {
        return $ability;
    }
}
