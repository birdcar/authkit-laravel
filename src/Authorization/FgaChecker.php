<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Exceptions\MembershipNotResolvedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuthorizationAssignment;

/**
 * Fine-grained authorization via the WorkOS Check API — the explicit
 * escalation path beyond claims-shaped RBAC.
 *
 * Caching is opt-in and DISABLED by default (authkit.fga.cache.enabled): a
 * stale cache entry is a stale permission decision, so the cache ships only
 * together with its events-driven invalidation wiring (InvalidateFgaCache,
 * plus the proactive forgetCache() calls on this package's own graph-mutating
 * write paths). Keys are generation-versioned — invalidation is one atomic
 * increment that makes every previously-cached decision unreachable — because
 * point invalidation would need cache tags (absent on file/database stores)
 * or a key index, which would be new local state. A cache-layer fault never
 * changes an authorization outcome: reads and writes fail open to a live
 * Check API call.
 *
 * WorkOS-down behavior is deliberately fail-loud: after the SDK's own retries
 * exhaust, WorkOSException propagates uncaught rather than being masked as a
 * deny (spec-phase-5 Failure Mode 11).
 */
class FgaChecker
{
    private const string GENERATION_KEY = 'authkit:fga:cache:generation';

    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly ResolvesOrganizationMembershipId $membershipResolver,
    ) {}

    /**
     * The ?RequestOptions parameter exists for SDK-signature parity and
     * per-call timeout/retry overrides, not idempotency — check() is a read.
     */
    public function check(
        string $permissionSlug,
        string $resourceExternalId,
        string $resourceTypeSlug,
        ?string $organizationMembershipId = null,
        ?Authenticatable $user = null,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): bool {
        $membershipId = $organizationMembershipId ?? $this->resolveMembershipId($user, $organizationId);
        $target = ResourceTarget::byExternalId($resourceExternalId, $resourceTypeSlug);

        if (! $this->cacheEnabled()) {
            return $this->rawCheck($membershipId, $permissionSlug, $target, $options);
        }

        // Store resolution sits INSIDE the guarded block: a misconfigured
        // store name throws from Cache::store() itself, and that fault must
        // degrade to a live check like any other cache-layer failure
        // (spec-phase-12 Failure Mode 3), never break authorization.
        try {
            $store = $this->cacheStore();
            $key = $this->cacheKey($store, $membershipId, $permissionSlug, $target);
            $cached = $store->get($key);

            if (is_bool($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
            Log::warning('authkit: FGA cache read failed, bypassing cache for this check', ['exception' => $e->getMessage()]);

            return $this->rawCheck($membershipId, $permissionSlug, $target, $options);
        }

        $authorized = $this->rawCheck($membershipId, $permissionSlug, $target, $options);

        try {
            $store->put($key, $authorized, $this->cacheTtl());
        } catch (Throwable $e) {
            Log::warning('authkit: FGA cache write failed, result not cached', ['exception' => $e->getMessage()]);
        }

        return $authorized;
    }

    /**
     * Invalidate every cached check decision in O(1) by bumping the
     * generation counter — stale entries become unreachable and age out via
     * TTL. No-op while the cache feature is disabled, so write paths may call
     * this unconditionally.
     *
     * A cache-layer fault here is logged, never thrown: forgetCache() runs
     * AFTER a WorkOS write has already succeeded (group role assignments,
     * resource sync) and inside the events pipeline's listeners — throwing
     * would misreport the completed write or poison the poller. The cost is
     * a TTL-bounded stale window, the same bound the cache already documents.
     */
    public function forgetCache(): void
    {
        if (! $this->cacheEnabled()) {
            return;
        }

        try {
            $this->cacheStore()->increment(self::GENERATION_KEY);
        } catch (Throwable $e) {
            Log::warning('authkit: FGA cache invalidation failed; stale entries persist until TTL', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Which resources under $parentResource can this membership exercise
     * $permissionSlug on? Uncached — discovery is a listing, not a decision.
     */
    public function listResourcesForMembership(
        string $organizationMembershipId,
        ResourceTarget $parentResource,
        string $permissionSlug,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        return $this->clients->client()->authorization()->listResourcesForMembership(
            organizationMembershipId: $organizationMembershipId,
            parentResource: $parentResource->toParentTarget(),
            permissionSlug: $permissionSlug,
            before: $before,
            after: $after,
            limit: $limit,
        );
    }

    /**
     * Which organization memberships hold $permissionSlug on this resource
     * (by WorkOS resource id)?
     */
    public function listMembershipsForResource(
        string $resourceId,
        string $permissionSlug,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        ?AuthorizationAssignment $assignment = null,
    ): PaginatedResponse {
        return $this->clients->client()->authorization()->listMembershipsForResource(
            resourceId: $resourceId,
            permissionSlug: $permissionSlug,
            before: $before,
            after: $after,
            limit: $limit,
            assignment: $assignment,
        );
    }

    /**
     * Which organization memberships hold $permissionSlug on this resource
     * (by external id + type slug, the HasWorkosResource linking convention)?
     */
    public function listMembershipsForResourceByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        string $permissionSlug,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        ?AuthorizationAssignment $assignment = null,
    ): PaginatedResponse {
        return $this->clients->client()->authorization()->listMembershipsForResourceByExternalId(
            organizationId: $organizationId,
            resourceTypeSlug: $resourceTypeSlug,
            externalId: $externalId,
            permissionSlug: $permissionSlug,
            before: $before,
            after: $after,
            limit: $limit,
            assignment: $assignment,
        );
    }

    /**
     * The direct, uncached Check API call — one network round-trip.
     */
    private function rawCheck(
        string $organizationMembershipId,
        string $permissionSlug,
        ResourceTarget $target,
        ?RequestOptions $options,
    ): bool {
        $result = $this->clients->client()->authorization()->check(
            $organizationMembershipId,
            $permissionSlug,
            $target->toSdkTarget(),
            $options,
        );

        return $result->authorized;
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('authkit.fga.cache.enabled', false);
    }

    private function cacheStore(): Repository
    {
        $store = config('authkit.fga.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function cacheTtl(): int
    {
        return (int) config('authkit.fga.cache.ttl', 300);
    }

    private function cacheKey(Repository $store, string $organizationMembershipId, string $permissionSlug, ResourceTarget $target): string
    {
        // Default is 0, not 1. Laravel's increment() on a key that has never
        // been set stores 1 on every driver — so the very first forgetCache()
        // call sets the generation to 1. If the implicit default here also
        // read 1, that first invalidation would be invisible: pre- and
        // post-invalidation keys would collide under the same generation
        // number and stale entries would stay reachable.
        $generation = $store->get(self::GENERATION_KEY, 0);

        return sprintf(
            'authkit:fga:check:g%d:%s:%s:%s',
            is_numeric($generation) ? (int) $generation : 0,
            $organizationMembershipId,
            $permissionSlug,
            $target->cacheFragment(),
        );
    }

    private function resolveMembershipId(?Authenticatable $user, ?string $organizationId): string
    {
        $user ??= Auth::guard('workos')->user();
        $organizationId ??= $this->currentOrganizationIdFromClaims();

        $membershipId = $user !== null && $organizationId !== null
            ? $this->membershipResolver->resolve($user, $organizationId)
            : null;

        if ($membershipId === null) {
            throw MembershipNotResolvedException::forContext(
                $this->authIdentifier($user),
                $organizationId ?? 'unknown',
            );
        }

        return $membershipId;
    }

    private function authIdentifier(?Authenticatable $user): int|string
    {
        $identifier = $user?->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : 'guest';
    }

    private function currentOrganizationIdFromClaims(): ?string
    {
        $guard = Auth::guard('workos');

        if (! $guard instanceof HasAccessTokenClaims) {
            return null;
        }

        $organizationId = $guard->accessTokenClaims()['org_id'] ?? null;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
