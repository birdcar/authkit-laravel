<?php

declare(strict_types=1);

namespace Authkit\Authkit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Thin wrapper over the memberships projection. Deliberately a plain Model,
 * not a Pivot subclass: MembershipProjectionResolver queries it directly as a
 * first-class model, while BelongsToWorkosOrganizations reads role/status via
 * withPivot() — neither use site needs a Pivot class shape.
 */
final class WorkosMembership extends Model
{
    protected $table = 'workos_memberships';

    protected $guarded = [];
}
