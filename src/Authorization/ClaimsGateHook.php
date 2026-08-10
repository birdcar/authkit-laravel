<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * RBAC's Gate::before hook: grants an ability when it matches a permission or
 * role claim the workos guard already decoded and verified while
 * authenticating the request — zero HTTP per check.
 *
 * The load-bearing constraint: this hook returns true or null, NEVER false.
 * Gate::raw() treats any non-null before-result as final and skips every
 * registered policy, so a false here would deny every ability for every
 * authenticated user, globally — a claims mismatch must fall through (null)
 * so policies still run. Never add an `else { return false; }` branch.
 */
final class ClaimsGateHook
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __invoke(?Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        $guard = Auth::guard('workos');

        if (! $guard instanceof HasAccessTokenClaims) {
            return null; // the guard hasn't wired claims — never deny, let policies run
        }

        $claims = $guard->accessTokenClaims();

        if ($claims === null) {
            return null; // unauthenticated — nothing to short-circuit
        }

        if (in_array($ability, $this->stringValues($claims, 'permissions'), true)) {
            return true;
        }

        if (in_array($ability, $this->stringValues($claims, 'roles'), true)) {
            return true;
        }

        // Singular fallback, checked defensively alongside the plural claim:
        // the token audit (Phase 1 Open Item) hasn't confirmed which shape
        // AuthKit tokens carry by default.
        $role = $claims['role'] ?? null;

        if (is_string($role) && $role === $ability) {
            return true;
        }

        return null; // NEVER false — see class docblock
    }

    /**
     * The claim's value as a list of strings, tolerating absent or
     * garbage-shaped claims (this hook must never fatal on a token shape).
     *
     * @param  array<string, mixed>  $claims
     * @return array<int, string>
     */
    private function stringValues(array $claims, string $key): array
    {
        $values = $claims[$key] ?? null;

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }
}
