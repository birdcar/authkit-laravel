<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cookie;
use WorkOS\WorkOS;

class SessionManager
{
    private ?WorkOSSession $cachedSession = null;

    public function __construct(
        private readonly WorkOS $client,
        private readonly string $cookiePassword,
        private readonly string $cookieName = 'wos-session',
    ) {}

    public function getSession(): ?WorkOSSession
    {
        if ($this->cachedSession !== null) {
            return $this->cachedSession;
        }

        $sealedSession = $this->getSealedSession();
        if ($sealedSession === null) {
            return null;
        }

        try {
            $clientId = (string) config('workos.client_id');

            $result = $this->client->sessionManager()->authenticate(
                sessionData: $sealedSession,
                cookiePassword: $this->cookiePassword,
                clientId: $clientId,
            );

            if (! ($result['authenticated'] ?? false)) {
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

        $accessToken = $authResponse['access_token'] ?? null;
        $refreshToken = $authResponse['refresh_token'] ?? null;

        if (is_string($accessToken) && is_string($refreshToken)) {
            $user = isset($authResponse['user']) && is_array($authResponse['user']) ? $authResponse['user'] : null;
            $impersonator = isset($authResponse['impersonator']) && is_array($authResponse['impersonator']) ? $authResponse['impersonator'] : null;

            $sealedSession = \WorkOS\SessionManager::sealSessionFromAuthResponse(
                accessToken: $accessToken,
                refreshToken: $refreshToken,
                cookiePassword: $this->cookiePassword,
                user: $user,
                impersonator: $impersonator,
            );

            $this->storeSealedCookie($sealedSession);
        }

        return WorkOSSession::fromAuthResponse($authResponse);
    }

    public function destroy(): void
    {
        $this->cachedSession = null;
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
        $sealedSession = $this->getSealedSession();
        if ($sealedSession === null) {
            return null;
        }

        try {
            $clientId = (string) config('workos.client_id');

            return $this->client->sessionManager()->getLogoutUrl(
                sessionData: $sealedSession,
                cookiePassword: $this->cookiePassword,
                clientId: $clientId,
                returnTo: $returnTo,
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function getSealedSession(): ?string
    {
        $sealedSession = request()->cookie($this->cookieName);

        if (! $sealedSession || ! is_string($sealedSession)) {
            return null;
        }

        return $sealedSession;
    }

    private function attemptRefresh(): ?WorkOSSession
    {
        $sealedSession = $this->getSealedSession();
        if ($sealedSession === null) {
            return null;
        }

        try {
            $clientId = (string) config('workos.client_id');

            $result = $this->client->sessionManager()->refresh(
                sessionData: $sealedSession,
                cookiePassword: $this->cookiePassword,
                clientId: $clientId,
            );

            if (! ($result['authenticated'] ?? false)) {
                $this->cachedSession = null;

                return null;
            }

            $newSealedSession = $result['sealed_session'] ?? null;
            if (is_string($newSealedSession)) {
                $this->storeSealedCookie($newSealedSession);

                // Re-authenticate with the new sealed session to get full JWT claims
                // (refresh response only contains session_id, user, impersonator)
                $authResult = $this->client->sessionManager()->authenticate(
                    sessionData: $newSealedSession,
                    cookiePassword: $this->cookiePassword,
                    clientId: $clientId,
                );

                if ($authResult['authenticated'] ?? false) {
                    $this->cachedSession = $this->buildWorkOSSession($authResult);

                    return $this->cachedSession;
                }
            }

            // Fallback: build session from refresh response (partial data)
            $this->cachedSession = $this->buildWorkOSSession($result);

            return $this->cachedSession;
        } catch (\Exception) {
            $this->cachedSession = null;

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function buildWorkOSSession(array $result): WorkOSSession
    {
        $roles = $this->extractStringArray($result['roles'] ?? null);
        $permissions = $this->extractStringArray($result['permissions'] ?? null);
        $featureFlags = $this->extractStringArray($result['feature_flags'] ?? null);
        $entitlements = $this->extractStringArray($result['entitlements'] ?? null);

        $userId = '';
        if (isset($result['user']) && is_array($result['user'])) {
            $userId = (string) ($result['user']['id'] ?? '');
        }

        return new WorkOSSession(
            userId: $userId,
            accessToken: (string) ($result['access_token'] ?? ''),
            refreshToken: isset($result['refresh_token']) ? (string) $result['refresh_token'] : null,
            expiresAt: Carbon::now()->addMinutes(
                (int) config('workos.session.access_token_lifetime', 60)
            ),
            sessionId: isset($result['session_id']) ? (string) $result['session_id'] : null,
            roles: $roles,
            permissions: $permissions,
            featureFlags: $featureFlags,
            entitlements: $entitlements,
            organizationId: isset($result['organization_id']) ? (string) $result['organization_id'] : null,
            impersonator: isset($result['impersonator']) && is_array($result['impersonator']) ? $result['impersonator'] : null,
        );
    }

    /**
     * @return array<string>
     */
    private function extractStringArray(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_map(function (mixed $item): string {
            if (is_string($item)) {
                return $item;
            }
            if (is_array($item) && isset($item['slug'])) {
                return (string) $item['slug'];
            }

            return (string) $item;
        }, $data);
    }

    private function storeSealedCookie(string $sealedSession): void
    {
        Cookie::queue(
            $this->cookieName,
            $sealedSession,
            60 * 24 * 30,
            '/',
            config('session.domain'),
            config('session.secure', false),
            true,
        );
    }
}
