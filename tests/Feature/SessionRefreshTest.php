<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Auth\RefreshStatus;
use Authkit\Authkit\Auth\SessionRefresher;
use Authkit\Authkit\Events\SessionCookieOversized;
use Authkit\Authkit\Http\Middleware\RefreshWorkosSession;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Workbench\Database\Factories\UserFactory;
use WorkOS\SessionManager;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    Route::get('/refresh-probe', fn (): string => 'ok')->middleware('authkit.session');
});

function jwksResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
}

function refreshResponse(string $sealedSession): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'sealed_session' => $sealedSession,
        'sid' => 'session_fixture',
        'user' => JwtFixture::user(),
        'access_token' => JwtFixture::sign(),
        'refresh_token' => 'refresh_rotated',
    ]));
}

it('refreshes and attaches a new cookie when the token is inside the buffer window', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $sealed = JwtFixture::sealedCookie(JwtFixture::sign(['exp' => time() + 30]));
    $this->fakeWorkosResponses([jwksResponse(), refreshResponse('sealed-after-refresh')]);

    $response = $this->withUnencryptedCookie('authkit_session', $sealed)->get('/refresh-probe');

    $response->assertOk();
    expect($response->getCookie('authkit_session', false)?->getValue())->toBe('sealed-after-refresh');
});

