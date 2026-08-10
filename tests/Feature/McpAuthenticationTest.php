<?php

declare(strict_types=1);

// Test path: MockHandler + local self-signed JWKS fixtures. The MockHandler
// serves the fixture's JWKS document over the fake wire; the fixture's RSA
// keypair signs test tokens locally — no wire call is needed to produce a
// token (same combination as the SessionSecurity suite).

use Authkit\Authkit\Exceptions\ConfigurationException;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class)->group('connect-mcp');

const MCP_DOMAIN = 'fixture.authkit.app';
const MCP_RESOURCE = 'https://mcp.fixture.test';

beforeEach(function (): void {
    config()->set('authkit.authkit_domain', MCP_DOMAIN);
    config()->set('authkit.mcp.resource_indicator', MCP_RESOURCE);

    Route::middleware('authkit.mcp')->post('/mcp-guarded', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'claims' => $request->attributes->get('authkit.mcp.claims'),
            'workos_id' => $user?->getAttribute('workos_id'),
        ]);
    });
});

function mcpJwksResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
}

/**
 * A token whose iss/aud satisfy the middleware's policy unless overridden.
 *
 * @param  array<string, mixed>  $claimOverrides
 * @param  array<string, mixed>  $headerOverrides
 */
function mcpToken(array $claimOverrides = [], array $headerOverrides = [], ?string $signingKeyPath = null): string
{
    return JwtFixture::sign(array_merge([
        'iss' => 'https://'.MCP_DOMAIN,
        'aud' => MCP_RESOURCE,
    ], $claimOverrides), $signingKeyPath, $headerOverrides);
}

it('passes a valid token through to the route with claims attached', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('claims.sub', 'user_fixture')
        ->assertJsonPath('claims.iss', 'https://'.MCP_DOMAIN)
        ->assertJsonPath('claims.aud', MCP_RESOURCE);

    // Exactly one JWKS fetch — the request-path does zero WorkOS API calls
    // beyond the (cacheable) key fetch (contract decision D9).
    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and($this->workosRequestHistory[0]['request']->getUri()->getHost())->toBe(MCP_DOMAIN)
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())->toBe('/oauth2/jwks');
});

it('challenges without an error param when no token was attempted', function (): void {
    $this->fakeWorkosResponses([]);

    $response = $this->postJson('/mcp-guarded');

    $response->assertUnauthorized();

    $challenge = $response->headers->get('WWW-Authenticate');

    expect($challenge)->toBe(sprintf('Bearer resource_metadata="%s"', url('/.well-known/oauth-protected-resource')))
        ->and($challenge)->not->toContain('error=');
});

it('treats a non-bearer Authorization header as no token attempted', function (): void {
    $this->fakeWorkosResponses([]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Basic dXNlcjpwYXNz']);

    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))->not->toContain('error=');
});

it('rejects a malformed bearer token that is not three JWT segments', function (): void {
    $this->fakeWorkosResponses([]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer not-a-jwt']);

    $response->assertUnauthorized();

    $challenge = $response->headers->get('WWW-Authenticate');

    expect($challenge)->toContain('error="invalid_token"')
        ->and($challenge)->toContain('resource_metadata="'.url('/.well-known/oauth-protected-resource').'"');

    expect($this->workosRequestHistory)->toBeEmpty();
});

it('rejects a forged alg:none token before the JWKS endpoint is ever hit', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken(headerOverrides: ['alg' => 'none'])]);

    $response->assertUnauthorized();

    // The allow-list check runs before signature verification — and before
    // any fetch — so the JWKS endpoint sees zero requests (failure mode
    // F-alg-confusion).
    expect($this->workosRequestHistory)->toBeEmpty();
});

it('rejects a forged alg:HS256 token', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken(headerOverrides: ['alg' => 'HS256'])]);

    $response->assertUnauthorized();

    expect($this->workosRequestHistory)->toBeEmpty();
});

it('force-refreshes the JWKS once for an unknown kid, then rejects', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse(), mcpJwksResponse()]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken(headerOverrides: ['kid' => 'rotated-away'])]);

    $response->assertUnauthorized();

    // Cold-cache fetch, then exactly one forced refresh (failure mode F9 —
    // the debounce keeps a bogus-kid flood from stampeding the endpoint).
    expect($this->workosRequestHistory)->toHaveCount(2);
});

it('rejects a token signed with a key the JWKS never advertised', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse(), mcpJwksResponse()]);

    $forged = mcpToken(signingKeyPath: JwtFixture::forgedKeyPath());

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.$forged])->assertUnauthorized();
});

