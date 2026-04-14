<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;

class CheckFeatureFlag
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        foreach ($flags as $flag) {
            if (! $this->flags->isEnabled($flag)) {
                abort(403, "Feature [{$flag}] is not enabled.");
            }
        }

        return $next($request);
    }
}
