<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

use WorkOS\AuthKit\Auth\WorkOSSession;

trait HasWorkOSPermissions
{
    protected ?WorkOSSession $workosSession = null;

    public function setWorkOSSession(WorkOSSession $session): void
    {
        $this->workosSession = $session;
    }

    public function getWorkOSSession(): ?WorkOSSession
    {
        return $this->workosSession;
    }

    public function hasWorkOSRole(string $role): bool
    {
        return in_array($role, $this->workosSession?->roles ?? [], true);
    }

    public function hasWorkOSPermission(string $permission): bool
    {
        return in_array($permission, $this->workosSession?->permissions ?? [], true);
    }

    public function hasAnyWorkOSRole(array $roles): bool
    {
        return ! empty(array_intersect($roles, $this->workosSession?->roles ?? []));
    }

    public function hasAllWorkOSPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->workosSession?->permissions ?? []));
    }

    public function currentOrganizationId(): ?string
    {
        return $this->workosSession?->organizationId;
    }

    public function isImpersonating(): bool
    {
        return $this->workosSession?->impersonator !== null;
    }

    public function impersonator(): ?array
    {
        return $this->workosSession?->impersonator;
    }
}