it('rejects an expired token that is otherwise validly signed', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    $expired = mcpToken(['iat' => time() - 7200, 'exp' => time() - 3600]);

    $response = $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.$expired]);

    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))->toContain('error="invalid_token"');
});

it('rejects a validly signed token carrying the wrong issuer', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    // Concrete misconfiguration this catches: a staging-environment token
    // presented to a production-configured app (failure mode F-wrong-iss).
    $wrongIssuer = mcpToken(['iss' => 'https://staging.authkit.app']);

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.$wrongIssuer])->assertUnauthorized();
});

it('rejects a session-shaped token carrying no aud claim at all', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    // A legitimate AuthKit session token replayed against the MCP endpoint:
    // valid signature, valid issuer, no aud — the RFC 8707 check is the only
    // thing standing between it and the tools (failure mode F-wrong-aud).
    $sessionShaped = JwtFixture::sign(['iss' => 'https://'.MCP_DOMAIN]);

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.$sessionShaped])->assertUnauthorized();
});

it('rejects a token minted for a different MCP resource', function (): void {
    $this->fakeWorkosResponses([mcpJwksResponse()]);

    $otherResource = mcpToken(['aud' => 'https://other-mcp.fixture.test']);

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.$otherResource])->assertUnauthorized();
});

it('returns 503, not 401, when the JWKS is unreachable with a cold cache', function (): void {
    // Our infrastructure failing to verify must not read as "every client has
    // a bad token" (failure mode F-jwks-down).
    $this->fakeWorkosResponses([
        new RequestException('connection refused', new Psr7Request('GET', 'https://'.MCP_DOMAIN.'/oauth2/jwks')),
    ]);

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()])
        ->assertStatus(503);
});

it('returns 503 when the JWKS endpoint 5xxs with a cold cache', function (): void {
    $this->fakeWorkosResponses([new Response(500)]);

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()])
        ->assertStatus(503);
});

it('resolves the local user by sub when resolve_user is enabled', function (): void {
    $this->migratePackageDatabase();
    config()->set('authkit.mcp.resolve_user', true);
    UserFactory::new()->create(['email' => 'alice@acme.com', 'workos_id' => 'user_fixture']);

    $this->fakeWorkosResponses([mcpJwksResponse()]);

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()])
        ->assertOk()
        ->assertJsonPath('workos_id', 'user_fixture');
});

it('proceeds with claims only when no local user row matches the sub', function (): void {
    $this->migratePackageDatabase();
    config()->set('authkit.mcp.resolve_user', true);

    $this->fakeWorkosResponses([mcpJwksResponse()]);

    // Not a failure: a user-delegated token for a WorkOS user who has not
    // completed the first-login link yet (failure mode F15).
    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()])
        ->assertOk()
        ->assertJsonPath('workos_id', null)
        ->assertJsonPath('claims.sub', 'user_fixture');
});

it('proceeds without crashing for an M2M token carrying no sub', function (): void {
    $this->migratePackageDatabase();
    config()->set('authkit.mcp.resolve_user', true);

    $this->fakeWorkosResponses([mcpJwksResponse()]);

    // M2M client-credentials tokens carry no sub by design (F-m2m-no-sub).
    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken(['sub' => null])])
        ->assertOk()
        ->assertJsonPath('workos_id', null)
        ->assertJsonPath('claims.sub', null);
});

it('issues no database query at all when resolve_user is disabled', function (): void {
    $this->migratePackageDatabase();
    UserFactory::new()->create(['email' => 'alice@acme.com', 'workos_id' => 'user_fixture']);

    $this->fakeWorkosResponses([mcpJwksResponse()]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()])
        ->assertOk()
        ->assertJsonPath('workos_id', null);

    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

it('fails fast with a configuration exception when the resource indicator is blank', function (): void {
    config()->set('authkit.mcp.resource_indicator', '');

    $this->fakeWorkosResponses([]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/mcp-guarded', [], ['Authorization' => 'Bearer '.mcpToken()]))
        ->toThrow(ConfigurationException::class, 'authkit.authkit_domain and authkit.mcp.resource_indicator');
});

it('fails fast with a configuration exception when the authkit domain is blank', function (): void {
    config()->set('authkit.authkit_domain', null);

    $this->fakeWorkosResponses([]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/mcp-guarded'))
        ->toThrow(ConfigurationException::class);
});
