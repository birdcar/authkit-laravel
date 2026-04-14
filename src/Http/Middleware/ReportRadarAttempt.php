<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Services\RadarService;

class ReportRadarAttempt
{
    public function handle(Request $request, Closure $next, string $action = 'sign-in'): Response
    {
        if (! config('workos.features.radar', false)) {
            return $next($request);
        }

        try {
            $verdict = app(RadarService::class)->createAttempt([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ?? '',
                'email' => $request->input('email', ''),
                'auth_method' => 'Password',
                'action' => $action,
            ]);

            if (($verdict['verdict'] ?? 'allow') === 'block') {
                return response()->json(['message' => 'Access denied.'], 403);
            }

            $request->merge(['_radar_verdict' => $verdict]);
        } catch (\Exception) {
            // Radar failure must not block auth flow
        }

        return $next($request);
    }
}
