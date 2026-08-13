<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Contracts\WorkosUser;
use Authkit\Authkit\Events\Impersonating;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Builds a synthetic `workos` session and installs it on the guard — the
 * offline equivalent of a real login, without a sealed cookie, a JWKS fetch
 * or any SDK call.
 *
 * The claims it produces flow through the exact contracts the real guard
 * uses ({@see AccessTokenClaims}, {@see WorkosUser::setWorkosClaims()} and the
 * raw payload behind `accessTokenClaims()`), so ClaimsGateHook, the Pennant
 * `workos` store, CurrentOrganizationResolver and FgaChecker all behave as
 * they would against a genuine session.
 */
final class FakesWorkosSession
{
    /**
     * Authenticate the `workos` guard as $user for the rest of the test, and
     * return the user so it can be assigned in one line.
     *
     * Recognised keys: `organization` (an Eloquent model carrying a
     * `workos_id`, or a raw `org_...` string), `role`, `roles`,
     * `permissions`, `feature_flags`, `impersonator`, and `sub`. Anything
     * else is merged into the token payload verbatim, so a test needing an
     * exotic or app-specific claim can set one without a new parameter.
     *
     * A claim whose name collides with one of those friendly keys goes under
     * a nested `claims` array, which is applied last and always wins:
     * `Authkit::actingAs($user, ['claims' => ['permissions' => 'raw']])`.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function actingAs(Authenticatable $user, array $claims = []): Authenticatable
    {
        $payload = self::payloadFor($user, $claims);
        $impersonator = self::impersonatorContext($claims);

        if ($user instanceof WorkosUser) {
            $user->setWorkosClaims(AccessTokenClaims::fromPayload($payload));
            $user->setWorkosImpersonator($impersonator);
        }

        self::install(new FakeWorkosGuard($user, $payload));

        // Mirrors the real guard, which announces impersonation at the moment
        // it resolves the principal.
        $actorId = $payload['act']['sub'] ?? null;

        if (is_string($actorId) && $actorId !== '') {
            event(new Impersonating($user, $actorId, $impersonator));
        }

        return $user;
    }

    /**
     * Install an explicitly unauthenticated `workos` guard — the way to prove
     * a route rejects guests without depending on ambient test state.
     */
    public static function actingAsGuest(): FakeWorkosGuard
    {
        return self::install(new FakeWorkosGuard);
    }

