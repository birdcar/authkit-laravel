<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Auth\SessionManager;

class CheckOrganization
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {}

    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthenticated');
        }

        $organizationId = $this->sessionManager->getOrganizationId();

        if (! $organizationId) {
            abort(403, 'No organization selected');
        }

        if (! method_exists($user, 'belongsToOrganization')) {
            abort(403, 'User model does not support organizations');
        }

        /** @var callable $belongsToOrganization */
        $belongsToOrganization = [$user, 'belongsToOrganization'];
        if (! $belongsToOrganization($organizationId)) {
            abort(403, 'You do not belong to this organization');
        }

        if ($role !== null && method_exists($user, 'hasOrganizationRole')) {
            /** @var callable $hasOrganizationRole */
            $hasOrganizationRole = [$user, 'hasOrganizationRole'];
            if (! $hasOrganizationRole($organizationId, $role)) {
                abort(403, "You do not have the required role: {$role}");
            }
        }

        return $next($request);
    }
}
