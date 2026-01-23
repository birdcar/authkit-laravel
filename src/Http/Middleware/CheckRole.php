<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Exceptions\MissingRoleException;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new MissingRoleException($roles, 'Unauthenticated');
        }

        if (! method_exists($user, 'hasAnyWorkOSRole')) {
            throw new MissingRoleException($roles, 'User model missing HasWorkOSPermissions trait');
        }

        /** @var callable $hasAnyWorkOSRole */
        $hasAnyWorkOSRole = [$user, 'hasAnyWorkOSRole'];
        if (! $hasAnyWorkOSRole($roles)) {
            throw new MissingRoleException($roles);
        }

        return $next($request);
    }
}
