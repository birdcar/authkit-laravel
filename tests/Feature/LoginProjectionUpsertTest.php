<?php

declare(strict_types=1);

use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

uses(UsesWorkosMockHandler::class);

// Test path note: emulate cannot be seeded with a pre-existing organization
// membership (its seed schema covers users and organizations only), so this
// suite is MockHandler-backed per the spec's sanctioned downgrade — the
// mechanism under test is unchanged, only the fixture source.

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.redirect_uri', 'https://app.test/authkit/callback');
});

function loginExchangeResponse(string $accessToken, array $userOverrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'user' => array_merge(JwtFixture::user(), $userOverrides),
        'access_token' => $accessToken,
        'refresh_token' => 'refresh_from_exchange',
    ]));
}

function linkedUserResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::user()));
}

function remoteOrgResponse(string $id, string $name): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => $id,
        'name' => $name,
        'domains' => [],
        'metadata' => [],
        'external_id' => null,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]));
}

function membershipListResponse(string $membershipId, string $organizationId, string $userId, string $role = 'member'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'data' => [[
            'object' => 'organization_membership',
            'id' => $membershipId,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => 'active',
            'directory_managed' => false,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'role' => ['slug' => $role],
            'roles' => [['slug' => $role]],
            'user' => JwtFixture::user(),
        ]],
        'list_metadata' => [],
    ]));
}

function performCallbackLogin(object $test): TestResponse
{
    return $test->withSession([
        'authkit.pkce.state' => 'state_abc',
        'authkit.pkce.code_verifier' => 'verifier_abc',
    ])->get('/authkit/callback?code=code_abc&state=state_abc');
}

it('backfills both projection rows for an org and membership never seen locally', function (): void {
    $token = JwtFixture::sign(['org_id' => 'org_first_seen']);

    $this->fakeWorkosResponses([
        loginExchangeResponse($token),
        linkedUserResponse(), // external-id write on first link
        remoteOrgResponse('org_first_seen', 'Acme (from WorkOS)'),
        membershipListResponse('om_backfilled', 'org_first_seen', 'user_fixture', 'admin'),
    ]);

    performCallbackLogin($this)->assertRedirect('/');

    $organization = Organization::query()->firstWhere('workos_id', 'org_first_seen');
    $membership = WorkosMembership::query()->firstWhere('workos_id', 'om_backfilled');

    expect($organization)->not->toBeNull()
        ->and($organization?->getAttribute('name'))->toBe('Acme (from WorkOS)')
        ->and($membership)->not->toBeNull()
        ->and($membership?->getAttribute('organization_id'))->toBe('org_first_seen')
        ->and($membership?->getAttribute('user_id'))->toBe('user_fixture')
        ->and($membership?->getAttribute('role'))->toBe('admin')
        ->and($membership?->getAttribute('status'))->toBe('active')
        ->and($this->workosRequestHistory)->toHaveCount(4);
});

it('costs zero extra API calls when the org and membership are already projected', function (): void {
    $user = User::query()->create(['name' => 'Alice', 'email' => 'alice@acme.com', 'workos_id' => 'user_fixture']);

    Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_known']);
    WorkosMembership::query()->create([
        'workos_id' => 'om_known',
        'organization_id' => 'org_known',
        'user_id' => 'user_fixture',
        'status' => 'active',
    ]);

    $token = JwtFixture::sign(['org_id' => 'org_known']);

    // external_id already matches, so the login itself is the only HTTP call.
    $this->fakeWorkosResponses([
        loginExchangeResponse($token, ['external_id' => (string) $user->getKey()]),
    ]);

    performCallbackLogin($this)->assertRedirect('/');

    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and(Organization::query()->count())->toBe(1)
        ->and(WorkosMembership::query()->count())->toBe(1);
});

it('projects nothing when the token carries no org_id claim', function (): void {
    $this->fakeWorkosResponses([
        loginExchangeResponse(JwtFixture::sign()),
        linkedUserResponse(),
    ]);

    performCallbackLogin($this)->assertRedirect('/');

    expect(Organization::query()->count())->toBe(0)
        ->and(WorkosMembership::query()->count())->toBe(0)
        ->and($this->workosRequestHistory)->toHaveCount(2);
});

it('projects nothing when no org model is configured', function (): void {
    config()->set('authkit.organization.model', null);

    $this->fakeWorkosResponses([
        loginExchangeResponse(JwtFixture::sign(['org_id' => 'org_unwired'])),
        linkedUserResponse(),
    ]);

    performCallbackLogin($this)->assertRedirect('/');

    expect(WorkosMembership::query()->count())->toBe(0)
        ->and($this->workosRequestHistory)->toHaveCount(2);
});

it('never turns a WorkOS failure during backfill into a failed login', function (): void {
    config()->set('authkit.max_retries', 0); // no SDK-internal 5xx retries

    Log::spy();

    $token = JwtFixture::sign(['org_id' => 'org_first_seen']);

    $this->fakeWorkosResponses([
        loginExchangeResponse($token),
        linkedUserResponse(),
        remoteOrgResponse('org_first_seen', 'Acme'),
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
            'message' => 'Internal server error.',
        ])),
    ]);

    // Login still succeeds: redirect + sealed cookie issued.
    $response = performCallbackLogin($this);

    $response->assertRedirect('/');

    expect($response->getCookie('authkit_session', false))->not->toBeNull()
        ->and(WorkosMembership::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});
