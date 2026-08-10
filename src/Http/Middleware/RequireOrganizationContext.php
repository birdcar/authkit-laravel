<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `authkit.org` alias: guards a route on "there must be a current org,"
 * with a configurable outcome (403 or redirect) when there isn't one. A thin
 * consumer of CurrentOrganizationResolver, not the thing that populates it —
 * $request->organization() works on any authenticated route without this.
 */
final class RequireOrganizationContext
{
    public function __construct(private readonly CurrentOrganizationResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->resolver->resolve() !== null) {
            return $next($request);
        }

        return $this->handleMissing();
    }

    private function handleMissing(): Response
    {
        $mode = config('authkit.organization.middleware.on_missing', 'abort');

        if ($mode === 'redirect') {
            $route = config('authkit.organization.middleware.redirect_route');

            // Fail fast and name the config key rather than hiding a real
            // configuration mistake behind a plausible-looking 403.
            abort_if(
                ! is_string($route) || $route === '',
                500,
                'authkit.organization.middleware.redirect_route must be set when authkit.organization.middleware.on_missing is "redirect".',
            );

            return redirect()->route($route);
        }

        abort(403, 'This action requires an organization context.');
    }
}
