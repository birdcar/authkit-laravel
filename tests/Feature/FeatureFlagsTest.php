<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Drivers\Decorator;
use Laravel\Pennant\Feature;
use Laravel\Pennant\FeatureManager;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler — the spec designates feature-flag reads as an emulate
// verb-mismatch area, so this suite never boots workos/emulate. Every case
// seeds its own MockHandler queue and stub-guard claims inline.

/**
 * Pins the exact contract WorkosPennantDriver depends on: any guard registered
 * as `workos` that exposes accessTokenClaims() via HasAccessTokenClaims —
 * decoupled from the real sealed-session guard's cookie internals.
 */
final class FeatureFlagsStubGuard implements Guard, HasAccessTokenClaims
{
    /**
     * @param  array<string, mixed>|null  $claims
     */
    public function __construct(
        private ?Authenticatable $user = null,
        private readonly ?array $claims = null,
    ) {}

    public function accessTokenClaims(): ?array
    {
        return $this->user !== null ? $this->claims : null;
    }

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function guest(): bool
    {
        return $this->user === null;
    }

    public function id(): int|string|null
    {
        $identifier = $this->user?->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }
}

/**
 * Never persisted — only used as a scope value to exercise the organization
 * branch of the driver's duck-typed scope resolution without depending on the
 * workbench Organization model.
 */
final class FeatureFlagsTestOrganization extends Model
{
    protected $guarded = [];
}

/**
 * @param  array<string, mixed>|null  $claims
 */
function featureFlagsActingAs(?Authenticatable $user, ?array $claims): void
{
    Auth::extend('workos', fn (): FeatureFlagsStubGuard => new FeatureFlagsStubGuard($user, $claims));
    Auth::forgetGuards();
}

/**
 * @return array<string, mixed>
 */
function featureFlagJson(string $slug): array
{
    return [
        'object' => 'feature_flag',
        'id' => 'flag_'.$slug,
        'slug' => $slug,
        'name' => ucfirst($slug),
        'description' => null,
        'tags' => [],
        'enabled' => true,
        'default_value' => false,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

function featureFlagListResponse(string ...$slugs): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => array_map(fn (string $slug): array => featureFlagJson($slug), $slugs),
        'list_metadata' => ['before' => null, 'after' => null],
    ]));
}

function featureFlagsCacheKey(string $type, string $id): string
{
    return 'authkit:feature-flags:'
        .substr(hash('sha256', (string) config('authkit.client_id')), 0, 8)
        .":{$type}:{$id}";
}

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('pennant.default', 'workos');
});

