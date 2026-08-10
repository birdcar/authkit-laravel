<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Http\LoginRedirect;
use Authkit\Authkit\Http\Requests\AuthKitLoginRequest;
use Authkit\Authkit\Http\SessionCookie;
use Authkit\Authkit\Support\AuthkitConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `authkit.switch-org`: refreshes the sealed session scoped to the target
 * organization via the SDK's own organizationId hint. When refresh can't
 * satisfy the switch (no active membership, an already-rotated refresh token,
 * any other rejection), falls back to a full re-authorize redirect carrying
 * the same org hint — reusing the login request's PKCE handshake rather than
 * duplicating it.
 *
 * Deliberately bypasses the SessionRefresher single-flight lock: that lock
 * coordinates automatic near-expiry refreshes racing each other; an explicit,
 * user-initiated switch is a single request with nothing to coordinate
 * against, and a lost race lands in the same re-authorize fallback anyway.
 */
final class SwitchOrganizationController extends Controller
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function __invoke(Request $request, string $organizationId, AuthKitLoginRequest $loginRequest): RedirectResponse
    {
        $sealed = $request->cookie(SessionCookie::name());

        if (! is_string($sealed) || $sealed === '') {
            // LoginRedirect guards the named route, which does not exist when
            // authkit.routes.enabled=false — same helper as every other
            // back-to-login redirect in the package.
            return LoginRedirect::make();
        }

        $result = $this->clients->client()->sessionManager()->refresh(
            sessionData: $sealed,
            cookiePassword: AuthkitConfig::cookiePassword(),
            clientId: AuthkitConfig::clientId(),
            organizationId: $organizationId,
        );

        $sealedSession = $result['sealed_session'] ?? null;

        if (($result['authenticated'] ?? false) !== true || ! is_string($sealedSession) || $sealedSession === '') {
            return $loginRequest->redirect(
                intendedUrl: $this->returnTo($request),
                organizationId: $organizationId,
            );
        }

        return redirect()
            ->to($this->returnTo($request) ?? '/')
            ->withCookie(SessionCookie::issue($sealedSession));
    }

    /**
     * Constrained to app-relative paths: return_to is request input, and
     * echoing an absolute URL into a redirect would be an open-redirect
     * primitive on an authenticated POST route.
     */
    private function returnTo(Request $request): ?string
    {
        $returnTo = $request->input('return_to');

        if (! is_string($returnTo) || $returnTo === '') {
            return null;
        }

        return str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')
            ? $returnTo
            : null;
    }
}
