<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Workbench\Database\Factories\UserFactory;
use WorkOS\SessionManager;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.redirect_uri', 'https://app.test/authkit/callback');
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function authenticateResponse(string $accessToken, ?array $impersonator = null): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(array_filter([
        'user' => JwtFixture::user(),
        'access_token' => $accessToken,
        'refresh_token' => 'refresh_from_exchange',
        'impersonator' => $impersonator,
    ])));
}

function userWriteResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::user()));
}

it('redirects to a WorkOS authorization URL carrying PKCE and state', function (): void {
    $this->fakeWorkosResponses([]);

    $response = $this->get('/authkit/login');

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://api.workos.com/user_management/authorize')
        ->and($query['client_id'])->toBe(JwtFixture::CLIENT_ID)
        ->and($query['redirect_uri'])->toBe('https://app.test/authkit/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->not->toBeEmpty()
        // Real WorkOS rejects a selector-less /authorize outright ("invalid
        // connection selector") — the emulator tolerates it, so only this
        // assertion keeps the hosted-AuthKit path honest.
        ->and($query['provider'])->toBe('authkit')
        ->and($query['state'])->not->toBeEmpty();

    // The verifier stays server-side; only the challenge travels to WorkOS.
    $this->assertNotNull(session('authkit.pkce.code_verifier'));
    expect(session('authkit.pkce.state'))->toBe($query['state']);
});

it('exchanges the code, links the user, and sets the sealed session cookie', function (): void {
    Event::fake([Login::class]);
    $this->fakeWorkosResponses([authenticateResponse(JwtFixture::sign()), userWriteResponse()]);

    $response = $this->withSession([
        'authkit.pkce.state' => 'state_abc',
        'authkit.pkce.code_verifier' => 'verifier_abc',
        'url.intended' => '/dashboard',
    ])->get('/authkit/callback?code=code_abc&state=state_abc');

    $response->assertRedirect('/dashboard');

    $cookie = $response->getCookie('authkit_session', false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');

    // The sealed value has to round-trip through the SDK's own unseal.
    $unsealed = SessionManager::unsealData((string) $cookie->getValue(), JwtFixture::COOKIE_PASSWORD);

    expect($unsealed['refresh_token'])->toBe('refresh_from_exchange')
        ->and($unsealed['user']['id'])->toBe('user_fixture');

    Event::assertDispatched(Login::class, function (Login $event) use ($unsealed): bool {
        return $event->user->workos_id === 'user_fixture'
            && $event->response->accessToken === $unsealed['access_token']
            && $event->response->refreshToken === 'refresh_from_exchange';
    });
});

it('issues and clears the cookie on the same path and domain', function (): void {
    // A browser only replaces a cookie when name, path, and domain all match. If
    // logout cleared a different (name, path, domain) triple the sealed cookie
    // would survive, and the guard would keep accepting it until the token expired.
    config()->set('session.path', '/app');
    config()->set('session.domain', '.acme.test');

    $this->fakeWorkosResponses([authenticateResponse(JwtFixture::sign()), userWriteResponse()]);

    $issued = $this->withSession([
        'authkit.pkce.state' => 'state_abc',
        'authkit.pkce.code_verifier' => 'verifier_abc',
    ])->get('/authkit/callback?code=code_abc&state=state_abc')->getCookie('authkit_session', false);

    $cleared = $this->post('/authkit/logout')->getCookie('authkit_session', false);

    expect($issued?->getPath())->toBe('/app')
        ->and($issued?->getDomain())->toBe('.acme.test')
        ->and($cleared?->getPath())->toBe($issued?->getPath())
        ->and($cleared?->getDomain())->toBe($issued?->getDomain())
        ->and($cleared?->getName())->toBe($issued?->getName());
});

it('registers no routes when routes are disabled', function (): void {
    // Re-runs the route file itself rather than rebuilding the app, because
    // refreshApplication() would replay getEnvironmentSetUp() and discard the
    // config change under test.
    $countBefore = Route::getRoutes()->count();

    config()->set('authkit.routes.enabled', false);
    require dirname(__DIR__, 2).'/routes/authkit-laravel.php';

    expect(Route::getRoutes()->count())->toBe($countBefore);
});

it('authenticates the guard on a follow-up request using the cookie the callback issued', function (): void {
    Route::get('/who-am-i', fn (): string => (string) Auth::guard('workos')->user()?->workos_id);

    $this->fakeWorkosResponses([
        authenticateResponse(JwtFixture::sign()),
        userWriteResponse(),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks())),
    ]);

    $callback = $this->withSession([
        'authkit.pkce.state' => 'state_abc',
        'authkit.pkce.code_verifier' => 'verifier_abc',
    ])->get('/authkit/callback?code=code_abc&state=state_abc');

    $sealed = (string) $callback->getCookie('authkit_session', false)?->getValue();

    $this->withUnencryptedCookie('authkit_session', $sealed)
        ->get('/who-am-i')
        ->assertOk()
        ->assertSee('user_fixture');
});

