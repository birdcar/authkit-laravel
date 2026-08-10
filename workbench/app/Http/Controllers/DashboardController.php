<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

/**
 * Post-login hub: the single JSON page a human quickstart trial looks at to
 * confirm "login worked, with orgs and RBAC live". Surfaces the logged-in
 * user, the claims-resolved current organization, the raw claim set the
 * zero-HTTP authorization story rides on, and an index of every demo route.
 *
 * JSON, not a Blade view — the package is headless plumbing by design, and a
 * JSON shape keeps every acceptance assertion assertJson()-testable.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $guard = Auth::guard('workos');
        $claims = $guard instanceof HasAccessTokenClaims ? $guard->accessTokenClaims() : null;

        // Same resolver as the $request->organization() macro — the facade
        // accessor is used here because workbench/app is static-analysed and
        // the macro is invisible to PHPStan.
        $organization = Authkit::currentOrganization();

        $demoRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => is_string($name) && str_starts_with($name, 'demo.'))
            ->sort()
            ->values()
            ->all();

        return response()->json([
            'user' => [
                'id' => $user->getKey(),
                'email' => $user->getAttribute('email'),
                'workos_id' => $user->getAttribute('workos_id'),
            ],
            'organization' => $organization === null ? null : [
                'id' => $organization->getKey(),
                'name' => $organization->getAttribute('name'),
                'workos_id' => $organization->getAttribute('workos_id'),
            ],
            'claims' => [
                'role' => $claims['role'] ?? null,
                'permissions' => $claims['permissions'] ?? [],
                'feature_flags' => $claims['feature_flags'] ?? [],
            ],
            'demo_routes' => $demoRoutes,
        ]);
    }
}
