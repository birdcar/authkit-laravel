<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Pipes\AuthMethod;
use Authkit\Authkit\Pipes\ConnectedAccountState;
use Authkit\Authkit\Pipes\Data\ConnectedAccountData;
use Authkit\Authkit\Pipes\Data\PipeAccessTokenData;
use Authkit\Authkit\Pipes\Data\ProviderConfigurationData;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Authkit\Authkit\Pipes\PipesManager;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * An in-memory {@see PipesManager}: connected accounts are fixtures, tokens
 * are scripted values, and both named business exceptions are triggerable —
 * so a test can walk connect → use → needs-reauth → reconnect without a
 * provider OAuth app or a WorkOS environment.
 *
 * Production parity where it protects the consumer: requesting a token for a
 * provider that was never {@see connect()}ed throws
 * {@see PipesAccountNotConnectedException} exactly like the real manager.
 */
final class PipesFake extends PipesManager
{
    /** @var array<string, ConnectedAccountData> keyed by "userId|providerSlug" */
    private array $accounts = [];

    /** @var array<string, string> scripted token values keyed by "userId|providerSlug" */
    private array $tokens = [];

    /** @var array<string, array{missing_scopes: list<string>, url: string}> keyed by "userId|providerSlug" */
    private array $reauthorizations = [];

    /** @var array<string, ProviderConfigurationData> keyed by "organizationId|providerSlug" */
    private array $providerConfigurations = [];

    /** @var list<array{user_id: string, provider_slug: string, organization_id: string|null}> */
    private array $tokenRequests = [];

