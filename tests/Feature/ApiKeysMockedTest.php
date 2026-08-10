<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\Data\ApiKeyCreated;
use Authkit\Authkit\Data\ApiKeySummary;
use Authkit\Authkit\Exceptions\MissingModelConfigurationException;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler — user-scoped API keys have no emulate coverage
// (emulate 0.6 ships no user-scoped key endpoints), and emulate also lacks the
// org key CREATE endpoint, so both create paths and all failure-injection
// cases live here. The org-scoped guard journey against a real wire is in
// ApiKeysTest.php.

beforeEach(function (): void {
    $this->migratePackageDatabase();

    Route::middleware('auth:authkit-key')->get('/api-key-probe', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'principal' => $user::class,
            'auth_id' => $user->getAuthIdentifier(),
            'permissions' => $user instanceof WorkosApiKeyActor
                ? $user->permissions
                : $user->apiKeyPermissions(),
            'can_read' => Gate::allows('posts:read'),
            'can_delete' => Gate::allows('posts:delete'),
        ]);
    });
});

/**
 * @return array<string, mixed>
 */
function apiKeysMockUserKeyJson(array $overrides = []): array
{
    return array_merge([
        'object' => 'api_key',
        'id' => 'api_key_01HUSERKEY',
        'owner' => [
            'type' => 'user',
            'id' => 'user_01HKEYOWNER',
            'organization_id' => 'org_01HKEYORG',
        ],
        'name' => 'ci-bot',
        'obfuscated_value' => 'sk_live_...abcd',
        'last_used_at' => null,
        'expires_at' => null,
        'permissions' => ['posts:read'],
        'created_at' => '2026-08-01T00:00:00.000Z',
        'updated_at' => '2026-08-01T00:00:00.000Z',
    ], $overrides);
}

function apiKeysMockValidationResponse(?array $apiKey): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['api_key' => $apiKey]));
}

it('resolves the local user for a valid user-scoped key and loads its permissions into Gate', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson())]);

    $response = $this->withHeader('Authorization', 'Bearer sk_test_user_key')->getJson('/api-key-probe');

    $response->assertOk()->assertJson([
        'principal' => User::class,
        'permissions' => ['posts:read'],
        'can_read' => true,
        'can_delete' => false,
    ]);

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getUri()->getPath())->toBe('/api_keys/validations')
        ->and((string) $request->getBody())->json()->toMatchArray(['value' => 'sk_test_user_key']);
});

it('returns null permissions for a user the authkit-key guard never touched', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);

    expect($user->apiKeyPermissions())->toBeNull();
});

it('resolves a WorkosApiKeyActor for a valid org-scoped key', function (): void {
    Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HKEYORG']);

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson([
        'id' => 'api_key_01HORGKEY',
        'owner' => ['type' => 'organization', 'id' => 'org_01HKEYORG'],
        'permissions' => ['posts:read', 'posts:delete'],
    ]))]);

    $this->withHeader('Authorization', 'Bearer sk_test_org_key')
        ->getJson('/api-key-probe')
        ->assertOk()
        ->assertJson([
            'principal' => WorkosApiKeyActor::class,
            'auth_id' => 'api_key_01HORGKEY',
            'permissions' => ['posts:read', 'posts:delete'],
            'can_read' => true,
            'can_delete' => true,
        ]);
});

it('rejects a request with no key at all without calling WorkOS', function (): void {
    $this->fakeWorkosResponses([]);

    $this->getJson('/api-key-probe')->assertUnauthorized();

    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('rejects a revoked or unknown key — WorkOS answers 200 with api_key null — logging nothing', function (): void {
    Log::spy();

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(null)]);

    $this->withHeader('Authorization', 'Bearer sk_test_revoked')->getJson('/api-key-probe')->assertUnauthorized();

    // Membership-deletion auto-revocation arrives as this exact same shape;
    // nothing app-side special-cases it, and neither case is warning-worthy.
    Log::shouldNotHaveReceived('warning');
});

it('fails closed with a warning distinct from the invalid-key case when WorkOS is down', function (): void {
    Log::spy();

    config()->set('authkit.max_retries', 0);

    $this->fakeWorkosResponses([new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
        'message' => 'Internal server error',
    ]))]);

    $this->withHeader('Authorization', 'Bearer sk_test_whatever')->getJson('/api-key-probe')->assertUnauthorized();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'authkit: API key validation call failed')
        ->once();
});

it('rejects a validated user key whose local user projection is missing, with a data-shadow warning', function (): void {
    Log::spy();

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson([
        'owner' => ['type' => 'user', 'id' => 'user_01HNEVERSEEN', 'organization_id' => 'org_01HKEYORG'],
    ]))]);

    $this->withHeader('Authorization', 'Bearer sk_test_shadow')->getJson('/api-key-probe')->assertUnauthorized();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'authkit: API key validated by WorkOS but no local user projection exists')
        ->once();
});

it('rejects a validated org key whose local organization projection is missing, with a data-shadow warning', function (): void {
    Log::spy();

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson([
        'owner' => ['type' => 'organization', 'id' => 'org_01HNEVERSEEN'],
    ]))]);

    $this->withHeader('Authorization', 'Bearer sk_test_shadow')->getJson('/api-key-probe')->assertUnauthorized();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'authkit: API key validated by WorkOS but no local organization projection exists')
        ->once();
});

it('reads the key from a literal header when authkit.api_keys.header names one', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);

    config()->set('authkit.api_keys.header', 'X-Api-Key');

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson())]);

    $this->withHeader('X-Api-Key', 'sk_test_header_key')->getJson('/api-key-probe')->assertOk();

    expect((string) $this->workosRequestHistory[0]['request']->getBody())
        ->json()->toMatchArray(['value' => 'sk_test_header_key']);
});

