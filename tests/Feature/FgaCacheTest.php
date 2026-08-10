<?php

declare(strict_types=1);

use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(UsesWorkosMockHandler::class)->group('depth-extensions');

// Test path: MockHandler + Laravel's array cache store — emulate's check
// endpoint expects a `permission` body key the SDK does not send (Phase 5
// finding), and cache assertions need a controllable in-process store anyway.

beforeEach(function (): void {
    // Membership events also feed the Phase 4 projection listeners, which
    // upsert into workos_memberships — the table must exist for dispatches.
    $this->migratePackageDatabase();
});

function fgaCacheCheckResponse(bool $authorized): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['authorized' => $authorized]));
}

function fgaCacheEnable(?string $store = null): void
{
    config()->set('authkit.fga.cache.enabled', true);

    if ($store !== null) {
        config()->set('authkit.fga.cache.store', $store);
    }
}

function fgaCacheCheck(): bool
{
    return Authkit::check('view', 'proj_42', 'project', organizationMembershipId: 'om_1');
}

describe('FgaCache', function (): void {
    it('ships disabled by default', function (): void {
        expect(config('authkit.fga.cache.enabled'))->toBeFalse();
    });

    it('calls the API on every check and never touches the cache store while disabled', function (): void {
        // A store whose every operation throws: if the disabled path touched
        // the cache at all, the check would blow up or log instead of passing.
        Cache::extend('fga-explosive', function (): Repository {
            return Cache::repository(new class extends ArrayStore
            {
                public function get($key): mixed
                {
                    throw new RuntimeException('cache must not be touched while disabled');
                }

                public function put($key, $value, $seconds): bool
                {
                    throw new RuntimeException('cache must not be touched while disabled');
                }
            });
        });
        config()->set('cache.stores.fga-explosive', ['driver' => 'fga-explosive']);
        config()->set('authkit.fga.cache.store', 'fga-explosive');

        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(true)]);

        expect(fgaCacheCheck())->toBeTrue()
            ->and(fgaCacheCheck())->toBeTrue()
            ->and($this->workosRequestHistory)->toHaveCount(2);
    });

    it('serves a warm check from cache without an API call', function (): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true)]);

        expect(fgaCacheCheck())->toBeTrue()   // cold: API call + cache write
            ->and(fgaCacheCheck())->toBeTrue() // warm: cache read only
            ->and($this->workosRequestHistory)->toHaveCount(1);
    });

    it('caches deny decisions, not just allows', function (): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(false)]);

        expect(fgaCacheCheck())->toBeFalse()
            ->and(fgaCacheCheck())->toBeFalse()
            ->and($this->workosRequestHistory)->toHaveCount(1);
    });

    it('keys decisions by membership, permission, and resource target', function (): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(false)]);

        expect(Authkit::check('view', 'proj_42', 'project', organizationMembershipId: 'om_1'))->toBeTrue()
            ->and(Authkit::check('view', 'proj_43', 'project', organizationMembershipId: 'om_1'))->toBeFalse()
            ->and(Authkit::check('view', 'proj_42', 'project', organizationMembershipId: 'om_1'))->toBeTrue()
            ->and($this->workosRequestHistory)->toHaveCount(2);
    });

    it('busts the cache when a typed membership event arrives', function (string $eventClass): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(false)]);

        expect(fgaCacheCheck())->toBeTrue();

        // Full projection-shaped payload: the same dispatch also feeds the
        // Phase 4 membership projection listener, which persists these keys.
        Event::dispatch(new $eventClass('event_01', [
            'id' => 'om_1',
            'organization_id' => 'org_acme',
            'user_id' => 'user_1',
            'status' => 'active',
            'role' => ['slug' => 'member'],
        ], new DateTimeImmutable('now')));

        // Generation bumped: the warm entry is unreachable, so the next check
        // is a live call and serves the post-change decision.
        expect(fgaCacheCheck())->toBeFalse()
            ->and($this->workosRequestHistory)->toHaveCount(2);
    })->with([
        'membership created' => [OrganizationMembershipCreated::class],
        'membership updated' => [OrganizationMembershipUpdated::class],
        'membership deleted' => [OrganizationMembershipDeleted::class],
    ]);

    it('busts the cache on authorization-relevant generic WorkOS events', function (string $type): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(false)]);

        expect(fgaCacheCheck())->toBeTrue();

        Event::dispatch(new GenericWorkosEvent($type, 'event_01', ['id' => 'res_x'], new DateTimeImmutable('now')));

        expect(fgaCacheCheck())->toBeFalse()
            ->and($this->workosRequestHistory)->toHaveCount(2);
    })->with([
        'role.updated',
        'organization_role.deleted',
        'permission.created',
        'group.updated',
    ]);

    it('keeps the cache warm on generic WorkOS events that cannot shift a check outcome', function (): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true)]);

        expect(fgaCacheCheck())->toBeTrue();

        Event::dispatch(new GenericWorkosEvent('dsync.activated', 'event_02', ['id' => 'dir_x'], new DateTimeImmutable('now')));

        expect(fgaCacheCheck())->toBeTrue()
            ->and($this->workosRequestHistory)->toHaveCount(1);
    });

    it('does not bust the cache on membership events while the cache is disabled', function (): void {
        // The listener is registered unconditionally; forgetCache() itself is
        // the config-guarded no-op. With the cache off, the event must not
        // even bump the generation counter in the store.
        Event::dispatch(new OrganizationMembershipCreated('event_03', [
            'id' => 'om_1',
            'organization_id' => 'org_acme',
            'user_id' => 'user_1',
            'status' => 'active',
        ], new DateTimeImmutable('now')));

        expect(Cache::get('authkit:fga:cache:generation'))->toBeNull();
    });

    it('falls back to a live check when the cache read throws, without changing the outcome', function (): void {
        Cache::extend('fga-failing-read', function (): Repository {
            return Cache::repository(new class extends ArrayStore
            {
                public function get($key): mixed
                {
                    throw new RuntimeException('redis is down');
                }
            });
        });
        config()->set('cache.stores.fga-failing-read', ['driver' => 'fga-failing-read']);
        fgaCacheEnable(store: 'fga-failing-read');

        Log::spy();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(false)]);

        // Neither an authorization outage (deny) nor a bypass (allow): each
        // check serves the live API outcome.
        expect(fgaCacheCheck())->toBeTrue()
            ->and(fgaCacheCheck())->toBeFalse()
            ->and($this->workosRequestHistory)->toHaveCount(2);

        Log::shouldHaveReceived('warning')
            ->twice()
            ->withArgs(fn (string $message): bool => str_contains($message, 'FGA cache read failed'));
    });

    it('still returns the live outcome when the cache write throws', function (): void {
        Cache::extend('fga-failing-write', function (): Repository {
            return Cache::repository(new class extends ArrayStore
            {
                public function put($key, $value, $seconds): bool
                {
                    throw new RuntimeException('serialization failure');
                }
            });
        });
        config()->set('cache.stores.fga-failing-write', ['driver' => 'fga-failing-write']);
        fgaCacheEnable(store: 'fga-failing-write');

        Log::spy();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(false), fgaCacheCheckResponse(true)]);

        // The caching side-effect is lost, so every check stays a live call —
        // but the returned decision is always the API's.
        expect(fgaCacheCheck())->toBeFalse()
            ->and(fgaCacheCheck())->toBeTrue()
            ->and($this->workosRequestHistory)->toHaveCount(2);

        Log::shouldHaveReceived('warning')
            ->twice()
            ->withArgs(fn (string $message): bool => str_contains($message, 'FGA cache write failed'));
    });

    it('degrades to live checks and non-throwing invalidation when the configured store name does not exist', function (): void {
        // Cache::store('nope') throws before any read — a misconfigured
        // store must degrade exactly like a down backend (Failure Mode 3):
        // checks stay live and correct, invalidation logs instead of
        // exploding after an already-successful WorkOS write.
        fgaCacheEnable(store: 'nope');

        Log::spy();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(false)]);

        expect(fgaCacheCheck())->toBeTrue()
            ->and(fgaCacheCheck())->toBeFalse()
            ->and($this->workosRequestHistory)->toHaveCount(2);

        Authkit::fga()->forgetCache();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'FGA cache read failed'))
            ->twice();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'FGA cache invalidation failed'))
            ->once();
    });

    it('survives the very first invalidation: generation defaults to 0 and increment() seeds 1', function (): void {
        fgaCacheEnable();
        $this->fakeWorkosResponses([fgaCacheCheckResponse(true), fgaCacheCheckResponse(false)]);

        expect(fgaCacheCheck())->toBeTrue();

        // First-ever bump on a store where the generation key has never been
        // written: increment() seeds the key with 1, and since cold keys read
        // generation 0, the warm entry must become unreachable.
        Event::dispatch(new GenericWorkosEvent('role.updated', 'event_04', ['id' => 'role_x'], new DateTimeImmutable('now')));

        expect(Cache::get('authkit:fga:cache:generation'))->toBe(1)
            ->and(fgaCacheCheck())->toBeFalse()
            ->and($this->workosRequestHistory)->toHaveCount(2);
    });
});
