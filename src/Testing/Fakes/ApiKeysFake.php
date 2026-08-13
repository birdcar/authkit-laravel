<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Testing\Fakes\Support\ApiKeyRegistry;
use Authkit\Authkit\Testing\Fakes\Support\FakeWorkosClient;
use DateTimeImmutable;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Assert;
use WorkOS\WorkOS;

/**
 * In-memory WorkOS API keys. There is no ApiKeysManager class to extend —
 * the key surface lives on the model traits (HasApiKeys,
 * HasOrganizationApiKeys) and the authkit-key guard, all of which resolve
 * {@see WorkosClientManager} per call — so this fake IS a client manager: it
 * serves a client whose apiKeys()/userManagement() services run against a
 * local registry while every other service stays real.
 *
 * Key lifecycle covered offline: create (user- AND organization-scoped, the
 * surface `npx @workos/emulate` has no routes for), list, revoke, and the
 * guard's validate call — so a request carrying a fake key's value
 * authenticates through the REAL authkit-key guard machinery.
 */
final class ApiKeysFake implements WorkosClientManager
{
    private readonly ApiKeyRegistry $registry;

    public function __construct()
    {
        $this->registry = new ApiKeyRegistry;
    }

    /**
     * Built fresh per call (never memoized) so a mid-test HandlerStack swap
     * by the MockHandler harness is always honored. The real client manager
     * is itself a memoizing singleton — the per-call freshness contract in
     * production belongs to the DEPENDENT bindings (UserManagement,
     * SessionManager, VaultManager, ...), which the harness composes with by
     * forgetting the manager instance; this fake gets the same effect by
     * never memoizing at all.
     */
    public function client(): WorkOS
    {
        $config = app(Repository::class);
        $emulate = (bool) $config->get('authkit.emulate.enabled', false);

        // Mirrors Support\WorkosClientManager::fromConfig(), which keeps its
        // constructor arguments private — drift here would only surface as
        // inherited (unfaked) services talking to the wrong host.
        return new FakeWorkosClient(
            registry: $this->registry,
            apiKey: (string) ($emulate
                ? $config->get('authkit.emulate.api_key', 'sk_test_default')
                : $config->get('authkit.api_key', '')),
            clientId: (string) $config->get('authkit.client_id', ''),
            baseUrl: (string) ($emulate
                ? $config->get('authkit.emulate.base_url', 'http://localhost:4100')
                : $config->get('authkit.base_url', 'https://api.workos.com')),
            timeout: (int) $config->get('authkit.timeout', 60),
            maxRetries: (int) $config->get('authkit.max_retries', 3),
            handler: app()->bound(HandlerStack::class) ? app(HandlerStack::class) : null,
        );
    }

    /**
     * @param  (callable(array{id: string, value: string, name: string, owner_type: 'organization'|'user', owner_id: string, organization_id: string|null, permissions: list<string>, expires_at: DateTimeImmutable|null, created_at: DateTimeImmutable, revoked: bool}): bool)|null  $callback
     */
    public function assertCreated(?callable $callback = null): void
    {
        $matches = array_filter(
            $this->registry->all(),
            static fn (array $key): bool => $callback === null || $callback($key),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected an API key to be created%s but none matched. %s',
            $callback !== null ? ' passing the given callback' : '',
            $this->describeKeys(),
        ));
    }

    public function assertRevoked(string $apiKeyId): void
    {
        Assert::assertContains(
            $apiKeyId,
            $this->registry->revokedIds(),
            "Expected API key [{$apiKeyId}] to be revoked, but it was not.",
        );
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty(
            $this->registry->all(),
            sprintf('Expected no API keys to be created. %s', $this->describeKeys()),
        );
    }

    /**
     * Every key the registry holds, oldest first — including revoked ones.
     *
     * @return list<array{id: string, value: string, name: string, owner_type: 'organization'|'user', owner_id: string, organization_id: string|null, permissions: list<string>, expires_at: DateTimeImmutable|null, created_at: DateTimeImmutable, revoked: bool}>
     */
    public function recordedKeys(): array
    {
        return $this->registry->all();
    }

    private function describeKeys(): string
    {
        $keys = $this->registry->all();

        if ($keys === []) {
            return 'No API keys were created.';
        }

        $lines = array_map(
            static fn (array $key): string => "[{$key['name']}] for {$key['owner_type']} [{$key['owner_id']}]",
            $keys,
        );

        return 'Created keys: '.implode(', ', $lines).'.';
    }
}
