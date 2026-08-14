<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Authkit\Authkit\Http\LoginRedirect;
use Authkit\Authkit\Http\Requests\AuthKitLoginRequest;
use Authkit\Authkit\Organizations\OrganizationSwitcher;
use Authkit\Authkit\Organizations\OrganizationSwitchResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `authkit.switch-org`: the route face of {@see OrganizationSwitcher}, which
 * owns the refresh-scoped-to-org mechanics (and queues the new session
 * cookie). This controller adds only the route concerns: where to land on
 * success, and the full re-authorize redirect — reusing the login request's
 * PKCE handshake rather than duplicating it — when refresh can't satisfy the
 * switch (no active membership, an already-rotated refresh token, any other
 * rejection).
 */
final class SwitchOrganizationController extends Controller
{
    public function __construct(private readonly OrganizationSwitcher $switcher) {}

    public function __invoke(Request $request, string $organizationId, AuthKitLoginRequest $loginRequest): RedirectResponse
    {
        return match ($this->switcher->switch($organizationId)) {
            // LoginRedirect guards the named route, which does not exist when
            // authkit.routes.enabled=false — same helper as every other
            // back-to-login redirect in the package.
            OrganizationSwitchResult::NoSession => LoginRedirect::make(),
            OrganizationSwitchResult::Refused => $loginRequest->redirect(
                intendedUrl: $this->returnTo($request),
                organizationId: $organizationId,
            ),
            OrganizationSwitchResult::Switched => redirect()->to($this->returnTo($request) ?? '/'),
        };
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