it('resolves via claims when the workos guard has a matching authenticated session, zero HTTP', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_claims']);
    featureFlagsActingAs($user, ['sub' => 'user_claims', 'feature_flags' => ['new-dashboard']]);

    // Empty queue: any stray HTTP call throws "Mock queue is empty" and fails.
    $this->fakeWorkosResponses([]);

    expect(Feature::for($user)->active('new-dashboard'))->toBeTrue()
        ->and(Feature::for($user)->active('other-flag'))->toBeFalse()
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('resolves the default scope to the workos guard user, zero HTTP', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_claims']);
    featureFlagsActingAs($user, ['sub' => 'user_claims', 'feature_flags' => ['new-dashboard']]);

    $this->fakeWorkosResponses([]);

    expect(Feature::active('new-dashboard'))->toBeTrue()
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('falls back to the API when no workos session is authenticated', function (): void {
    // No stub guard: the real workos guard sees no cookie, so there are no
    // claims — the console / queued-job equivalent per Decision D-7.
    $user = UserFactory::new()->create(['workos_id' => 'user_api']);

    $this->fakeWorkosResponses([featureFlagListResponse('beta-flag')]);

    expect(Feature::for($user)->active('beta-flag'))->toBeTrue()
        ->and($this->workosMockHandler->count())->toBe(0)
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
        ->toBe('/user_management/users/user_api/feature-flags');
});

it('falls back to the API when checking a scope other than the current session principal', function (): void {
    $userA = UserFactory::new()->create(['workos_id' => 'user_a']);
    $userB = UserFactory::new()->create(['workos_id' => 'user_b']);
    featureFlagsActingAs($userA, ['sub' => 'user_a', 'feature_flags' => ['team-inbox']]);

    $this->fakeWorkosResponses([featureFlagListResponse('team-inbox')]);

    expect(Feature::for($userB)->active('team-inbox'))->toBeTrue()
        ->and($this->workosRequestHistory)->toHaveCount(1)
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
        ->toBe('/user_management/users/user_b/feature-flags');
});

it('falls back to the API when the feature_flags claim is absent', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_truncated']);

    // Claims carry a matching subject but no feature_flags key — simulating
    // WorkOS dropping the claim at the 4KB sealed-cookie ceiling.
    featureFlagsActingAs($user, ['sub' => 'user_truncated']);

    $this->fakeWorkosResponses([featureFlagListResponse('truncated-flag')]);

    expect(Feature::for($user)->active('truncated-flag'))->toBeTrue()
        ->and($this->workosRequestHistory)->toHaveCount(1);
});

it('resolves organization scope via the organization feature flags endpoint', function (): void {
    $organization = new FeatureFlagsTestOrganization(['workos_id' => 'org_acme']);

    $this->fakeWorkosResponses([featureFlagListResponse('org-flag')]);

    expect(Feature::for($organization)->active('org-flag'))->toBeTrue()
        ->and(Feature::for($organization)->active('missing-org-flag'))->toBeFalse()
        ->and($this->workosRequestHistory)->toHaveCount(1)
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
        ->toBe('/organizations/org_acme/feature-flags');
});

it('resolves plain workos id strings as scopes by prefix', function (): void {
    $this->fakeWorkosResponses([
        featureFlagListResponse('string-flag'),
        featureFlagListResponse('string-flag'),
    ]);

    expect(Feature::for('user_direct')->active('string-flag'))->toBeTrue()
        ->and(Feature::for('org_direct')->active('string-flag'))->toBeTrue()
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
        ->toBe('/user_management/users/user_direct/feature-flags')
        ->and($this->workosRequestHistory[1]['request']->getUri()->getPath())
        ->toBe('/organizations/org_direct/feature-flags');
});

it('returns false without any HTTP for an unresolvable scope', function (): void {
    Log::spy();

    $this->fakeWorkosResponses([]);

    expect(Feature::for('not-a-workos-id')->active('any-flag'))->toBeFalse()
        ->and(Feature::for(new FeatureFlagsTestOrganization)->active('any-flag'))->toBeFalse()
        ->and($this->workosRequestHistory)->toHaveCount(0);

    // Both misses share the "no-resource:{feature}" dedupe key — one line, not two.
    Log::shouldHaveReceived('debug')->once();
});

it('returns false and logs once per slug and scope type for an unknown or disabled flag', function (): void {
    Log::spy();

    $userOne = UserFactory::new()->create(['workos_id' => 'user_one']);
    $userTwo = UserFactory::new()->create(['workos_id' => 'user_two']);

    $this->fakeWorkosResponses([
        featureFlagListResponse(),
        featureFlagListResponse(),
    ]);

    expect(Feature::for($userOne)->active('ghost-flag'))->toBeFalse()
        ->and(Feature::for($userTwo)->active('ghost-flag'))->toBeFalse();

    // Same driver instance, same "unknown:{slug}:{type}" dedupe key — the
    // second miss must not produce a second log line.
    Log::shouldHaveReceived('debug')->once();
});

it('serves stale cached flags when WorkOS is unreachable and a prior successful fetch exists', function (): void {
    Log::spy();

    config()->set('authkit.max_retries', 0);

    $user = UserFactory::new()->create(['workos_id' => 'user_stale']);

    $this->fakeWorkosResponses([
        featureFlagListResponse('rocket'),
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'server error'])),
    ]);

    expect(Feature::for($user)->active('rocket'))->toBeTrue();

    // Age the cached entry past the freshness TTL (but within physical
    // retention), then clear Pennant's per-request memoization so the next
    // check reaches the driver again.
    Cache::put(
        featureFlagsCacheKey('user', 'user_stale'),
        ['slugs' => ['rocket'], 'cachedAt' => time() - 3600],
        600,
    );
    Feature::flushCache();

    expect(Feature::for($user)->active('rocket'))->toBeTrue()
        ->and($this->workosRequestHistory)->toHaveCount(2);

    Log::shouldHaveReceived('warning')->once();
});

it('returns false and logs an error when WorkOS is unreachable with no prior cache', function (): void {
    Log::spy();

    config()->set('authkit.max_retries', 0);

    $user = UserFactory::new()->create(['workos_id' => 'user_cold']);

    $this->fakeWorkosResponses([
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'server error'])),
    ]);

    expect(Feature::for($user)->active('rocket'))->toBeFalse();

    Log::shouldHaveReceived('error')->once();
});

it('throws for Feature::define() on the workos store', function (): void {
    expect(fn () => Feature::store('workos')->define('local-flag', fn (): bool => true))
        ->toThrow(RuntimeException::class, 'defined in the WorkOS Dashboard');
});

it('throws for activate, deactivateForEveryone, and forget on the workos store', function (): void {
    expect(fn () => Feature::store('workos')->activate('x'))
        ->toThrow(RuntimeException::class, 'read-only')
        ->and(fn () => Feature::store('workos')->deactivateForEveryone('x'))
        ->toThrow(RuntimeException::class, 'read-only')
        ->and(fn () => Feature::store('workos')->forget('x'))
        ->toThrow(RuntimeException::class, 'read-only');
});

it('resolves the workos Pennant store without clobbering the built-in stores', function (): void {
    // Covers Decision D-4: the boot()-time dot-notation injection must leave
    // laravel/pennant's own mergeConfigFrom-provided stores intact even though
    // this package's provider registers before Pennant's.
    expect(Feature::store('workos'))->toBeInstanceOf(Decorator::class)
        ->and(config('pennant.stores.workos'))->toBe(['driver' => 'workos'])
        ->and(config('pennant.stores.array'))->toBe(['driver' => 'array'])
        ->and(config('pennant.stores.database.driver'))->toBe('database');
});

it('does not evict cached flag data when flushCache runs', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_cache']);

    $this->fakeWorkosResponses([featureFlagListResponse('sticky-flag')]);

    expect(Feature::for($user)->active('sticky-flag'))->toBeTrue();

    // Octane's per-request/per-job listeners call this on every HasFlushableCache
    // store; per Decision D-3 it must only reset the log-dedupe set, never the
    // Cache-store-backed flag data.
    app(FeatureManager::class)->flushCache();

    expect(Feature::for($user)->active('sticky-flag'))->toBeTrue()
        ->and($this->workosRequestHistory)->toHaveCount(1);
});
