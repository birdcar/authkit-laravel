<?php

declare(strict_types=1);

use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;

uses(UsesWorkosMockHandler::class);

// Test path note: a MockHandler-backed refresh stands in for emulate here —
// an emulate-backed happy path would need a real emulate login first (the
// sealed cookie must carry a refresh token emulate itself minted), which no
// suite in this package drives; Phase 2 established the same precedent for
// its refresh tests.

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.redirect_uri', 'https://app.test/authkit/callback');
});

function refreshedSessionResponse(string $sealed): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'sealed_session' => $sealed,
        'user' => JwtFixture::user(),
    ]));
}

it('rotates the sealed cookie to the target org and redirects to return_to', function (): void {
    $newSealed = JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_target']));

    $this->fakeWorkosResponses([refreshedSessionResponse($newSealed)]);

    $response = $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/authkit/organizations/org_target/switch', ['return_to' => '/dashboard']);

    $response->assertRedirect('/dashboard');

    $cookie = $response->getCookie('authkit_session', false);

    expect($cookie?->getValue())->toBe($newSealed)
        ->and($cookie?->isHttpOnly())->toBeTrue();

    // The refresh call carried the org hint the SDK signature supports.
    $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and($body['organization_id'])->toBe('org_target')
        ->and($body['grant_type'])->toBe('refresh_token');
});

it('defaults the redirect to the app root when no return_to is given', function (): void {
    $newSealed = JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_target']));

    $this->fakeWorkosResponses([refreshedSessionResponse($newSealed)]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/authkit/organizations/org_target/switch')
        ->assertRedirect('/');
});

it('refuses to use an absolute return_to as a redirect target', function (): void {
    $newSealed = JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_target']));

    $this->fakeWorkosResponses([refreshedSessionResponse($newSealed)]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/authkit/organizations/org_target/switch', ['return_to' => 'https://evil.example/phish'])
        ->assertRedirect('/');
});

it('falls back to a re-authorize redirect carrying the org hint when refresh is rejected', function (): void {
    $this->fakeWorkosResponses([
        new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'message' => 'Refresh token already used.',
        ])),
    ]);

    $response = $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/authkit/organizations/org_no_membership/switch', ['return_to' => '/dashboard']);

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://api.workos.com/user_management/authorize')
        ->and($query['organization_id'])->toBe('org_no_membership')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['state'])->not->toBeEmpty();

    // The PKCE handshake state landed in the session, and the intended URL
    // survives the round trip the same way the login flow's does.
    expect(session('authkit.pkce.code_verifier'))->not->toBeNull()
        ->and(session('url.intended'))->toBe('/dashboard');
});

it('redirects straight to login when there is no session cookie at all', function (): void {
    $this->fakeWorkosResponses([]);

    $this->post('/authkit/organizations/org_target/switch')
        ->assertRedirect(route('authkit.login'));

    expect($this->workosRequestHistory)->toHaveCount(0);
});
