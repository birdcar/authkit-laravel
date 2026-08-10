<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Contracts\WorkosUser;
use Authkit\Authkit\Events\Impersonating;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Throwable;
use WorkOS\SessionManager;

final class WorkosGuard implements Guard, HasAccessTokenClaims
{
    private bool $resolved = false;

    private ?Authenticatable $user = null;

    /** @var array<string, mixed>|null */
    private ?array $claimsPayload = null;

    public function __construct(
        private readonly UserProvider $provider,
        private readonly SessionManager $sessionManager,
        private readonly JwtClaimsValidator $validator,
        private Request $request,
        private readonly string $cookieName,
        private readonly string $cookiePassword,
        private readonly string $clientId,
        private readonly string $baseUrl,
    ) {}

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $sealed = $this->request->cookie($this->cookieName);

        if (! is_string($sealed) || $sealed === '') {
            return $this->user = null;
        }

        // The SDK is the only sanctioned signature/expiry check. Everything below
        // layers claims the SDK's return array omits — it never re-verifies.
        $result = $this->sessionManager->authenticate($sealed, $this->cookiePassword, $this->clientId, $this->baseUrl);

        if (($result['authenticated'] ?? false) !== true) {
            return $this->user = null;
        }

        try {
            $raw = SessionManager::unsealData($sealed, $this->cookiePassword);
            $accessToken = $raw['access_token'] ?? null;

            if (! is_string($accessToken)) {
                return $this->user = null;
            }

            $payload = JwtPayloadDecoder::decode($accessToken);
            $claims = AccessTokenClaims::fromPayload($payload);
        } catch (Throwable) {
            return $this->user = null;
        }

        if (! $this->validator->validate($claims)) {
            return $this->user = null;
        }

        $user = $this->provider->retrieveByCredentials(['workos_id' => $claims->sub]);

        // Orphaned session (local row deleted, DB reset, replica lag) reads as
        // "guest", identical to having no cookie at all, rather than as an error.
        if (! $user instanceof Authenticatable) {
            return $this->user = null;
        }

        $impersonator = $result['impersonator'] ?? null;

        // A model without the HasWorkosUser trait still authenticates; it just
        // cannot carry per-request claims.
        if ($user instanceof WorkosUser) {
            $user->setWorkosClaims($claims);
            $user->setWorkosImpersonator(is_array($impersonator) ? $impersonator : null);
        }

        if ($claims->isImpersonated()) {
            event(new Impersonating($user, (string) $claims->actorId, is_array($impersonator) ? $impersonator : null));
        }

        $this->claimsPayload = $payload;

        return $this->user = $user;
    }

    /**
     * The decoded, signature-verified access-token claims for the current
     * request, or null if there is no authenticated session. Thin accessor
     * over data user() already produced — no new unsealing, no new HTTP.
     * Fulfills the HasAccessTokenClaims contract that ClaimsGateHook and
     * FgaChecker check via instanceof; CurrentOrganizationResolver's older
     * method_exists duck-typing keeps working unchanged.
     *
     * @return array<string, mixed>|null
     */
    public function accessTokenClaims(): ?array
    {
        return $this->user() !== null ? $this->claimsPayload : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        // This guard authenticates a sealed cookie, never raw credentials.
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->resolved = true;
        $this->user = $user;

        return $this;
    }

    /**
     * Laravel memoizes guard instances in AuthManager for the lifetime of the
     * container, and AuthManager::callCustomCreator() — unlike its session and
     * token drivers — never rebinds the request. Without this (plus the matching
     * `refresh('request', ...)` in the service provider) a second request in the
     * same process would resolve against the first request's cookies.
     */
    public function setRequest(Request $request): static
    {
        $this->request = $request;
        $this->resolved = false;
        $this->user = null;
        $this->claimsPayload = null;

        return $this;
    }
}
