<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Illuminate\Database\Eloquent\Model;

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
}
