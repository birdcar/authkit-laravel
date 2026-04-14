<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Facades\WorkOS;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (! is_string($authHeader) || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $key = substr($authHeader, 7);
        $validation = WorkOS::validateApiKey($key);

        if ($validation === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $request->attributes->set('workos_api_key', $validation);

        if ($validation->organizationId !== null) {
            $request->attributes->set('workos_organization_id', $validation->organizationId);
        }

        return $next($request);
    }
}
