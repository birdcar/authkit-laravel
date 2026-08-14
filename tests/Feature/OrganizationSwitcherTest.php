<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Authkit\Authkit\Organizations\OrganizationSwitcher;
use Authkit\Authkit\Organizations\OrganizationSwitchResult;
use Authkit\Authkit\Testing\FakeWorkosGuard;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.redirect_uri', 'https://app.test/authkit/callback');

    // The service face of the switch, exercised through a real route so the
    // request carries a sealed cookie and the response shows queued cookies.
    Route::post('/switch-service-test', fn (): array => [
        'switched' => Authkit::switchToOrganization('org_target'),
    ])->middleware('web');
});

it('reports NoSession when the request carries no sealed cookie', function (): void {
    $this->fakeWorkosResponses([]);

    expect(app(OrganizationSwitcher::class)->switch('org_target'))
        ->toBe(OrganizationSwitchResult::NoSession);
});

it('queues the rotated session cookie and reports true on a successful switch', function (): void {
    $newSealed = JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_target']));

    $this->fakeWorkosResponses([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'sealed_session' => $newSealed,
        'user' => JwtFixture::user(),
    ]))]);

    $response = $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/switch-service-test');

    $response->assertOk()->assertJson(['switched' => true]);

    $cookie = $response->getCookie('authkit_session', false);

    expect($cookie?->getValue())->toBe($newSealed)
        ->and($cookie?->isHttpOnly())->toBeTrue();

    $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

    expect($body['organization_id'])->toBe('org_target')
        ->and($body['grant_type'])->toBe('refresh_token');
});

it('reports false and rotates nothing when WorkOS refuses the refresh', function (): void {
    $this->fakeWorkosResponses([
        new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'message' => 'Refresh token already used.',
        ])),
    ]);

    $response = $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/switch-service-test');

    $response->assertOk()->assertJson(['switched' => false]);

    expect($response->getCookie('authkit_session', false))->toBeNull();
});

it('rejects an organization model that has not synced', function (): void {
    $this->fakeWorkosResponses([]);

    $organization = Organization::query()->createQuietly(['name' => 'Unsynced']);

    app(OrganizationSwitcher::class)->switch($organization);
})->throws(InvalidArgumentException::class, 'workos_id is empty');

it('forget() clears the resolver memo so the next resolve re-reads claims', function (): void {
    config()->set('authkit.organization.model', Organization::class);

    Organization::query()->createQuietly(['name' => 'A', 'workos_id' => 'org_a']);
    Organization::query()->createQuietly(['name' => 'B', 'workos_id' => 'org_b']);

    $user = User::query()->create(['name' => 'Switcher', 'email' => 'switcher@example.com']);

    $installGuard = function (string $organizationId) use ($user): void {
        $guard = new FakeWorkosGuard($user, ['sub' => 'user_abc', 'org_id' => $organizationId]);

        Auth::extend('workos', fn (): FakeWorkosGuard => $guard);
        Auth::forgetGuards();
    };

    $installGuard('org_a');

    $resolver = app(CurrentOrganizationResolver::class);

    expect($resolver->resolve()?->getAttribute('workos_id'))->toBe('org_a');

    // The claims underneath change, but the memo still answers org_a — the
    // exact staleness forget() exists to clear.
    $installGuard('org_b');

    expect($resolver->resolve()?->getAttribute('workos_id'))->toBe('org_a');

    $resolver->forget();

    expect($resolver->resolve()?->getAttribute('workos_id'))->toBe('org_b');
});
