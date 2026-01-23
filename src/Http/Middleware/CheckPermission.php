<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Exceptions\MissingPermissionException;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new MissingPermissionException($permissions, 'Unauthenticated');
        }

        if (! method_exists($user, 'hasAllWorkOSPermissions')) {
            throw new MissingPermissionException($permissions, 'User model missing HasWorkOSPermissions trait');
        }

        /** @var callable $hasAllWorkOSPermissions */
        $hasAllWorkOSPermissions = [$user, 'hasAllWorkOSPermissions'];
        if (! $hasAllWorkOSPermissions($permissions)) {
            throw new MissingPermissionException($permissions);
        }

        return $next($request);
    }
}
