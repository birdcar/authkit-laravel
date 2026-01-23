<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Http\Request;
use WorkOS\CookieSession;
use WorkOS\Resource\SessionAuthenticationSuccessResponse;
use WorkOS\UserManagement;

/**
 * Manages WorkOS sessions using the wos-session cookie directly.
 *
 * This is the recommended session driver as it uses WorkOS's cookie
 * as the single source of truth, avoiding session synchronization issues.
 */
class CookieSessionManager implements SessionManagerInterface
{
    private ?WorkOSSession $cachedSession = null;

    private ?CookieSession $cookieSession = null;

    public function __construct(
        private readonly Request $request,
        private readonly string $cookiePassword,
        private readonly string $cookieName = 'wos-session',
    ) {}

    public function getSession(): ?WorkOSSession
    {
        if ($this->cachedSession !== null) {
            return $this->cachedSession;
        }

        $cookieSession = $this->getCookieSession();
        if (! $cookieSession) {
            return null;
        }

        try {
            $result = $cookieSession->authenticate();

            if (! $result->authenticated) {
                return null;
            }

            $this->cachedSession = $this->buildWorkOSSession($result);

            return $this->cachedSession;
        } catch (\Exception) {
            return null;
        }
    }

    public function getValidSession(): ?WorkOSSession
    {
        $session = $this->getSession();

        if (! $session) {
            return null;
        }

        // The CookieSession handles token refresh automatically
        // when we call authenticate(), so we just need to check
        // if the session is still valid
        if ($session->isExpired()) {
            return $this->attemptRefresh();
        }

        return $session;
    }

    /**
     * Store is a no-op for cookie sessions - WorkOS manages the cookie.
     *
     * @param  array<string, mixed>  $authResponse
     */
    public function store(array $authResponse): WorkOSSession
    {
        // Clear cached session so next getSession() reads fresh cookie
        $this->cachedSession = null;
        $this->cookieSession = null;

        // Build and return session from the auth response
        return WorkOSSession::fromAuthResponse($authResponse);
    }

    /**
     * Destroy is a no-op for cookie sessions - logout via WorkOS clears the cookie.
     */
    public function destroy(): void
    {
        $this->cachedSession = null;
        $this->cookieSession = null;
    }

    public function isImpersonating(): bool
    {
        return $this->getSession()?->impersonator !== null;
    }

    public function getOrganizationId(): ?string
    {
        return $this->getSession()?->organizationId;
    }

    /**
     * Organization switching requires re-authentication with WorkOS.
     */
    public function setOrganizationId(string $organizationId): void
    {
        // For cookie-based sessions, org switching requires going through
        // WorkOS login flow with the organization_id parameter
        // This is intentionally a no-op as the caller should redirect to login
    }

    public function hasPermission(string $permission): bool
    {
        return $this->getSession()?->hasPermission($permission) ?? false;
    }

    public function hasRole(string $role): bool
    {
        return $this->getSession()?->hasRole($role) ?? false;
    }

    /**
     * Get the logout URL for the current session.
     */
    public function getLogoutUrl(?string $returnTo = null): ?string
    {
        $cookieSession = $this->getCookieSession();
        if (! $cookieSession) {
            return null;
        }

        try {
            return $cookieSession->getLogoutUrl([
                'returnTo' => $returnTo,
            ]);
        } catch (\Exception) {
            return null;
        }
    }

    private function getCookieSession(): ?CookieSession
    {
        if ($this->cookieSession !== null) {
            return $this->cookieSession;
        }

        $sealedSession = $this->request->cookie($this->cookieName);

        if (! $sealedSession || ! is_string($sealedSession)) {
            return null;
        }

        $userManagement = new UserManagement;
        $this->cookieSession = $userManagement->loadSealedSession($sealedSession, $this->cookiePassword);

        return $this->cookieSession;
    }

    private function attemptRefresh(): ?WorkOSSession
    {
        $cookieSession = $this->getCookieSession();
        if (! $cookieSession) {
            return null;
        }

        try {
            [$result, $newTokens] = $cookieSession->refresh();

            if (! $result->authenticated) {
                $this->cachedSession = null;

                return null;
            }

            $this->cachedSession = $this->buildWorkOSSession($result);

            return $this->cachedSession;
        } catch (\Exception) {
            $this->cachedSession = null;

            return null;
        }
    }

    private function buildWorkOSSession(SessionAuthenticationSuccessResponse $result): WorkOSSession
    {
        return new WorkOSSession(
            userId: $result->user->id ?? '',
            accessToken: $result->accessToken ?? '',
            refreshToken: $result->refreshToken,
            expiresAt: \Carbon\Carbon::now()->addHour(), // Cookie session doesn't expose exact expiry
            sessionId: $result->sessionId,
            roles: $result->user->raw['roles'] ?? [],
            permissions: $result->user->raw['permissions'] ?? [],
            organizationId: $result->organizationId,
            impersonator: $result->impersonator,
        );
    }
}
