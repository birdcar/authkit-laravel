<?php

declare(strict_types=1);

use Authkit\Authkit\Events\Impersonating;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    // Queued twice: SessionManager re-fetches JWKS once on a `kid` miss, and an
    // unconsumed MockHandler response is harmless.
    $jwks = fn (): Response => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
    $this->fakeWorkosResponses([$jwks(), $jwks()]);
});

function resolveWorkosUser(?string $sealed): ?Authenticatable
{
    $cookies = $sealed === null ? [] : ['authkit_session' => $sealed];

    // Bound before the guard is first resolved, so the guard reads this request.
    app()->instance('request', Request::create('/', 'GET', cookies: $cookies));

    return Auth::guard('workos')->user();
}

it('resolves the local user for a valid sealed cookie', function (): void {
    UserFactory::new()->create(['email' => 'alice@acme.com', 'workos_id' => 'user_fixture']);

    $user = resolveWorkosUser(JwtFixture::sealedCookie(JwtFixture::sign()));

    expect($user)->not->toBeNull()
        ->and($user->workos_id)->toBe('user_fixture')
        ->and($user->claims()?->sessionId)->toBe('session_fixture');
});

it('treats a valid session with no local user row as a guest', function (): void {
    expect(resolveWorkosUser(JwtFixture::sealedCookie(JwtFixture::sign())))->toBeNull();
});

it('rejects a token signed with a key the JWKS never advertised', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $forged = JwtFixture::sign(signingKeyPath: JwtFixture::forgedKeyPath());

    expect(resolveWorkosUser(JwtFixture::sealedCookie($forged)))->toBeNull();
});

it('rejects an expired token', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $expired = JwtFixture::sign(['iat' => time() - 7200, 'exp' => time() - 3600]);

    expect(resolveWorkosUser(JwtFixture::sealedCookie($expired)))->toBeNull();
});

it('rejects a validly signed token carrying the wrong issuer', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $wrongIssuer = JwtFixture::sign(['iss' => 'https://auth.attacker.example']);

    expect(resolveWorkosUser(JwtFixture::sealedCookie($wrongIssuer)))->toBeNull();
});

it('rejects a validly signed token minted for a different client_id', function (): void {
    // The cross-application replay case: same environment, same JWKS signing key,
    // different application. The signature verifies cleanly and only the
    // client_id check stops it.
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $otherApp = JwtFixture::sign(['client_id' => 'client_other_app']);

    expect(resolveWorkosUser(JwtFixture::sealedCookie($otherApp)))->toBeNull();
});

it('rejects a cookie whose sealed bytes were tampered with', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $sealed = JwtFixture::sealedCookie(JwtFixture::sign());
    $tampered = substr($sealed, 0, -8).strrev(substr($sealed, -8));

    expect(resolveWorkosUser($tampered))->toBeNull();
});

it('rejects a cookie that is not sealed data at all', function (): void {
    expect(resolveWorkosUser('not-a-sealed-cookie'))->toBeNull();
});

it('treats a missing cookie as a guest', function (): void {
    expect(resolveWorkosUser(null))->toBeNull();
});

it('treats an empty cookie as a guest', function (): void {
    expect(resolveWorkosUser(''))->toBeNull();
});

it('dispatches Impersonating when the token carries an act claim', function (): void {
    Event::fake([Impersonating::class]);

    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    $sealed = JwtFixture::sealedCookie(
        JwtFixture::sign(['act' => ['sub' => 'user_admin']]),
        impersonator: ['email' => 'admin@acme.com', 'reason' => 'support ticket 42'],
    );

    expect(resolveWorkosUser($sealed))->not->toBeNull();

    Event::assertDispatched(Impersonating::class, function (Impersonating $event): bool {
        return $event->impersonatorWorkosUserId === 'user_admin'
            && $event->impersonatorContext['email'] === 'admin@acme.com';
    });
});

it('does not dispatch Impersonating for an ordinary session', function (): void {
    Event::fake([Impersonating::class]);

    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    expect(resolveWorkosUser(JwtFixture::sealedCookie(JwtFixture::sign())))->not->toBeNull();

    Event::assertNotDispatched(Impersonating::class);
});
