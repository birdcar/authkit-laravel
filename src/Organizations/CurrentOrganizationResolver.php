<?php

declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Request-scoped resolver: org_id claim on the current workos guard session →
 * local org model row via workos_id. Memoized so repeated calls within one
 * request cost exactly one database query.
 *
 * Named caveat (not solved here): bound as a container singleton, so under
 * Octane-style long-running workers the app must register this class for
 * request-lifecycle reset via Octane's own hooks.
 */
final class CurrentOrganizationResolver
{
    private bool $resolved = false;

    private ?Model $organization = null;

    public function resolve(): ?Model
    {
        if ($this->resolved) {
            return $this->organization;
        }

        $this->resolved = true;

        $organizationId = $this->currentOrganizationIdFromClaims();

        if ($organizationId === null) {
            return $this->organization = null;
        }

        $organizationModel = config('authkit.organization.model');

        if (! is_string($organizationModel) || $organizationModel === ''
            || ! class_exists($organizationModel) || ! is_a($organizationModel, Model::class, true)) {
            return $this->organization = null;
        }

        return $this->organization = $organizationModel::query()
            ->where('workos_id', $organizationId)
            ->first();
    }

    private function currentOrganizationIdFromClaims(): ?string
    {
        $guard = Auth::guard('workos');

        // Duck-typed rather than instanceof HasAccessTokenClaims: that
        // interface is a Phase 5 deliverable, and an instanceof against a
        // class name that may not exist yet risks autoload surprises — a
        // method-exists check is a safe superset for a single-method contract
        // and keeps working unchanged once the interface lands.
        if (! method_exists($guard, 'accessTokenClaims')) {
            return null;
        }

        $claims = $guard->accessTokenClaims();

        if (! is_array($claims)) {
            return null;
        }

        $organizationId = $claims['org_id'] ?? null;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
