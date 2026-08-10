<?php

declare(strict_types=1);

namespace Authkit\Authkit\FeatureFlags;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Contracts\Driver;
use Laravel\Pennant\Contracts\HasFlushableCache;
use RuntimeException;
use WorkOS\Exception\WorkOSException;
use WorkOS\Resource\Flag;

/**
 * Read-only Pennant store backed by WorkOS feature flags.
 *
 * Resolution is claims-first: an authenticated `workos` guard session whose
 * subject matches the requested scope answers from the JWT's `feature_flags`
 * claim with zero HTTP. Everything else (queued jobs, console, other scopes,
 * truncated claims) falls back to the WorkOS list endpoints behind a short
 * freshness TTL, serving stale data when WorkOS is unreachable.
 */
final class WorkosPennantDriver implements Driver, HasFlushableCache
{
    /**
     * Physical cache retention as a multiple of the freshness TTL — long enough
     * to survive a short WorkOS outage by serving stale data, short enough to
     * self-heal without manual intervention. See spec-phase-7 Decision D-9.
     */
    private const int STALE_RETENTION_MULTIPLIER = 20;

    /** @var array<string, true> de-duplicates "unknown/unresolved" log lines per driver-instance lifetime. */
    private array $loggedOnce = [];

    public function __construct(
        private readonly WorkosClientManager $client,
        private readonly CacheRepository $cache,
        private readonly int $cacheTtl,
    ) {}

    public function define(string $feature, callable $resolver): void
    {
        throw new RuntimeException(sprintf(
            'The "workos" Pennant store does not support Feature::define() for "%s". '
            .'Feature flags are defined in the WorkOS Dashboard, not in application code. '
            .'Use a different Pennant store for locally-defined features.',
            $feature,
        ));
    }

    /**
     * @return array<string>
     */
    public function defined(): array
    {
        return [];
    }

    /**
     * @param  array<string, array<int, mixed>>  $features
     * @return array<string, array<int, mixed>>
     */
    public function getAll(array $features): array
    {
        $results = [];

        foreach ($features as $feature => $scopes) {
            $results[$feature] = array_map(
                fn (mixed $scope): mixed => $this->get($feature, $scope),
                $scopes,
            );
        }

        return $results;
    }

    public function get(string $feature, mixed $scope): mixed
    {
        $resource = $this->resolveResource($scope);

        if ($resource === null) {
            $this->logOnce("no-resource:{$feature}", "authkit.feature_flags: no resolvable WorkOS user/organization for feature [{$feature}]; defaulting to false.");

            return false;
        }

        $fromClaims = $this->resolveFromClaims($feature, $resource);

        if ($fromClaims !== null) {
            return $fromClaims;
        }

        $active = in_array($feature, $this->enabledSlugsFor($resource), true);

        if (! $active) {
            $this->logOnce(
                "unknown:{$feature}:{$resource->type}",
                "authkit.feature_flags: [{$feature}] resolved to false for {$resource->type} [{$resource->id}] "
                .'(not enabled for this scope, or the slug does not exist in this WorkOS environment).',
            );
        }

        return $active;
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        throw new RuntimeException(
            'The "workos" Pennant store is read-only. Feature flags are managed in the WorkOS Dashboard '
            .'or via the WorkOS API directly; Feature::activate()/deactivate() are not supported here.',
        );
    }

    public function setForAllScopes(string $feature, mixed $value): void
    {
        throw new RuntimeException(
            'The "workos" Pennant store is read-only. Feature::activateForEveryone()/deactivateForEveryone() '
            .'are not supported here — toggle the flag in the WorkOS Dashboard.',
        );
    }

    public function delete(string $feature, mixed $scope): void
    {
        throw new RuntimeException('The "workos" Pennant store is read-only; Feature::forget() is not supported here.');
    }

    /**
     * @param  array<int, string>|null  $features
     */
    public function purge(?array $features): void
    {
        $this->loggedOnce = [];
    }

    public function flushCache(): void
    {
        // Deliberately does not touch the Cache-store-backed flag data: Octane
        // calls this on every request/job, which would defeat the TTL cache
        // entirely. See spec-phase-7 Decision D-3.
        $this->loggedOnce = [];
    }

