<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Support\Facades\Cookie;
use WorkOS\AuthKit\Facades\WorkOS;
use WorkOS\CookieSession;
use WorkOS\Resource\Impersonator;
use WorkOS\Resource\SessionAuthenticationSuccessResponse;
use WorkOS\Session\HaliteSessionEncryption;

class SessionManager
{
    private ?WorkOSSession $cachedSession = null;

    private ?CookieSession $cookieSession = null;

    public function __construct(
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

            if (! $result instanceof SessionAuthenticationSuccessResponse || ! $result->authenticated) {
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

        if ($session->isExpired()) {
            return $this->attemptRefresh();
        }

        return $session;
    }

    /**
     * Seal and store the session cookie after authentication.
     *
     * @param  array<string, mixed>  $authResponse
     */
    public function store(array $authResponse): WorkOSSession
    {
        $this->cachedSession = null;
        $this->cookieSession = null;

        $accessToken = $authResponse['access_token'] ?? null;
        $refreshToken = $authResponse['refresh_token'] ?? null;

        if ($accessToken && $refreshToken) {
            $encryptor = new HaliteSessionEncryption();
            $sealedSession = $encryptor->seal([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
            ], $this->cookiePassword);

            Cookie::queue(
                $this->cookieName,
                $sealedSession,
                60 * 24 * 30, // 30 days
                '/',
                config('session.domain'),
                config('session.secure', false),
                true,
            );
        }

        return WorkOSSession::fromAuthResponse($authResponse);
    }

    public function destroy(): void
    {
        $this->cachedSession = null;
        $this->cookieSession = null;
        Cookie::queue(Cookie::forget($this->cookieName));
    }

    public function isImpersonating(): bool
    {
        return $this->getSession()?->impersonator !== null;
    }

    public function getOrganizationId(): ?string
    {
        return $this->getSession()?->organizationId;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->getSession()?->hasPermission($permission) ?? false;
    }

    public function hasRole(string $role): bool
    {
        return $this->getSession()?->hasRole($role) ?? false;
    }

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

        $sealedSession = request()->cookie($this->cookieName);

        if (! $sealedSession || ! is_string($sealedSession)) {
            return null;
        }

        $this->cookieSession = WorkOS::userManagement()->loadSealedSession($sealedSession, $this->cookiePassword);

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

            if (! $result instanceof SessionAuthenticationSuccessResponse || ! $result->authenticated) {
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
            expiresAt: \Carbon\Carbon::now()->addHour(),
            sessionId: $result->sessionId,
            roles: $result->user->raw['roles'] ?? [],
            permissions: $result->user->raw['permissions'] ?? [],
            organizationId: $result->organizationId,
            impersonator: $this->impersonatorToArray($result->impersonator),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function impersonatorToArray(?Impersonator $impersonator): ?array
    {
        if ($impersonator === null) {
            return null;
        }

        return [
            'email' => $impersonator->email,
            'reason' => $impersonator->reason,
        ];
    }
}
