<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use WorkOS\AuthKit\Auth\SessionManagerInterface;
use WorkOS\AuthKit\Events\OrganizationSwitched;
use WorkOS\AuthKit\Models\Organization;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasOrganization
{
    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        /** @var class-string<Organization> $organizationModel */
        $organizationModel = config('workos.organization_model', Organization::class);

        return $this->belongsToMany(
            $organizationModel,
            'organization_user',
            'user_id',
            'organization_id'
        )->withPivot('role')->withTimestamps();
    }

    public function currentOrganization(): ?Organization
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
