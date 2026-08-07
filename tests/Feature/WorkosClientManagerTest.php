<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use Authkit\Authkit\Support\WorkosClientManager;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Config\Repository;
use WorkOS\WorkOS;

uses(UsesWorkosMockHandler::class);

/**
 * Minimal payload satisfying WorkOS\Resource\User::fromArray().
 */
function authkitUserResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => 'user_123',
        'email' => 'alice@acme.com',
        'email_verified' => true,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]));
}

afterEach(function (): void {
    putenv('WORKOS_API_KEY');
    putenv('WORKOS_CLIENT_ID');
});

it('binds the client manager as a singleton', function (): void {
    expect(app(WorkosClientManagerContract::class))
        ->toBeInstanceOf(WorkosClientManager::class)
        ->toBe(app(WorkosClientManagerContract::class));
});

it('caches the constructed WorkOS client', function (): void {
    $manager = app(WorkosClientManagerContract::class);

    expect($manager->client())
        ->toBeInstanceOf(WorkOS::class)
        ->toBe($manager->client());
});

it('builds a client from config without throwing', function (): void {
    expect(app(WorkosClientManagerContract::class)->client()->userManagement())->not->toBeNull();
});

it('sends the configured api key rather than the SDK env fallback', function (): void {
    putenv('WORKOS_API_KEY=sk_should_never_be_used');
    config()->set('authkit.api_key', 'sk_from_config');

    $this->fakeWorkosResponses([authkitUserResponse()]);

    app(WorkosClientManagerContract::class)->client()->userManagement()->getUser('user_123');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getHeaderLine('Authorization'))->toBe('Bearer sk_from_config');
});

it('uses the top level base url when emulate is disabled', function (): void {
    config()->set('authkit.emulate.enabled', false);
    config()->set('authkit.base_url', 'https://api.workos.com');
    config()->set('authkit.api_key', 'sk_live_config');

    $this->fakeWorkosResponses([authkitUserResponse()]);

    app(WorkosClientManagerContract::class)->client()->userManagement()->getUser('user_123');

    $request = $this->workosRequestHistory[0]['request'];

    expect((string) $request->getUri())->toStartWith('https://api.workos.com');
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer sk_live_config');
});

it('overrides base url and api key from the emulate keys when emulate is enabled', function (): void {
    config()->set('authkit.api_key', 'sk_live_config');
    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', 'http://127.0.0.1:4321');
    config()->set('authkit.emulate.api_key', 'sk_test_emulate');

    $this->fakeWorkosResponses([authkitUserResponse()]);

    app(WorkosClientManagerContract::class)->client()->userManagement()->getUser('user_123');

    $request = $this->workosRequestHistory[0]['request'];

    expect((string) $request->getUri())->toStartWith('http://127.0.0.1:4321');
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer sk_test_emulate');
});

it('resolves every constructor argument from the config repository', function (): void {
    $config = app(Repository::class);
    $config->set('authkit.api_key', 'sk_unit');
    $config->set('authkit.client_id', 'client_unit');
    $config->set('authkit.base_url', 'https://example.test');
    $config->set('authkit.timeout', 12);
    $config->set('authkit.max_retries', 1);
    $config->set('authkit.emulate.enabled', false);

    expect(WorkosClientManager::fromConfig($config)->client())->toBeInstanceOf(WorkOS::class);
});

it('rebinds the handler stack when responses are faked twice in one test', function (): void {
    $this->fakeWorkosResponses([new Response(500)]);
    $this->fakeWorkosResponses([authkitUserResponse()]);

    $user = app(WorkosClientManagerContract::class)->client()->userManagement()->getUser('user_123');

    expect($user->id)->toBe('user_123');
    expect($this->workosRequestHistory)->toHaveCount(1);
});
