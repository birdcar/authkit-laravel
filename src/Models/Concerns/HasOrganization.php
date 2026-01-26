<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use WorkOS\AuthKit\Auth\SessionManagerInterface;
use WorkOS\AuthKit\Events\OrganizationSwitched;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasOrganization
{
    /**
     * @return BelongsToMany<Model, $this>
     */
    public function organizations(): BelongsToMany
    {
        /** @var class-string<Model>|null $organizationModel */
        $organizationModel = config('workos.organization_model');

        if ($organizationModel === null) {
            throw new \RuntimeException(
                'workos.organization_model is not configured. Run php artisan workos:install to set up.'
            );
        }

        return $this->belongsToMany(
            $organizationModel,
            'organization_memberships',
            'user_id',
            'organization_id'
        )->withPivot('role')->withTimestamps();
    }

    public function currentOrganization(): ?Model
    {
        $orgId = $this->currentOrganizationId();
        if (! $orgId) {
            return null;
        }

        return $this->organizations()->where('workos_id', $orgId)->first();
    }

    public function switchOrganization(string $organizationId): bool
    {
        if (! $this->belongsToOrganization($organizationId)) {
            return false;
        }

        app(SessionManagerInterface::class)->setOrganizationId($organizationId);

        event(new OrganizationSwitched($this, $organizationId));

        return true;
    }

    public function belongsToOrganization(string $organizationId): bool
    {
        return $this->organizations()
            ->where('workos_id', $organizationId)
            ->exists();
    }

    public function organizationRole(string $organizationId): ?string
    {
        $org = $this->organizations()
            ->where('workos_id', $organizationId)
            ->first();

        return $org?->pivot?->role;
    }

    public function hasOrganizationRole(string $organizationId, string $role): bool
    {
        return $this->organizationRole($organizationId) === $role;
    }

    public function hasOrganizationPermission(string $organizationId, string $permission): bool
    {
        // Check from session permissions filtered by org context
        $session = $this->getWorkOSSession();
        if ($session?->organizationId !== $organizationId) {
            return false;
        }

        return in_array($permission, $session->permissions, true);
    }
}
