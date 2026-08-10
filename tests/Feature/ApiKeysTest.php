<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Data\ApiKeySummary;
use Authkit\Authkit\Tests\Support\EmulateServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Organization;

// Test path: emulate — org-scoped keys are the covered path (validate, list,
// revoke; emulate 0.6 has NO org key create endpoint and no user-scoped key
// endpoints at all, so those cases live in ApiKeysMockedTest.php). Each test
// boots its own server against a dedicated seed file: an array-form apiKeys
// seed replaces the default auth allow-list, so this suite cannot share the
// package's main emulate fixture.

beforeEach(function (): void {
    $this->migratePackageDatabase();

    Route::middleware('auth:authkit-key')->get('/api-key-probe', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'principal' => $user::class,
            'permissions' => $user instanceof WorkosApiKeyActor ? $user->permissions : null,
            'can_read' => Gate::allows('posts:read'),
            'can_delete' => Gate::allows('posts:delete'),
        ]);
    });
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function startApiKeysEmulate(): EmulateServer
{
    $server = new EmulateServer(
        port: 4193,
        seedPath: __DIR__.'/../Fixtures/workos-emulate-api-keys.config.yaml',
    );
    $server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    return $server;
}

it('authenticates an org-scoped key through the guard and loads its permissions into Gate', function (): void {
    Log::spy();

    $this->server = startApiKeysEmulate();

    Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HACMEAPIKEYS']);

    // Bearer mode (the default): the seeded key resolves to a synthetic actor
    // wrapping the local org, and Gate honors exactly the key's permissions.
    $this->withHeader('Authorization', 'Bearer sk_test_acme_ci_key')
        ->getJson('/api-key-probe')
        ->assertOk()
        ->assertJson([
            'principal' => WorkosApiKeyActor::class,
            'permissions' => ['posts:read'],
            'can_read' => true,
            'can_delete' => false,
        ]);

    // A garbage key value gets WorkOS's 200-with-null answer → guard null → 401.
    $this->withHeader('Authorization', 'Bearer sk_test_not_a_real_key')
        ->getJson('/api-key-probe')
        ->assertUnauthorized();

    // Literal-header mode: same key via X-Api-Key once config switches.
    config()->set('authkit.api_keys.header', 'X-Api-Key');

    $this->withHeader('X-Api-Key', 'sk_test_acme_ci_key')
        ->getJson('/api-key-probe')
        ->assertOk();

    config()->set('authkit.api_keys.header', 'bearer');

    // Data shadow: a real emulate-issued key whose owner org has no local
    // projection row → 401 with the projection-missing warning, distinct
    // from the invalid-key silence above.
    $this->withHeader('Authorization', 'Bearer sk_test_shadow_key')
        ->getJson('/api-key-probe')
        ->assertUnauthorized();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'authkit: API key validated by WorkOS but no local organization projection exists')
        ->once();
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

it('lists org keys as valueless summaries and revocation kills the key on the very next request', function (): void {
    $this->server = startApiKeysEmulate();

    $organization = Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HACMEAPIKEYS']);

    $keys = $organization->listApiKeys();

    expect($keys)->toHaveCount(1)
        ->and($keys->first())->toBeInstanceOf(ApiKeySummary::class)
        ->and($keys->first()->name)->toBe('acme-ci')
        ->and($keys->first()->permissions)->toBe(['posts:read'])
        ->and(property_exists(ApiKeySummary::class, 'value'))->toBeFalse();

    // The key authenticates before revocation…
    $this->withHeader('Authorization', 'Bearer sk_test_acme_ci_key')
        ->getJson('/api-key-probe')
        ->assertOk();

    $organization->revokeApiKey($keys->first()->id);

    // …and is dead on the next request: no cache layer exists to serve a
    // stale allow, so revocation takes effect immediately (fresh
    // createValidation per request, by contract decision).
    $this->withHeader('Authorization', 'Bearer sk_test_acme_ci_key')
        ->getJson('/api-key-probe')
        ->assertUnauthorized();

    expect($organization->listApiKeys())->toHaveCount(0);
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
