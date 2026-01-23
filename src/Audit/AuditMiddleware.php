<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Audit\Contracts\Auditable;

class AuditMiddleware
{
    public function __construct(
        private readonly AuditLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->isSuccessful()) {
            $route = $request->route();

            $this->logger->log(
                action: $action ?? $this->inferAction($request, $route),
                targets: $this->extractTargets($route),
                metadata: [
                    'route' => $this->getRouteName($route),
                    'method' => $request->method(),
                    'path' => $request->path(),
                ]
            );
        }

        return $response;
    }

    private function inferAction(Request $request, mixed $route): string
    {
        $resource = $route instanceof Route ? ($route->getName() ?? 'resource') : 'resource';

        return match ($request->method()) {
            'GET' => "{$resource}.read",
            'POST' => "{$resource}.create",
            'PUT', 'PATCH' => "{$resource}.update",
            'DELETE' => "{$resource}.delete",
            default => "{$resource}.access",
        };
    }

    private function getRouteName(mixed $route): ?string
    {
        if ($route instanceof Route) {
            return $route->getName();
        }

        return null;
    }

    /**
     * @return array<int, Auditable|array{type: string, id: string}>
     */
    private function extractTargets(mixed $route): array
    {
        $targets = [];

        if (! $route instanceof Route) {
            return $targets;
        }

        /** @var array<string, mixed> $parameters */
        $parameters = $route->parameters();

        foreach ($parameters as $key => $value) {
            if (is_object($value) && $value instanceof Auditable) {
                $targets[] = $value;
            } elseif (is_string($value) || is_numeric($value)) {
                $targets[] = ['type' => $key, 'id' => (string) $value];
            }
        }

        return $targets;
    }
}
