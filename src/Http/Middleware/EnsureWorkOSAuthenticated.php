<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Auth\SessionManagerInterface;

class EnsureWorkOSAuthenticated
{
    public function __construct(
        private readonly SessionManagerInterface $session,
    ) {}

    public function handle(Request $request, Closure $next, ?string $redirectTo = null): Response
    {
        $session = $this->session->getValidSession();

        if (! $session) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest($redirectTo ?? route('login'));
        }

        return $next($request);
    }
}
