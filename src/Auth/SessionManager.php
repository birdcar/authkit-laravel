<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Contracts\Session\Session;
use SensitiveParameter;
use WorkOS\AuthKit\Facades\WorkOS;

/**
 * Manages WorkOS sessions using Laravel's session storage.
 *
 * This driver stores WorkOS session data (access token, refresh token, etc.)
 * in Laravel's session. Use this when you need full control over session
 * storage or when cookie-based sessions are not suitable.
 */
class SessionManager implements SessionManagerInterface
{
    private const SESSION_KEY = 'workos_session';

    public function __construct(
        private readonly Session $store,
    ) {}

    public function getSession(): ?WorkOSSession
    {
        /** @var array<string, mixed>|null $data */
        $data = $this->store->get(self::SESSION_KEY);

        return $data ? WorkOSSession::fromArray($data) : null;
    }

    public function getValidSession(): ?WorkOSSession
    {
        $session = $this->getSession();

        if (! $session) {
            return null;
        }

        if ($session->isExpired()) {
            return $this->attemptRefresh($session);
        }

        /** @var int $bufferMinutes */
        $bufferMinutes = config('workos.session.refresh_buffer_minutes', 5);
        if ($session->needsRefresh($bufferMinutes)) {
            return $this->attemptRefresh($session) ?? $session;
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $authResponse
     */
    public function store(#[SensitiveParameter] array $authResponse): WorkOSSession
    {
        $session = WorkOSSession::fromAuthResponse($authResponse);
        $this->store->put(self::SESSION_KEY, $session->toArray());

        return $session;
    }

    public function destroy(): void
    {
        $this->store->forget(self::SESSION_KEY);
    }

    public function isImpersonating(): bool
    {
        return $this->getSession()?->impersonator !== null;
    }

    public function getOrganizationId(): ?string
    {
        return $this->getSession()?->organizationId;
    }

    public function setOrganizationId(string $organizationId): void
    {
        $session = $this->getSession();
        if (! $session) {
            return;
        }

        $data = $session->toArray();
        $data['organization_id'] = $organizationId;
        $this->store->put(self::SESSION_KEY, $data);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->getSession()?->hasPermission($permission) ?? false;
    }

    public function hasRole(string $role): bool
    {
        return $this->getSession()?->hasRole($role) ?? false;
    }

    private function attemptRefresh(WorkOSSession $session): ?WorkOSSession
    {
        if (! $session->refreshToken) {
            $this->destroy();

            return null;
        }

        try {
            /** @var string $clientId */
            $clientId = config('workos.client_id');
            $response = WorkOS::userManagement()->authenticateWithRefreshToken(
                clientId: $clientId,
                refreshToken: $session->refreshToken,
            );

            // Use the raw property which contains the original API response array
            // (array) cast doesn't properly convert nested resource objects
            return $this->store($response->raw);
        } catch (\Exception) {
            $this->destroy();

            return null;
        }
    }
}