    private function resolveResource(mixed $scope): ?WorkosFeatureScope
    {
        if (is_string($scope)) {
            return match (true) {
                str_starts_with($scope, 'org_') => new WorkosFeatureScope('organization', $scope),
                str_starts_with($scope, 'user_') => new WorkosFeatureScope('user', $scope),
                default => null,
            };
        }

        if (! is_object($scope)) {
            return null;
        }

        // Duck-typed on the workos_id column both declared projections carry
        // (spec-phase-7 Decision D-6); data_get routes through Eloquent's
        // attribute access for models and plain properties otherwise.
        $workosId = data_get($scope, 'workos_id');

        if (! is_string($workosId) || $workosId === '') {
            return null;
        }

        return match (true) {
            $scope instanceof Authenticatable => new WorkosFeatureScope('user', $workosId),
            $scope instanceof Model => new WorkosFeatureScope('organization', $workosId),
            default => null,
        };
    }

    private function resolveFromClaims(string $feature, WorkosFeatureScope $resource): ?bool
    {
        $claims = $this->guardClaims();

        if ($claims === null) {
            return null; // no authenticated workos session this request — API path
        }

        $subject = $resource->type === 'user' ? ($claims['sub'] ?? null) : ($claims['org_id'] ?? null);

        if ($subject !== $resource->id) {
            return null; // checking someone other than the current session's principal — API path
        }

        if (! array_key_exists('feature_flags', $claims) || ! is_array($claims['feature_flags'])) {
            return null; // claim absent/truncated (4KB cookie ceiling) — API path
        }

        return in_array($feature, $claims['feature_flags'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function guardClaims(): ?array
    {
        $guard = Auth::guard('workos');

        if ($guard instanceof HasAccessTokenClaims) {
            return $guard->accessTokenClaims();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function enabledSlugsFor(WorkosFeatureScope $resource): array
    {
        $key = $this->cacheKey($resource);
        $cached = $this->cachedEntry($key);

        if ($cached !== null && (time() - $cached['cachedAt']) < $this->cacheTtl) {
            return $cached['slugs'];
        }

        try {
            $slugs = $this->fetchFromApi($resource);
        } catch (WorkOSException $e) {
            if ($cached !== null) {
                Log::warning(
                    'authkit.feature_flags: WorkOS unreachable; serving flags cached '
                    .(time() - $cached['cachedAt'])."s ago for {$resource->type} [{$resource->id}].",
                    ['exception' => $e],
                );

                return $cached['slugs'];
            }

            Log::error(
                "authkit.feature_flags: WorkOS unreachable and no cached flags for {$resource->type} "
                ."[{$resource->id}]; defaulting every flag to false for this scope.",
                ['exception' => $e],
            );

            return [];
        }

        $this->cache->put(
            $key,
            ['slugs' => $slugs, 'cachedAt' => time()],
            now()->addSeconds($this->cacheTtl * self::STALE_RETENTION_MULTIPLIER),
        );

        return $slugs;
    }

    /**
     * @return array{slugs: list<string>, cachedAt: int}|null
     */
    private function cachedEntry(string $key): ?array
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return null;
        }

        $cachedAt = $cached['cachedAt'] ?? null;
        $rawSlugs = $cached['slugs'] ?? null;

        if (! is_int($cachedAt) || ! is_array($rawSlugs)) {
            return null;
        }

        $slugs = [];

        foreach ($rawSlugs as $slug) {
            if (is_string($slug)) {
                $slugs[] = $slug;
            }
        }

        return ['slugs' => $slugs, 'cachedAt' => $cachedAt];
    }

    /**
     * @return list<string>
     */
    private function fetchFromApi(WorkosFeatureScope $resource): array
    {
        $service = $this->client->client()->featureFlags();

        $response = match ($resource->type) {
            'user' => $service->listUserFeatureFlags($resource->id),
            'organization' => $service->listOrganizationFeatureFlags($resource->id),
        };

        $slugs = [];

        foreach ($response->autoPagingIterator() as $flag) {
            if ($flag instanceof Flag) {
                $slugs[] = $flag->slug;
            }
        }

        return $slugs;
    }

    private function cacheKey(WorkosFeatureScope $resource): string
    {
        // Namespaced by a hash of the client id so two WorkOS environments
        // sharing one cache store cannot read each other's flag lists
        // (spec-phase-7 Decision D-8). sha256 rather than the spec's md5:
        // the arch security preset bans md5, and this is a namespace
        // discriminator, not a security boundary — any stable hash works.
        $env = substr(hash('sha256', (string) config('authkit.client_id')), 0, 8);

        return "authkit:feature-flags:{$env}:{$resource->type}:{$resource->id}";
    }

    private function logOnce(string $dedupeKey, string $message): void
    {
        if (isset($this->loggedOnce[$dedupeKey])) {
            return;
        }

        $this->loggedOnce[$dedupeKey] = true;

        Log::debug($message);
    }
}