    /**
     * Replace the `workos` guard, make it the default, and clear every piece
     * of session-scoped state the container has memoized.
     *
     * Every step matters. `Auth::extend()` alone would be shadowed by the
     * guard AuthManager already memoized. `shouldUse()` is what makes
     * `auth()->user()` and `$request->user()` — what most application code
     * actually calls — see the acting user, matching Laravel's own `actingAs`.
     *
     * The last two clear memoized state that outlives a session swap:
     * CurrentOrganizationResolver caches its lookup for the life of the
     * container, and Pennant's Decorator caches every resolved flag per
     * scope. Without both resets, a second `actingAs()` in one test keeps
     * reporting the first call's organization and the first call's feature
     * flags — a switched session that quietly answers with stale data.
     */
    private static function install(FakeWorkosGuard $guard): FakeWorkosGuard
    {
        Auth::extend('workos', fn (): FakeWorkosGuard => $guard);
        Auth::forgetGuards();
        Auth::shouldUse('workos');

        app()->forgetInstance(CurrentOrganizationResolver::class);

        // Only flushes stores Pennant has already resolved, so this never
        // forces the `workos` store into existence in an app that isn't using
        // feature flags at all.
        Feature::flushCache();

        return $guard;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private static function payloadFor(Authenticatable $user, array $claims): array
    {
        $role = self::nullableString($claims['role'] ?? null);

        // Everything the friendly keys already account for is removed, so what
        // remains is merged into the token verbatim — that is what lets a test
        // pin `sid` or carry an app-specific claim without a new parameter.
        $overrides = $claims;
        unset(
            $overrides['organization'],
            $overrides['impersonator'],
            $overrides['sub'],
            $overrides['role'],
            $overrides['roles'],
            $overrides['permissions'],
            $overrides['feature_flags'],
            $overrides['claims'],
        );

        // The explicit escape hatch, applied last so it always wins: a claim
        // literally named `organization` or `permissions` is unreachable
        // through the flat form above, since those names are spoken for.
        $nested = $claims['claims'] ?? null;

        if (is_array($nested)) {
            $overrides = array_merge($overrides, $nested);
        }

        $payload = [
            'sub' => self::subjectFor($user, $claims),
            'iss' => self::configString('authkit.jwt.issuer', 'https://api.workos.com'),
            'client_id' => self::configString('authkit.client_id', 'client_fake'),
            'sid' => 'session_fake',
            'jti' => 'jwt_fake',
            'iat' => time(),
            'exp' => time() + 3600,
            'org_id' => self::organizationIdFor($claims),
            'role' => $role,
            // Real AuthKit tokens carry both shapes and ClaimsGateHook checks
            // both, so a test that sets only `role` still works through the
            // plural path. An explicit `roles` wins outright.
            'roles' => self::stringList($claims['roles'] ?? null) ?? ($role !== null ? [$role] : []),
            'permissions' => self::stringList($claims['permissions'] ?? null) ?? [],
            'feature_flags' => self::stringList($claims['feature_flags'] ?? null) ?? [],
        ];

        $actorId = self::impersonatorId($claims);

        if ($actorId !== null) {
            $payload['act'] = ['sub' => $actorId];
        }

        $payload = array_merge($payload, $overrides);

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The `sub` claim — the user's WorkOS id, which is what the Pennant store
     * and FGA match a scope against.
     *
     * A local-only user cannot stand in for a WorkOS principal, so this fails
     * loudly rather than synthesizing an id. A synthesized subject would not
     * match the model's `workos_id`, which is what the Pennant store and FGA
     * resolve a scope from — the session would authenticate and then silently
     * answer with no flags and no permissions, which is far harder to debug
     * than an exception naming the fix.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function subjectFor(Authenticatable $user, array $claims): string
    {
        $subject = self::nullableString($claims['sub'] ?? null);

        if ($subject !== null) {
            return $subject;
        }

        $workosId = $user instanceof Model ? $user->getAttribute('workos_id') : null;

        if (is_string($workosId) && $workosId !== '') {
            return $workosId;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot fake a WorkOS session for [%s]: it has no workos_id. Give the model one '
            ."(for example User::factory()->create(['workos_id' => 'user_123'])), or pass an "
            ."explicit subject: Authkit::actingAs(\$user, ['sub' => 'user_123']).",
            $user::class,
        ));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function organizationIdFor(array $claims): ?string
    {
        $organization = $claims['organization'] ?? null;

        if ($organization === null) {
            return null;
        }

        if (is_string($organization)) {
            if ($organization === '') {
                throw new InvalidArgumentException(
                    "Authkit::actingAs() was given an empty string for 'organization'. Pass a WorkOS "
                    ."organization id (for example 'org_123') or an Eloquent model carrying one.",
                );
            }

            return $organization;
        }

        if (! $organization instanceof Model) {
            throw new InvalidArgumentException(sprintf(
                'Authkit::actingAs() cannot read an organization from [%s]. Pass a WorkOS organization '
                ."id (for example 'org_123') or an Eloquent model with a workos_id — the same model "
                .'class configured as [authkit.organization.model].',
                get_debug_type($organization),
            ));
        }

        // Deliberately the literal column, not [authkit.organization.external_id_column]:
        // CurrentOrganizationResolver looks the row up by `workos_id`, so
        // honouring the config key here would produce a claim the resolver
        // then fails to match.
        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'Authkit::actingAs() was given [%s] as the organization, but its workos_id is empty — '
                .'it has not synced to WorkOS. Set one on the model (for example '
                ."['workos_id' => 'org_123']) so CurrentOrganizationResolver can resolve it back.",
                $organization::class,
            ));
        }

        return $workosId;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>|null
     */
    private static function impersonatorContext(array $claims): ?array
    {
        $impersonator = $claims['impersonator'] ?? null;

        return is_array($impersonator) ? $impersonator : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function impersonatorId(array $claims): ?string
    {
        $impersonator = self::impersonatorContext($claims);

        if ($impersonator === null) {
            return null;
        }

        // The sealed-session `impersonator` payload carries an email and a
        // reason but no id, while `act.sub` is the claim the guard treats as
        // authoritative — so a test that supplies only the human-readable
        // context still gets a well-formed impersonated token.
        return self::nullableString($impersonator['id'] ?? null) ?? 'user_impersonator';
    }

    /**
     * @return list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function configString(string $key, string $fallback): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