    private int $sequence = 0;

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    /**
     * Fixture a connected account for a user. Extra attributes: `state`
     * (ConnectedAccountState), `scopes`, `organization_id`, `provider_name`,
     * `auth_method` (AuthMethod).
     *
     * @param  array{state?: ConnectedAccountState, scopes?: list<string>, organization_id?: string|null, provider_name?: string|null, auth_method?: AuthMethod|null}  $attributes
     */
    public function connect(string $userId, string $providerSlug, array $attributes = []): ConnectedAccountData
    {
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $account = new ConnectedAccountData(
            id: 'connected_account_fake_'.++$this->sequence,
            providerSlug: $providerSlug,
            providerName: $attributes['provider_name'] ?? ucfirst($providerSlug),
            userId: $userId,
            organizationId: $attributes['organization_id'] ?? null,
            state: $attributes['state'] ?? ConnectedAccountState::Connected,
            scopes: $attributes['scopes'] ?? [],
            authMethod: $attributes['auth_method'] ?? null,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->accounts[$this->key($userId, $providerSlug)] = $account;

        return $account;
    }

    /**
     * Remove a fixture account — subsequent accessToken() calls throw
     * PipesAccountNotConnectedException again.
     */
    public function disconnect(string $userId, string $providerSlug): void
    {
        unset(
            $this->accounts[$this->key($userId, $providerSlug)],
            $this->tokens[$this->key($userId, $providerSlug)],
            $this->reauthorizations[$this->key($userId, $providerSlug)],
        );
    }

    /**
     * Pin the token value accessToken() serves for one connected account.
     */
    public function scriptAccessToken(string $userId, string $providerSlug, string $token): self
    {
        $this->tokens[$this->key($userId, $providerSlug)] = $token;

        return $this;
    }

    /**
     * Make the next accessToken() call for this account throw
     * PipesReauthorizationRequiredException, carrying $missingScopes and a
     * stubbed reauthorization URL.
     *
     * @param  list<string>  $missingScopes
     */
    public function requireReauthorization(string $userId, string $providerSlug, array $missingScopes = [], ?string $url = null): self
    {
        $this->reauthorizations[$this->key($userId, $providerSlug)] = [
            'missing_scopes' => $missingScopes,
            'url' => $url ?? "https://fake.workos.test/pipes/authorize/{$providerSlug}",
        ];

        return $this;
    }

    /**
     * @return Collection<int, ConnectedAccountData>
     */
    public function connectedAccounts(string $userId, ?string $organizationId = null): Collection
    {
        return Collection::make($this->accounts)
            ->filter(static fn (ConnectedAccountData $account): bool => $account->userId === $userId
                && ($organizationId === null || $account->organizationId === $organizationId))
            ->values();
    }

    public function accessToken(string $userId, string $providerSlug, ?string $organizationId = null): PipeAccessTokenData
    {
        $this->tokenRequests[] = [
            'user_id' => $userId,
            'provider_slug' => $providerSlug,
            'organization_id' => $organizationId,
        ];

        $key = $this->key($userId, $providerSlug);
        $account = $this->accounts[$key] ?? null;

        if ($account === null) {
            throw PipesAccountNotConnectedException::forProvider($providerSlug, $userId);
        }

        $reauthorization = $this->reauthorizations[$key] ?? null;

        if ($reauthorization !== null || $account->state === ConnectedAccountState::NeedsReauthorization) {
            throw PipesReauthorizationRequiredException::forProvider(
                providerSlug: $providerSlug,
                userId: $userId,
                organizationId: $organizationId,
                missingScopes: $reauthorization['missing_scopes'] ?? [],
                reauthorizationUrl: $reauthorization['url'] ?? "https://fake.workos.test/pipes/authorize/{$providerSlug}",
            );
        }

        return new PipeAccessTokenData(
            accessToken: $this->tokens[$key] ?? 'pipes_token_fake_'.$providerSlug,
            expiresAt: new DateTimeImmutable('+1 hour'),
            scopes: $account->scopes,
            missingScopes: [],
        );
    }

    /**
     * @return Collection<int, ProviderConfigurationData>
     */
    public function providerConfig(string $organizationId): Collection
    {
        return Collection::make($this->providerConfigurations)
            ->filter(static fn (ProviderConfigurationData $configuration): bool => $configuration->organizationId === $organizationId)
            ->values();
    }

    /**
     * @param  array<string>|null  $scopes
     * @param  array<string, string>|null  $config
     */
    public function configureProvider(
        string $organizationId,
        string $providerSlug,
        ?bool $enabled = null,
        ?array $scopes = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?array $config = null,
    ): ProviderConfigurationData {
        $key = $organizationId.'|'.$providerSlug;
        $existing = $this->providerConfigurations[$key] ?? null;
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $configuration = new ProviderConfigurationData(
            id: $existing->id ?? 'provider_config_fake_'.++$this->sequence,
            organizationId: $organizationId,
            providerSlug: $providerSlug,
            name: $existing->name ?? ucfirst($providerSlug),
            enabled: $enabled ?? $existing->enabled ?? true,
            scopes: $scopes ?? $existing->scopes ?? null,
            config: $config ?? $existing->config ?? [],
            hasOrganizationCredentials: $clientId !== null || ($existing->hasOrganizationCredentials ?? false),
            clientId: $clientId ?? $existing->clientId ?? null,
            clientSecretLastFour: $clientSecret !== null ? substr($clientSecret, -4) : ($existing->clientSecretLastFour ?? null),
            createdAt: $existing->createdAt ?? $now,
            updatedAt: $now,
        );

        return $this->providerConfigurations[$key] = $configuration;
    }

    public function assertTokenRequested(string $providerSlug, ?string $userId = null): void
    {
        $matches = array_filter(
            $this->tokenRequests,
            static fn (array $request): bool => $request['provider_slug'] === $providerSlug
                && ($userId === null || $request['user_id'] === $userId),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected an access-token request for provider [%s]%s but none matched. %s',
            $providerSlug,
            $userId !== null ? " by user [{$userId}]" : '',
            $this->describeTokenRequests(),
        ));
    }

    /**
     * @param  (callable(ProviderConfigurationData): bool)|null  $callback
     */
    public function assertProviderConfigured(string $organizationId, string $providerSlug, ?callable $callback = null): void
    {
        $configuration = $this->providerConfigurations[$organizationId.'|'.$providerSlug] ?? null;

        Assert::assertTrue(
            $configuration !== null && ($callback === null || $callback($configuration)),
            sprintf(
                'Expected provider [%s] to be configured for organization [%s]%s, but it was not.',
                $providerSlug,
                $organizationId,
                $callback !== null ? ' passing the given callback' : '',
            ),
        );
    }

    private function key(string $userId, string $providerSlug): string
    {
        return $userId.'|'.$providerSlug;
    }

    private function describeTokenRequests(): string
    {
        if ($this->tokenRequests === []) {
            return 'No access tokens were requested.';
        }

        $lines = array_map(
            static fn (array $request): string => "[{$request['provider_slug']}] by [{$request['user_id']}]",
            $this->tokenRequests,
        );

        return 'Requested tokens: '.implode(', ', $lines).'.';
    }
}
