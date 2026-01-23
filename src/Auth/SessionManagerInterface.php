<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

interface SessionManagerInterface
{
    public function getSession(): ?WorkOSSession;

    public function getValidSession(): ?WorkOSSession;

    /**
     * @param  array<string, mixed>  $authResponse
     */
    public function store(array $authResponse): WorkOSSession;

    public function destroy(): void;

    public function isImpersonating(): bool;

    public function getOrganizationId(): ?string;

    public function setOrganizationId(string $organizationId): void;

    public function hasPermission(string $permission): bool;

    public function hasRole(string $role): bool;
}
