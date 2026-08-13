<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Data\ApiKeyCreated;
use Authkit\Authkit\Testing\Fakes\ApiKeysFake;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\Organization;
use Workbench\Database\Factories\UserFactory;
use WorkOS\HttpClient;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function apiKeysFake(): ApiKeysFake
{
    $fake = new ApiKeysFake;

    app()->instance(WorkosClientManager::class, $fake);

    return $fake;
}

it('creates and lists user-scoped keys through the HasApiKeys trait', function (): void {
    $fake = apiKeysFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_key_owner']);

    $created = $user->createApiKey('CI key', 'org_acme', ['tasks.read']);

    expect($created)->toBeInstanceOf(ApiKeyCreated::class)
        ->and($created->value)->toStartWith('sk_fake_');

    $summaries = $user->listApiKeys();

    expect($summaries)->toHaveCount(1)
        ->and($summaries->first()?->name)->toBe('CI key')
        ->and($user->listApiKeys('org_other'))->toHaveCount(0)
        ->and($user->listApiKeys('org_acme'))->toHaveCount(1);

    $fake->assertCreated(fn (array $key): bool => $key['owner_type'] === 'user'
        && $key['owner_id'] === 'user_key_owner'
        && $key['organization_id'] === 'org_acme');
});

it('creates and lists organization-scoped keys through HasOrganizationApiKeys', function (): void {
    $fake = apiKeysFake();
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_key_owner']);

    $created = $organization->createApiKey('Provisioning key', ['members.manage']);

    expect($created->value)->toStartWith('sk_fake_')
        ->and($organization->listApiKeys())->toHaveCount(1);

    $fake->assertCreated(fn (array $key): bool => $key['owner_type'] === 'organization'
        && $key['owner_id'] === 'org_key_owner');
});

it('revokes keys and drops them from listings and validation', function (): void {
    $fake = apiKeysFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_key_owner']);

    $created = $user->createApiKey('CI key', 'org_acme');

    $user->revokeApiKey($created->id);

    expect($user->listApiKeys())->toHaveCount(0);

    $fake->assertRevoked($created->id);
});

it('authenticates a request through the real authkit-key guard', function (): void {
    apiKeysFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_key_owner']);

    $created = $user->createApiKey('CI key', 'org_acme', ['tasks.read']);

    Route::middleware('auth:authkit-key')->get('/testing/key-me', function (): array {
        $principal = auth()->user();

        return [
            'id' => $principal?->getAuthIdentifier(),
            'can_read' => $principal?->can('tasks.read'),
            'can_write' => $principal?->can('tasks.write'),
        ];
    });

    $this->getJson('/testing/key-me', ['Authorization' => "Bearer {$created->value}"])
        ->assertOk()
        ->assertJson([
            'id' => $user->getKey(),
            'can_read' => true,
            'can_write' => false,
        ]);
});

it('resolves an organization key to a WorkosApiKeyActor principal', function (): void {
    apiKeysFake();
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_key_owner']);

    $created = $organization->createApiKey('Provisioning key', ['members.manage']);

    Route::middleware('auth:authkit-key')->get('/testing/key-actor', function (): array {
        $principal = auth()->user();

        return [
            'actor' => $principal instanceof WorkosApiKeyActor,
            // WorkosApiKeyActor is a plain Authenticatable (no Authorizable
            // trait), so authorization goes through the Gate directly.
            'can_manage' => $principal !== null && Gate::forUser($principal)->allows('members.manage'),
        ];
    });

    $this->getJson('/testing/key-actor', ['Authorization' => "Bearer {$created->value}"])
        ->assertOk()
        ->assertJson(['actor' => true, 'can_manage' => true]);
});

it('rejects unknown, revoked, and expired keys like production', function (): void {
    apiKeysFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_key_owner']);

    Route::middleware('auth:authkit-key')->get('/testing/key-guarded', fn (): array => ['ok' => true]);

    $this->getJson('/testing/key-guarded', ['Authorization' => 'Bearer sk_fake_unknown'])->assertUnauthorized();

    $revoked = $user->createApiKey('Revoked key', 'org_acme');
    $user->revokeApiKey($revoked->id);

    $this->getJson('/testing/key-guarded', ['Authorization' => "Bearer {$revoked->value}"])->assertUnauthorized();

    $expired = $user->createApiKey('Expired key', 'org_acme', expiresAt: new DateTimeImmutable('-1 hour'));

    $this->getJson('/testing/key-guarded', ['Authorization' => "Bearer {$expired->value}"])->assertUnauthorized();
});

it('refuses to revoke a key that never existed', function (): void {
    apiKeysFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_key_owner']);

    expect(fn () => $user->revokeApiKey('api_key_never_created'))
        ->toThrow(InvalidArgumentException::class, 'exists to revoke');
});

it('builds its client from the same config the real manager reads', function (): void {
    config()->set('authkit.api_key', 'sk_parity_check');
    config()->set('authkit.base_url', 'https://parity.workos.test');

    $fake = apiKeysFake();
    $real = Authkit\Authkit\Support\WorkosClientManager::fromConfig(app(Repository::class));

    // ApiKeysFake::client() duplicates fromConfig()'s key-for-key reads (the
    // real manager keeps them private) — this pins the two together so drift
    // fails loudly instead of surfacing as inherited services talking to the
    // wrong host.
    $inspect = function (object $client): array {
        $httpClient = (new ReflectionClass(WorkOS\WorkOS::class))->getProperty('httpClient')->getValue($client);
        $reflection = new ReflectionClass(HttpClient::class);

        return [
            'api_key' => $reflection->getProperty('apiKey')->getValue($httpClient),
            'base_url' => $reflection->getProperty('baseUrl')->getValue($httpClient),
        ];
    };

    expect($inspect($fake->client()))->toBe($inspect($real->client()));
});

it('fails assertions with readable messages', function (): void {
    $fake = apiKeysFake();

    $fake->assertNothingCreated();

    expect(fn () => $fake->assertCreated())
        ->toThrow(AssertionFailedError::class, 'No API keys were created');

    $user = UserFactory::new()->create(['workos_id' => 'user_key_owner']);
    $user->createApiKey('CI key', 'org_acme');

    expect(fn () => $fake->assertNothingCreated())
        ->toThrow(AssertionFailedError::class, '[CI key] for user [user_key_owner]');
});
