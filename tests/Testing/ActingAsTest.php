<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Events\Impersonating;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Testing\FakeWorkosGuard;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    // An EMPTY mock queue: any WorkOS HTTP call throws "Mock queue is empty",
    // so every assertion below doubles as proof the fake session needs zero
    // network — no JWKS fetch, no session authenticate, no flags read.
    $this->fakeWorkosResponses([]);
});

function actingUser(string $workosId = 'user_acting'): User
{
    return UserFactory::new()->create(['workos_id' => $workosId]);
}

it('authenticates the workos guard and the default guard without cookies or network', function (): void {
    $user = actingUser();

    Authkit::actingAs($user);

    expect(Auth::guard('workos')->check())->toBeTrue()
        ->and(Auth::guard('workos')->user())->toBe($user)
        ->and(Auth::guard('workos')->id())->toBe($user->getAuthIdentifier())
        // Mirrors Laravel's actingAs: the workos guard becomes the default
        // guard for the rest of the test, so bare auth() sees the acting user.
        ->and(auth()->user())->toBe($user);
});

it('installs an unauthenticated guard through actingAsGuest', function (): void {
    Authkit::actingAsGuest();

    expect(Auth::guard('workos')->check())->toBeFalse()
        ->and(Auth::guard('workos')->user())->toBeNull()
        ->and(auth()->guest())->toBeTrue();
});

it('grants abilities from permissions claims through the gate', function (): void {
    $user = actingUser();

    Authkit::actingAs($user, ['permissions' => ['projects.delete', 'team.manage']]);

    expect($user->can('projects.delete'))->toBeTrue()
        ->and($user->can('team.manage'))->toBeTrue()
        ->and($user->can('projects.purge'))->toBeFalse();
});

it('grants abilities from singular role and plural roles claims', function (): void {
    $user = actingUser();

    Authkit::actingAs($user, ['role' => 'admin']);

    // The singular claim is mirrored into the plural shape, so both
    // ClaimsGateHook paths grant it.
    expect($user->can('admin'))->toBeTrue()
        ->and($user->can('viewer'))->toBeFalse();

    Authkit::actingAs($user, ['roles' => ['editor']]);

    expect($user->can('editor'))->toBeTrue();
});

it('flows claims through the WorkosUser contract exactly like the real guard', function (): void {
    $user = actingUser();
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acting']);

    Authkit::actingAs($user, [
        'organization' => $organization,
        'role' => 'admin',
        'permissions' => ['projects.delete'],
    ]);

    $claims = $user->claims();

    expect($claims)->toBeInstanceOf(AccessTokenClaims::class)
        ->and($claims->sub)->toBe('user_acting')
        ->and($claims->organizationId)->toBe('org_acting')
        ->and($claims->role)->toBe('admin')
        ->and($claims->permissions)->toBe(['projects.delete']);
});

it('resolves feature flags from claims with zero network', function (): void {
    $user = actingUser();

    Authkit::actingAs($user, ['feature_flags' => ['team-plan']]);

    expect(Feature::store('workos')->active('team-plan'))->toBeTrue()
        ->and(Feature::store('workos')->active('enterprise-plan'))->toBeFalse();
});

it('resolves all feature flags to false when none are claimed, still offline', function (): void {
    $user = actingUser();

    Authkit::actingAs($user);

    expect(Feature::store('workos')->active('team-plan'))->toBeFalse();
});

it('serves fresh feature flags after a second actingAs call', function (): void {
    $user = actingUser();

    Authkit::actingAs($user, ['feature_flags' => ['team-plan']]);

    expect(Feature::store('workos')->active('team-plan'))->toBeTrue();

    // Pennant memoizes per (feature, scope) — without a flush on install,
    // this second session would keep serving the first session's answer.
    Authkit::actingAs($user, ['feature_flags' => []]);

    expect(Feature::store('workos')->active('team-plan'))->toBeFalse();
});

it('resolves the current organization from a model', function (): void {
    $user = actingUser();
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acting']);

    Authkit::actingAs($user, ['organization' => $organization]);

    expect(Authkit::currentOrganization()?->is($organization))->toBeTrue();
});

it('resolves the current organization from a raw org id string', function (): void {
    $user = actingUser();
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_raw']);

    Authkit::actingAs($user, ['organization' => 'org_raw']);

    expect(Authkit::currentOrganization()?->is($organization))->toBeTrue();
});

it('switches organizations across actingAs calls within one test', function (): void {
    $user = actingUser();
    $first = Organization::query()->createQuietly(['name' => 'First', 'workos_id' => 'org_first']);
    $second = Organization::query()->createQuietly(['name' => 'Second', 'workos_id' => 'org_second']);

    Authkit::actingAs($user, ['organization' => $first]);

    expect(Authkit::currentOrganization()?->is($first))->toBeTrue();

    Authkit::actingAs($user, ['organization' => $second]);

    expect(Authkit::currentOrganization()?->is($second))->toBeTrue();
});

