<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Auth\SessionManagerInterface;

class DetectImpersonation
{
    public function __construct(
        private readonly SessionManagerInterface $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->session->isImpersonating()) {
            $request->attributes->set('workos_impersonating', true);
            $request->attributes->set('workos_impersonator', $this->session->getSession()?->impersonator);
        }

        return $next($request);
    }
}
