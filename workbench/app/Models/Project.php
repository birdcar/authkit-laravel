<?php

namespace Workbench\App\Models;

use Authkit\Authkit\Concerns\HasWorkosResource;
use Authkit\Authkit\Contracts\WorkosResource;
use Illuminate\Database\Eloquent\Model;

class Project extends Model implements WorkosResource
{
    use HasWorkosResource;

    protected $guarded = ['id'];

    public function workosResourceType(): string
    {
        return 'project';
    }

    /**
     * A plain string column storing the WorkOS org ID directly — not an FK to
     * the workbench Organization model — so this fixture stays independent of
     * the organizations projection's timing.
     */
    public function workosResourceOrganizationId(): string
    {
        $organizationId = $this->getAttribute('organization_id');

        return is_string($organizationId) ? $organizationId : '';
    }
}
