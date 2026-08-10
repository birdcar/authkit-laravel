<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\JwtClaimsValidator;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Client;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;
use WorkOS\Service\UserManagement;

/**
 * The contract's whole-package promise as one emulate-backed journey:
 * login → local user linked (workos_id stored, external_id set in WorkOS) →
 * org projected via the claims-driven login listener → $user->can() honors
 * the JWT permission claims. Everything runs against a real (emulated)
 * WorkOS on the wire — no MockHandler anywhere in this file.
 *
 * WithWorkbench boots the real workbench app (routes, provider, demo
 * surface) rather than the bare package skeleton — the contract's own check
 * is "the suite runs the workbench app against workos/emulate".
 *
 * Scoped to RBAC ($user->can() from claims) deliberately: the contract's
 * acceptance criterion names RBAC, not FGA — FGA has its own Phase 5 suite
 * and workbench route.
 */
uses(WithWorkbench::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

/**
 * Runs one full hosted-login round trip against the emulator and returns the
 * sealed session cookie value the callback set.
 */
function acceptanceLogin(): string
{
    $test = test();

    // Step 1: the package's login route redirects to the (emulated)
    // WorkOS-hosted authorization URL, stashing PKCE state in the session.
    $login = $test->get('/authkit/login');
    $login->assertRedirect();

    $authorizeUrl = (string) $login->headers->get('Location');
    expect($authorizeUrl)->toStartWith($test->server->baseUrl().'/user_management/authorize');

    $state = session('authkit.pkce.state');
    $verifier = session('authkit.pkce.code_verifier');
    expect($state)->toBeString()->and($verifier)->toBeString();

    // Step 2: follow the hosted hop for real — the emulator resolves the
    // seeded user and 302s back to the redirect_uri with an auth code.
    $hop = (new Client(['allow_redirects' => false]))->get($authorizeUrl);
    expect($hop->getStatusCode())->toBe(302);

    parse_str((string) parse_url($hop->getHeaderLine('Location'), PHP_URL_QUERY), $callbackQuery);
    expect($callbackQuery['state'] ?? null)->toBe($state, 'state must round-trip through the hosted flow');
    $code = $callbackQuery['code'] ?? null;
    expect($code)->toBeString();

    // Step 3: the package callback exchanges the code (PKCE verified by the
    // emulator) and seals the session cookie.
    assert(is_string($state) && is_string($verifier) && is_string($code));
    $callback = $test->withSession([
        'authkit.pkce.state' => $state,
        'authkit.pkce.code_verifier' => $verifier,
    ])->get('/authkit/callback?'.http_build_query(['code' => $code, 'state' => $state]));
    $callback->assertRedirect();

    $cookie = $callback->getCookie('authkit_session', false);
    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

test('Acceptance: login links the user, projects the org, and RBAC reads from claims', function (): void {
    $this->server = new EmulateServer(
        port: 4189,
        seedPath: __DIR__.'/../Fixtures/workos-emulate-acceptance.config.yaml',
    );
    $this->server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $this->server->baseUrl());
    // The emulator only redirects back to localhost URIs.
    config()->set('authkit.redirect_uri', 'http://localhost/authkit/callback');
    // Issuer enforcement is opt-in (TestCase pins the MockHandler fixture
    // issuer; the emulator mints its own base URL) — cleared here so the
    // audience/client_id check is the one doing the work, as in production
    // before a token audit confirms the canonical issuer.
    config()->set('authkit.jwt.issuer', null);
    app()->forgetInstance(WorkosClientManager::class);
    app()->forgetInstance(JwtClaimsValidator::class);

    // Precondition: the guard rejects an anonymous hit on the dashboard
    // (401, not a crash) — the demo surface never leaks unauthenticated.
    $this->getJson('/dashboard')->assertStatus(401);

    // ---- First login: cold emulator, no local rows anywhere ----
    $sealed = acceptanceLogin();

    // Local user linked: workos_id stored on the projection row.
    $user = User::query()->firstWhere('email', 'acceptance-trial@example.test');
    expect($user)->not->toBeNull()
        ->and($user?->getAttribute('workos_id'))->toBe('user_01ACCEPTANCETRIAL000000000');
    assert($user instanceof User);

    // External-id linkage happened server-side against the emulator — confirm
    // by re-fetching from WorkOS through the package's container-bound
    // UserManagement service (the sanctioned test-side accessor).
    $linked = app(UserManagement::class)->getUserByExternalId((string) $user->getKey());
    expect($linked->id)->toBe('user_01ACCEPTANCETRIAL000000000');

    // Org context live: the login-time projection listener created the local
    // org row and membership from the org-scoped claims, and the
    // organizations() relation resolves through them on WorkOS ids.
    $organization = $user->organizations()->first();
    expect($organization)->not->toBeNull()
        ->and($organization?->getAttribute('workos_id'))->toBe('org_01ACCEPTANCEDEMO0000000000');

    // Claims-presence precondition (spec failure mode: a claims-absence
    // regression must be distinguishable from an RBAC-logic failure).
    // withCredentials(): JSON test requests drop test cookies without it.
    $dashboard = $this->withCredentials()
        ->withUnencryptedCookie('authkit_session', $sealed)
        ->getJson('/dashboard');
    $dashboard->assertOk()
        ->assertJsonPath('user.workos_id', 'user_01ACCEPTANCETRIAL000000000')
        ->assertJsonPath('organization.workos_id', 'org_01ACCEPTANCEDEMO0000000000')
        ->assertJsonPath('claims.role', 'admin')
        ->assertJsonPath('claims.permissions', ['posts.manage']);

    // RBAC honors the JWT permission claims — zero HTTP per check, proven
    // through the real guard on a real request (the claims Gate hook reads
    // the sealed session, so a bare actingAs() would prove nothing).
    $this->withUnencryptedCookie('authkit_session', $sealed)->getJson('/demo/rbac')
        ->assertOk()
        ->assertJsonPath('can_manage_posts', true)
        ->assertJsonPath('can_unknown_permission', false);

    // ---- Second login: warm emulator, all rows already exist ----
    // Only durable identifiers are asserted across logins: the emulator
    // rotates refresh tokens on every authenticate by design.
    $sealedAgain = acceptanceLogin();

    expect(User::query()->where('email', 'acceptance-trial@example.test')->count())->toBe(1)
        ->and(Organization::query()->where('workos_id', 'org_01ACCEPTANCEDEMO0000000000')->count())->toBe(1)
        ->and(WorkosMembership::query()->where('user_id', 'user_01ACCEPTANCETRIAL000000000')->count())->toBe(1);

    $this->withUnencryptedCookie('authkit_session', $sealedAgain)->getJson('/demo/rbac')
        ->assertOk()
        ->assertJsonPath('can_manage_posts', true);
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
