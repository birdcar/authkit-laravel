<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\FGA\FGAService;

class CheckFGAAccess
{
    public function __construct(private readonly FGAService $fga) {}

    public function handle(Request $request, Closure $next, string $permission, string $resourceType, string $resourceId): Response
    {
        if (str_starts_with($resourceId, ':')) {
            $paramName = substr($resourceId, 1);
            $routeParam = $request->route($paramName);
            $resourceId = is_string($routeParam) ? $routeParam : '';
        }

        if (! $this->fga->checkForCurrentUser($permission, $resourceType, $resourceId)) {
            abort(403, "Access denied: [{$permission}] on [{$resourceType}:{$resourceId}].");
        }

        return $next($request);
    }
}