it('does not refresh a token that is comfortably inside its lifetime', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $sealed = JwtFixture::sealedCookie(JwtFixture::sign(['exp' => time() + 3600]));
    $this->fakeWorkosResponses([jwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', $sealed)->get('/refresh-probe')->assertOk();

    // Only the guard's JWKS fetch — no refresh round trip.
    expect($this->workosRequestHistory)->toHaveCount(1);
});

it('dispatches SessionCookieOversized when the refreshed cookie exceeds the limit', function (): void {
    Event::fake([SessionCookieOversized::class]);
    config()->set('authkit.session.max_cookie_bytes', 8);

    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $sealed = JwtFixture::sealedCookie(JwtFixture::sign(['exp' => time() + 30]));
    $this->fakeWorkosResponses([jwksResponse(), refreshResponse('a-cookie-well-past-eight-bytes')]);

    $this->withUnencryptedCookie('authkit_session', $sealed)->get('/refresh-probe')->assertOk();

    Event::assertDispatched(SessionCookieOversized::class, fn (SessionCookieOversized $event): bool => $event->maxBytes === 8 && $event->bytes === 30);
});

it('serves a cached result without a second network call when another request already refreshed', function (): void {
    $this->fakeWorkosResponses([]);
    Cache::put('authkit:refresh-result:session_fixture', 'sealed-from-the-winner', 60);

    $outcome = app(SessionRefresher::class)->refresh(
        sealedCookie: JwtFixture::sealedCookie(JwtFixture::sign()),
        sessionId: 'session_fixture',
        cookiePassword: JwtFixture::COOKIE_PASSWORD,
        clientId: JwtFixture::CLIENT_ID,
    );

    expect($outcome->status)->toBe(RefreshStatus::Refreshed)
        ->and($outcome->sealedCookie)->toBe('sealed-from-the-winner')
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('calls the refresh endpoint exactly once across two sequential refreshes of one session', function (): void {
    $this->fakeWorkosResponses([refreshResponse('sealed-once')]);

    $refresher = app(SessionRefresher::class);
    $sealed = JwtFixture::sealedCookie(JwtFixture::sign());

    $first = $refresher->refresh($sealed, 'session_fixture', JwtFixture::COOKIE_PASSWORD, JwtFixture::CLIENT_ID);
    $second = $refresher->refresh($sealed, 'session_fixture', JwtFixture::COOKIE_PASSWORD, JwtFixture::CLIENT_ID);

    // A second HTTP call would also blow the MockHandler queue — refresh tokens
    // rotate on every use, so the second caller must reuse the first's result.
    expect($first->sealedCookie)->toBe('sealed-once')
        ->and($second->sealedCookie)->toBe('sealed-once')
        ->and($this->workosRequestHistory)->toHaveCount(1);
});

it('reports HardExpired when WorkOS refuses the refresh token', function (): void {
    $this->fakeWorkosResponses([
        new Response(401, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'invalid_grant'])),
    ]);

    $outcome = app(SessionRefresher::class)->refresh(
        sealedCookie: JwtFixture::sealedCookie(JwtFixture::sign()),
        sessionId: 'session_expired',
        cookiePassword: JwtFixture::COOKIE_PASSWORD,
        clientId: JwtFixture::CLIENT_ID,
    );

    expect($outcome->status)->toBe(RefreshStatus::HardExpired)
        ->and($outcome->sealedCookie)->toBeNull();
});

it('proceeds with the existing claims when another request holds the refresh lock', function (): void {
    $this->fakeWorkosResponses([]);
    config()->set('authkit.session.lock_wait_seconds', 1);

    // Held by a foreign owner, so block() times out rather than acquiring.
    Cache::lock('authkit:refresh-lock:session_fixture', 30, 'another-request')->get();

    $outcome = app(SessionRefresher::class)->refresh(
        sealedCookie: JwtFixture::sealedCookie(JwtFixture::sign()),
        sessionId: 'session_fixture',
        cookiePassword: JwtFixture::COOKIE_PASSWORD,
        clientId: JwtFixture::CLIENT_ID,
    );

    // The losing request must not call refresh itself: refresh tokens rotate on
    // every use, so a second call would invalidate the winner's.
    expect($outcome->status)->toBe(RefreshStatus::ProceedWithExisting)
        ->and($outcome->sealedCookie)->toBeNull()
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('refreshes an expired session the guard could not authenticate', function (): void {
    // The long-idle-tab case: the access token is past exp so the guard yields a
    // guest, but the sealed cookie still carries a usable refresh token. Without
    // this the refresh token would never be spent.
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $expired = JwtFixture::sealedCookie(JwtFixture::sign(['iat' => time() - 7200, 'exp' => time() - 3600]));
    $freshCookie = JwtFixture::sealedCookie(JwtFixture::sign());

    $this->fakeWorkosResponses([
        jwksResponse(),                     // guard's first (failing) verification
        refreshResponse($freshCookie),      // the refresh exchange
        jwksResponse(),                     // guard re-resolving against the new cookie
    ]);

    $response = $this->withUnencryptedCookie('authkit_session', $expired)->get('/refresh-probe');

    $response->assertOk();
    expect($response->getCookie('authkit_session', false)?->getValue())->toBe($freshCookie);
});

it('authenticates the current request against the freshly refreshed session', function (): void {
    Route::get('/who-am-i-refreshed', fn (): string => (string) Auth::guard('workos')->user()?->workos_id)
        ->middleware('authkit.session');

    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $expired = JwtFixture::sealedCookie(JwtFixture::sign(['iat' => time() - 7200, 'exp' => time() - 3600]));

    $this->fakeWorkosResponses([
        jwksResponse(),
        refreshResponse(JwtFixture::sealedCookie(JwtFixture::sign())),
        jwksResponse(),
    ]);

    // A refresh that only helped the *next* request would leave this one a guest.
    $this->withUnencryptedCookie('authkit_session', $expired)
        ->get('/who-am-i-refreshed')
        ->assertOk()
        ->assertSee('user_fixture');
});

it('redirects to login and clears the cookie when an expired session cannot be refreshed', function (): void {
    $this->fakeWorkosResponses([
        jwksResponse(),
        new Response(401, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'invalid_grant'])),
    ]);

    $expired = JwtFixture::sealedCookie(JwtFixture::sign(['iat' => time() - 7200, 'exp' => time() - 3600]));

    $response = $this->withUnencryptedCookie('authkit_session', $expired)->get('/refresh-probe');

    $response->assertRedirect(route('authkit.login'));
    expect($response->getCookie('authkit_session', false)?->getExpiresTime())->toBeLessThan(time());
});

it('does not refresh a valid session that simply has no local user row', function (): void {
    // The orphaned-session case (DB reset, replica lag): the guard returns null,
    // but the token is fine. Refreshing here would spend and rotate a refresh token
    // on every request while still resolving to a guest.
    $this->fakeWorkosResponses([jwksResponse()]);

    $valid = JwtFixture::sealedCookie(JwtFixture::sign());

    $response = $this->withUnencryptedCookie('authkit_session', $valid)->get('/refresh-probe');

    $response->assertOk();
    expect($this->workosRequestHistory)->toHaveCount(1) // JWKS only — no refresh call
        ->and($response->getCookie('authkit_session', false))->toBeNull();
});

it('does not refresh a session whose token was minted for another client_id', function (): void {
    // Rejected by JwtClaimsValidator, not expired. Asking WorkOS for a fresh
    // session on the strength of a token we just rejected would be worse than
    // doing nothing.
    UserFactory::new()->create(['workos_id' => 'user_fixture']);
    $this->fakeWorkosResponses([jwksResponse()]);

    $foreign = JwtFixture::sealedCookie(JwtFixture::sign(['client_id' => 'client_other_app']));

    $this->withUnencryptedCookie('authkit_session', $foreign)->get('/refresh-probe')->assertOk();

    expect($this->workosRequestHistory)->toHaveCount(1);
});

it('leaves an expired cookie intact when another request holds the refresh lock', function (): void {
    // Clearing it here would race the lock winner's freshly sealed cookie and log
    // the user out despite a successful refresh.
    $this->fakeWorkosResponses([jwksResponse()]);
    config()->set('authkit.session.lock_wait_seconds', 1);
    Cache::lock('authkit:refresh-lock:session_fixture', 30, 'another-request')->get();

    $expired = JwtFixture::sealedCookie(JwtFixture::sign(['iat' => time() - 7200, 'exp' => time() - 3600]));

    $response = $this->withUnencryptedCookie('authkit_session', $expired)->get('/refresh-probe');

    $response->assertOk();
    expect($response->getCookie('authkit_session', false))->toBeNull();
});

it('leaves a cookie it did not seal alone', function (): void {
    $this->fakeWorkosResponses([]);

    // Not sealed with our cookie password, so there is no session id to key a
    // refresh on — the request proceeds as a guest rather than erroring.
    $this->withUnencryptedCookie('authkit_session', 'not-a-sealed-cookie')
        ->get('/refresh-probe')
        ->assertOk();

    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('redirects to the login route and clears the cookie when the session is hard expired', function (): void {
    $this->fakeWorkosResponses([]);

    $user = UserFactory::new()->create(['workos_id' => 'user_fixture']);

    // No refresh_token in the sealed payload, so SessionManager::refresh() reports
    // invalid_session_cookie without a network call and the refresher returns
    // HardExpired.
    $sealed = SessionManager::sealData(['access_token' => JwtFixture::sign()], JwtFixture::COOKIE_PASSWORD);
    $request = Request::create('/probe', 'GET', cookies: ['authkit_session' => $sealed]);
    app()->instance('request', $request);

    // The guard is seeded rather than resolved from the cookie: the SDK rejects an
    // expired token outright, so a genuinely expired cookie makes the guard return
    // null and the middleware never reaches this branch. It stays as a safety net
    // for the boundary second, and this is the only way to drive it. Seeding has to
    // happen after the request is bound, because that rebind resets the guard.
    $user->setWorkosClaims(AccessTokenClaims::fromPayload([
        'sub' => 'user_fixture',
        'iss' => JwtFixture::ISSUER,
        'client_id' => JwtFixture::CLIENT_ID,
        'sid' => 'session_fixture',
        'exp' => time() - 30,
    ]));
    Auth::guard('workos')->setUser($user);

    $response = app(RefreshWorkosSession::class)->handle($request, fn (): HttpResponse => new HttpResponse('ok'));

    expect($response->isRedirect(route('authkit.login')))->toBeTrue()
        ->and($this->workosRequestHistory)->toHaveCount(0);

    $cleared = collect($response->headers->getCookies())->firstWhere(fn ($c): bool => $c->getName() === 'authkit_session');

    expect($cleared)->not->toBeNull()
        ->and($cleared->getExpiresTime())->toBeLessThan(time());
});

it('reports HardExpired for a sealed session with no refresh token', function (): void {
    $this->fakeWorkosResponses([]);

    $sealedWithoutRefreshToken = SessionManager::sealData(
        ['access_token' => JwtFixture::sign()],
        JwtFixture::COOKIE_PASSWORD,
    );

    $outcome = app(SessionRefresher::class)->refresh(
        sealedCookie: $sealedWithoutRefreshToken,
        sessionId: 'session_no_refresh',
        cookiePassword: JwtFixture::COOKIE_PASSWORD,
        clientId: JwtFixture::CLIENT_ID,
    );

    expect($outcome->status)->toBe(RefreshStatus::HardExpired);
});