it('resolves no current organization when acting without one', function (): void {
    $user = actingUser();

    Authkit::actingAs($user);

    expect(Authkit::currentOrganization())->toBeNull();
});

it('dispatches Impersonating and sets the impersonator context on the user', function (): void {
    Event::fake([Impersonating::class]);

    $user = actingUser();

    Authkit::actingAs($user, [
        'impersonator' => ['id' => 'user_support_admin', 'email' => 'admin@example.com', 'reason' => 'debugging'],
    ]);

    Event::assertDispatched(Impersonating::class, function (Impersonating $event) use ($user): bool {
        return $event->user->is($user)
            && $event->impersonatorWorkosUserId === 'user_support_admin'
            && ($event->impersonatorContext['email'] ?? null) === 'admin@example.com';
    });

    expect($user->impersonator())->toBe(['id' => 'user_support_admin', 'email' => 'admin@example.com', 'reason' => 'debugging']);
});

it('does not dispatch Impersonating for a plain acting session', function (): void {
    Event::fake([Impersonating::class]);

    Authkit::actingAs(actingUser());

    Event::assertNotDispatched(Impersonating::class);
});

it('survives a full HTTP request cycle through auth:workos middleware', function (): void {
    $user = actingUser();
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acting']);

    Route::middleware('auth:workos')->get('/testing/me', function (): array {
        return [
            'id' => auth()->id(),
            'can_delete' => auth()->user()?->can('projects.delete'),
            'org' => request()->organization()?->getKey(),
        ];
    });

    Authkit::actingAs($user, [
        'organization' => $organization,
        'permissions' => ['projects.delete'],
    ]);

    $this->getJson('/testing/me')
        ->assertOk()
        ->assertJson([
            'id' => $user->getKey(),
            'can_delete' => true,
            'org' => $organization->getKey(),
        ]);
});

it('keeps the acting session across multiple requests in one test', function (): void {
    $user = actingUser();

    Route::middleware('auth:workos')->get('/testing/whoami', fn (): array => ['id' => auth()->id()]);

    Authkit::actingAs($user);

    $this->getJson('/testing/whoami')->assertOk()->assertJson(['id' => $user->getKey()]);
    $this->getJson('/testing/whoami')->assertOk()->assertJson(['id' => $user->getKey()]);
});

it('throws with guidance when an organization model has no workos_id', function (): void {
    $user = actingUser();
    $unsynced = Organization::query()->createQuietly(['name' => 'Unsynced']);

    expect(fn (): mixed => Authkit::actingAs($user, ['organization' => $unsynced]))
        ->toThrow(InvalidArgumentException::class, 'workos_id');
});

it('throws with guidance for a user without a workos_id', function (): void {
    $user = UserFactory::new()->create(['workos_id' => null]);

    // A synthetic sub would silently never match Pennant or FGA scopes, so
    // this fails loudly instead — and names the escape hatch.
    expect(fn (): mixed => Authkit::actingAs($user))
        ->toThrow(InvalidArgumentException::class, 'workos_id');
});

it('accepts an explicit sub for a user without a workos_id', function (): void {
    $user = UserFactory::new()->create(['workos_id' => null]);

    Authkit::actingAs($user, ['sub' => 'user_explicit']);

    expect(Auth::guard('workos')->user())->toBe($user)
        ->and($user->claims()?->sub)->toBe('user_explicit');
});

it('merges unrecognised keys into the token payload verbatim', function (): void {
    $user = actingUser();

    // Every key the friendly handling doesn't claim for itself lands in the
    // token as-is — how a test pins `sid` or carries an app-specific claim.
    Authkit::actingAs($user, ['sid' => 'session_pinned', 'custom_claim' => 'yes']);

    /** @var FakeWorkosGuard $guard */
    $guard = Auth::guard('workos');

    expect($user->claims()?->sessionId)->toBe('session_pinned')
        ->and($guard->accessTokenClaims()['custom_claim'] ?? null)->toBe('yes');
});

it('lets the nested claims escape hatch win over friendly-key handling', function (): void {
    $user = actingUser();

    // A raw claim literally named like a friendly key is unreachable through
    // the flat form — the nested array is applied last and always wins.
    Authkit::actingAs($user, [
        'permissions' => ['projects.delete'],
        'claims' => ['permissions' => 'raw-string-shape', 'sid' => 'session_nested'],
    ]);

    /** @var FakeWorkosGuard $guard */
    $guard = Auth::guard('workos');

    expect($guard->accessTokenClaims()['permissions'] ?? null)->toBe('raw-string-shape')
        ->and($guard->accessTokenClaims()['sid'] ?? null)->toBe('session_nested');
});