it('ignores the Authorization header entirely while a literal header is configured', function (): void {
    config()->set('authkit.api_keys.header', 'X-Api-Key');

    $this->fakeWorkosResponses([]);

    $this->withHeader('Authorization', 'Bearer sk_test_wrong_place')->getJson('/api-key-probe')->assertUnauthorized();

    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('throws the config-naming exception when no user model is configured', function (): void {
    config()->set('auth.providers.workos.model', null);
    config()->set('auth.providers.users.model', null);

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson())]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader('Authorization', 'Bearer sk_test_user_key')->getJson('/api-key-probe'))
        ->toThrow(MissingModelConfigurationException::class, 'auth.providers.workos.model');
});

it('throws the config-naming exception when no organization model is configured', function (): void {
    config()->set('authkit.organization.model', null);

    $this->fakeWorkosResponses([apiKeysMockValidationResponse(apiKeysMockUserKeyJson([
        'owner' => ['type' => 'organization', 'id' => 'org_01HKEYORG'],
    ]))]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader('Authorization', 'Bearer sk_test_org_key')->getJson('/api-key-probe'))
        ->toThrow(MissingModelConfigurationException::class, 'authkit.organization.model');
});

it('creates a user-scoped key sending the same organization_id for a model or a raw string', function (
    bool $passModel,
): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);
    $organization = Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HKEYORG']);

    $this->fakeWorkosResponses([new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(
        apiKeysMockUserKeyJson(['value' => 'sk_live_raw_secret']),
    ))]);

    $created = $user->createApiKey(
        'ci-bot',
        $passModel ? $organization : 'org_01HKEYORG',
        permissions: ['posts:read'],
    );

    expect($created)->toBeInstanceOf(ApiKeyCreated::class)
        ->and($created->value)->toBe('sk_live_raw_secret')
        ->and($created->permissions)->toBe(['posts:read']);

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getUri()->getPath())->toBe('/user_management/users/user_01HKEYOWNER/api_keys')
        ->and((string) $request->getBody())->json()->toMatchArray([
            'name' => 'ci-bot',
            'organization_id' => 'org_01HKEYORG',
            'permissions' => ['posts:read'],
        ]);
})->with(['organization model' => [true], 'raw organization id string' => [false]]);

it('creates an org-scoped key against the org endpoint — no emulate coverage exists for create', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HKEYORG']);

    $this->fakeWorkosResponses([new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(
        apiKeysMockUserKeyJson([
            'owner' => ['type' => 'organization', 'id' => 'org_01HKEYORG'],
            'value' => 'sk_live_org_secret',
        ]),
    ))]);

    $created = $organization->createApiKey('org-bot', permissions: ['posts:read']);

    expect($created->value)->toBe('sk_live_org_secret');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getUri()->getPath())->toBe('/organizations/org_01HKEYORG/api_keys')
        ->and((string) $request->getBody())->json()->toMatchArray(['name' => 'org-bot']);
});

it('passes an idempotency key through to the wire so a retried create cannot mint two keys', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);

    $this->fakeWorkosResponses([new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(
        apiKeysMockUserKeyJson(['value' => 'sk_live_raw_secret']),
    ))]);

    $user->createApiKey('ci-bot', 'org_01HKEYORG', idempotencyKey: 'create-key-attempt-1');

    expect($this->workosRequestHistory[0]['request']->getHeaderLine('Idempotency-Key'))
        ->toBe('create-key-attempt-1');
});

it('refuses to create a key for a user that has never synced with WorkOS', function (): void {
    $user = UserFactory::new()->create(['workos_id' => null]);

    $this->fakeWorkosResponses([]);

    expect(fn () => $user->createApiKey('ci-bot', 'org_01HKEYORG'))
        ->toThrow(RuntimeException::class, 'has no workos_id');
});

it('returns create results that carry the value exactly once: listings structurally cannot expose it', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);

    $this->fakeWorkosResponses([
        new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(
            apiKeysMockUserKeyJson(['value' => 'sk_live_raw_secret']),
        )),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'object' => 'list',
            'data' => [apiKeysMockUserKeyJson()],
            'list_metadata' => ['before' => null, 'after' => null],
        ])),
    ]);

    $created = $user->createApiKey('ci-bot', 'org_01HKEYORG');
    $listed = $user->listApiKeys();

    expect(property_exists($created, 'value'))->toBeTrue()
        ->and($listed)->toHaveCount(1)
        ->and($listed->first())->toBeInstanceOf(ApiKeySummary::class)
        ->and(property_exists(ApiKeySummary::class, 'value'))->toBeFalse()
        ->and($listed->first()->obfuscatedValue)->toBe('sk_live_...abcd');
});

it('scopes a user key listing to an organization when one is given', function (): void {
    $user = UserFactory::new()->create(['workos_id' => 'user_01HKEYOWNER']);

    $this->fakeWorkosResponses([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => [],
        'list_metadata' => ['before' => null, 'after' => null],
    ]))]);

    $user->listApiKeys('org_01HKEYORG');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getUri()->getPath())->toBe('/user_management/users/user_01HKEYOWNER/api_keys');
    parse_str($request->getUri()->getQuery(), $query);
    expect($query)->toMatchArray(['organization_id' => 'org_01HKEYORG']);
});

it('revokes a key by id from either owner trait — the endpoint is owner-agnostic', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HKEYORG']);

    $this->fakeWorkosResponses([new Response(204, [], '')]);

    $organization->revokeApiKey('api_key_01HORGKEY');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('DELETE')
        ->and($request->getUri()->getPath())->toBe('/api_keys/api_key_01HORGKEY');
});
