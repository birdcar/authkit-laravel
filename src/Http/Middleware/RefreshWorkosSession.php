<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Auth\JwtPayloadDecoder;
use Authkit\Authkit\Auth\RefreshStatus;
use Authkit\Authkit\Auth\SessionRefresher;
use Authkit\Authkit\Contracts\WorkosUser;
use Authkit\Authkit\Events\SessionCookieOversized;
use Authkit\Authkit\Http\LoginRedirect;
use Authkit\Authkit\Http\SessionCookie;
use Authkit\Authkit\Support\AuthkitConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WorkOS\SessionManager;

final class RefreshWorkosSession
{
    public function __construct(private readonly SessionRefresher $refresher) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('workos')->user();
        $sealed = $request->cookie(SessionCookie::name());
        $newCookie = null;

        if (! is_string($sealed) || $sealed === '') {
            return $next($request);
        }

        if ($user instanceof WorkosUser && $user->claims() !== null) {
            $newCookie = $this->refreshBeforeExpiry($sealed, $user->claims());

            if ($newCookie instanceof Response) {
                return $newCookie;
            }
        } elseif ($user === null) {
            // The guard rejects an expired access token outright, so a long-idle
            // tab arrives here as a guest even though the sealed cookie still
            // carries a usable refresh token. Without this the refresh token would
            // never be spent and every idle session would need a full browser
            // round trip to WorkOS.
            $outcome = $this->refreshAfterExpiry($sealed);

            if ($outcome instanceof Response) {
                return $outcome;
            }

            if ($outcome !== null) {
                $newCookie = $outcome;

                // Unlike the pre-expiry path, this request has no valid claims of
                // its own yet, so the guard is re-pointed at the fresh cookie —
                // otherwise the refresh would only benefit the *next* request.
                // Forgetting the memoized guard makes the next Auth::guard() call
                // rebuild it against this (now updated) request.
                $request->cookies->set(SessionCookie::name(), $newCookie);
                Auth::forgetGuards();
            }
        }

        $response = $next($request);

        if ($newCookie !== null && $response instanceof Response) {
            $response->headers->setCookie(SessionCookie::issue($newCookie));
        }

        return $response;
    }

    /**
     * Refresh inside the buffer window before hard expiry. Returning null means
     * "carry on with the claims this request already has", which is safe precisely
     * because they are still cryptographically valid for its whole lifetime.
     */
    private function refreshBeforeExpiry(string $sealed, AccessTokenClaims $claims): string|Response|null
    {
        $threshold = (int) config('authkit.session.refresh_before_seconds', 60);

        if ($claims->secondsUntilExpiry() > $threshold) {
            return null;
        }

        $outcome = $this->refresher->refresh(
            sealedCookie: $sealed,
            sessionId: $claims->sessionId,
            cookiePassword: AuthkitConfig::cookiePassword(),
            clientId: AuthkitConfig::clientId(),
        );

        if ($outcome->status === RefreshStatus::HardExpired && $claims->secondsUntilExpiry() <= 0) {
            return LoginRedirect::make()->withCookie(SessionCookie::forget());
        }

        if ($outcome->status === RefreshStatus::Refreshed && $outcome->sealedCookie !== null) {
            $this->warnIfOversized($outcome->sealedCookie);

            return $outcome->sealedCookie;
        }

        return null;
    }

    /**
     * Refresh a session the guard could not authenticate. Returns the fresh sealed
     * cookie, a redirect when the session is beyond saving, or null when the cookie
     * is not something this package can act on at all.
     */
    private function refreshAfterExpiry(string $sealed): string|Response|null
    {
        $cookiePassword = AuthkitConfig::cookiePassword();

        try {
            // unsealData is an authenticated decrypt with our own cookie password,
            // so a payload that comes out of it is one this application sealed.
            // The session id read here is used only as the single-flight key — the
            // new session itself comes from WorkOS via refresh(), never from these
            // unverified claims.
            $payload = SessionManager::unsealData($sealed, $cookiePassword);
            $accessToken = $payload['access_token'] ?? null;

            if (! is_string($accessToken)) {
                return null;
            }

            $claims = AccessTokenClaims::fromPayload(JwtPayloadDecoder::decode($accessToken));
        } catch (Throwable) {
            // Not a cookie we sealed, or not one we can read: leave it alone and
            // let the request proceed as a guest.
            return null;
        }

        // Expiry is only one of the reasons the guard yields null — it also does so
        // for an orphaned session (no local users row) and for a token rejected by
        // JwtClaimsValidator. Refreshing those would spend and rotate a refresh
        // token on every single request while still resolving to a guest, and would
        // ask WorkOS for a fresh session on the strength of a token we just
        // rejected as belonging to another application.
        if ($claims->secondsUntilExpiry() > 0) {
            return null;
        }

        $outcome = $this->refresher->refresh(
            sealedCookie: $sealed,
            sessionId: $claims->sessionId,
            cookiePassword: $cookiePassword,
            clientId: AuthkitConfig::clientId(),
        );

        if ($outcome->status === RefreshStatus::Refreshed && $outcome->sealedCookie !== null) {
            $this->warnIfOversized($outcome->sealedCookie);

            return $outcome->sealedCookie;
        }

        // ProceedWithExisting is not a dead session — another request holds the
        // lock, or the cache store cannot lock at all. Clearing the cookie here
        // would race the winner's freshly sealed one and log the user out despite a
        // successful refresh; proceed as a guest and let the next request pick up
        // the shared result.
        if ($outcome->status === RefreshStatus::ProceedWithExisting) {
            return null;
        }

        // Genuinely beyond saving: send the browser back to the start rather than
        // serving the request against a dead session.
        return LoginRedirect::make()->withCookie(SessionCookie::forget());
    }

    private function warnIfOversized(string $sealedCookie): void
    {
        $max = (int) config('authkit.session.max_cookie_bytes', 3800);
        $bytes = strlen($sealedCookie);

        if ($bytes > $max) {
            event(new SessionCookieOversized($bytes, $max));
        }
    }
}
