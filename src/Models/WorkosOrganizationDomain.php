<?php

declare(strict_types=1);

namespace Authkit\Authkit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Thin wrapper over the domains projection. Relations live on the side that
 * needs them (HasWorkosOrganization::domains()), not duplicated here.
 */
final class WorkosOrganizationDomain extends Model
{
    protected $table = 'workos_organization_domains';

    protected $guarded = [];
}
