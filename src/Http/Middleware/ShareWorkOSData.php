<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Auth\SessionManager;

class ShareWorkOSData
{
    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! class_exists(Inertia::class)) {
            return $next($request);
        }

        Inertia::share([
            'auth' => fn () => $this->getAuthData($request),
        ]);

        return $next($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function getAuthData(Request $request): array
    {
        $user = $request->user();
        $session = $this->session->getSession();

        if ($user === null) {
            return [
                'check' => false,
                'user' => null,
                'roles' => [],
                'permissions' => [],
                'organization' => null,
                'impersonating' => false,
            ];
        }

        $workosId = null;
        if (is_object($user) && method_exists($user, 'getWorkOSId')) {
            /** @var mixed $workosId */
            $workosId = $user->getWorkOSId();
        }

        return [
            'check' => true,
            'user' => [
                'id' => $user->getAuthIdentifier(),
                'workos_id' => $workosId,
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ],
            'roles' => $session !== null ? $session->roles : [],
            'permissions' => $session !== null ? $session->permissions : [],
            'organization' => $session?->organizationId,
            'impersonating' => $session?->impersonator !== null,
            'impersonator' => $session?->impersonator,
        ];
    }
}