it('rejects a replayed callback because the state was already consumed', function (): void {
    $this->fakeWorkosResponses([authenticateResponse(JwtFixture::sign()), userWriteResponse()]);

    $session = [
        'authkit.pkce.state' => 'state_abc',
        'authkit.pkce.code_verifier' => 'verifier_abc',
    ];

    $this->withSession($session)->get('/authkit/callback?code=code_abc&state=state_abc')->assertRedirect();

    // Same code and state, replayed with the state no longer in the session.
    $replay = $this->get('/authkit/callback?code=code_abc&state=state_abc');

    $replay->assertRedirect(route('authkit.login'));
    expect($this->workosRequestHistory)->toHaveCount(2);
});

it('rejects a callback whose state does not match the stored value', function (): void {
    $this->fakeWorkosResponses([]);

    $this->withSession(['authkit.pkce.state' => 'state_abc', 'authkit.pkce.code_verifier' => 'verifier_abc'])
        ->get('/authkit/callback?code=code_abc&state=state_tampered')
        ->assertRedirect(route('authkit.login'));

    // No code exchange was attempted.
    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('redirects to the WorkOS logout URL and clears the cookie for a valid session', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $this->fakeWorkosResponses([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks())),
    ]);

    // Unencrypted on purpose: the provider registers the sealed cookie with
    // EncryptCookies::except(), so it travels as-is in every middleware group.
    $response = $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->post('/authkit/logout');

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://api.workos.com/user_management/sessions/logout')
        ->and($location)->toContain('session_id=session_fixture')
        ->and($response->getCookie('authkit_session', false)?->getExpiresTime())->toBeLessThan(time());
});

it('clears the cookie without calling WorkOS when the session is already invalid', function (): void {
    $this->fakeWorkosResponses([]);

    $response = $this->withUnencryptedCookie('authkit_session', 'not-a-sealed-cookie')->post('/authkit/logout');

    $response->assertRedirect('/');
    expect($response->getCookie('authkit_session', false)?->getExpiresTime())->toBeLessThan(time())
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('clears nothing and redirects home when there is no cookie at all', function (): void {
    $this->fakeWorkosResponses([]);

    $this->post('/authkit/logout')->assertRedirect('/');
});

it('reaches a running emulator for the login redirect and the JWKS the guard depends on', function (): void {
    // The MockHandler cases above prove our own wiring. This one proves the two
    // things a fake cannot: that Phase 1's emulate base-URL override actually
    // reaches this phase's route, and that the JWKS endpoint the guard's signature
    // verification depends on answers with real keys on the wire.
    $this->server = new EmulateServer(port: 4198);
    $this->server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $this->server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    $authorizeUrl = (string) $this->get('/authkit/login')->headers->get('Location');

    expect($authorizeUrl)->toStartWith($this->server->baseUrl().'/user_management/authorize')
        ->and($authorizeUrl)->toContain('code_challenge_method=S256');

    $jwks = app(WorkosClientManager::class)->client()->sessionManager()->fetchJwks(JwtFixture::CLIENT_ID);

    expect($jwks)->toHaveKey('keys')
        ->and($jwks['keys'])->not->toBeEmpty();
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
