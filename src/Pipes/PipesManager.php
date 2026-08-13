<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Pipes\Data\ConnectedAccountData;
use Authkit\Authkit\Pipes\Data\PipeAccessTokenData;
use Authkit\Authkit\Pipes\Data\ProviderConfigurationData;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Illuminate\Support\Collection;
use RuntimeException;
use WorkOS\Resource\DataIntegrationAccessTokenResponseError;
use WorkOS\Resource\DataIntegrationConfigurationResponse;
use WorkOS\Resource\DataIntegrationsListResponseData;

/**
 * Pipes connected-account surface, resolved via Authkit::pipes(). Every
 * method is a live, uncached read-through to WorkOS — connected-account
 * state is the one area where "no local projection" is the contract's
 * default posture, not an exception to it, so there is no table to go stale
 * and no cache to invalidate. Provider *definition* (which providers exist,
 * their OAuth apps) stays in the WorkOS Dashboard by the same decision.
 *
 * WorkOS outages surface as the SDK's own ServerException/ConnectionException
 * unmodified — an outage must never be conflated with the named business
 * state PipesAccountNotConnectedException carries.
 */
class PipesManager
{
    public function __construct(
        private readonly WorkosClientManager $clients,
    ) {}

    /**
     * The user's connected provider accounts — providers the environment
     * offers but the user never connected are filtered out. One live HTTP
     * call per invocation (there is no batch endpoint); callers rendering
     * this for many users should batch in a queued job, not a request-time
     * loop.
     *
     * @return Collection<int, ConnectedAccountData>
     */
    public function connectedAccounts(string $userId, ?string $organizationId = null): Collection
    {
        $response = $this->clients->client()->pipes()->listUserDataProviders(
            userId: $userId,
            organizationId: $organizationId,
        );

        return Collection::make($response->data)
            ->filter(fn (DataIntegrationsListResponseData $provider): bool => $provider->connectedAccount !== null)
            ->map(fn (DataIntegrationsListResponseData $provider): ConnectedAccountData => ConnectedAccountData::fromProvider($provider))
            ->values();
    }

    /**
     * A valid access token for one connected provider, with WorkOS-managed
     * refresh behind the call. Throws PipesAccountNotConnectedException when
     * the provider was never connected, and PipesReauthorizationRequiredException
     * (carrying a ready-to-redirect URL) for both reauthorization shapes:
     * the hard `needs_reauthorization` error and the soft non-empty
     * `missing_scopes` list on an otherwise-active token.
     */
    public function accessToken(string $userId, string $providerSlug, ?string $organizationId = null): PipeAccessTokenData
    {
        $response = $this->clients->client()->pipes()->getAccessToken(
            provider: $providerSlug,
            userId: $userId,
            organizationId: $organizationId,
        );

        if ($response->error === DataIntegrationAccessTokenResponseError::NotInstalled) {
            throw PipesAccountNotConnectedException::forProvider($providerSlug, $userId);
        }

        $missingScopes = $response->accessToken->missingScopes ?? [];

        if ($response->error === DataIntegrationAccessTokenResponseError::NeedsReauthorization || $missingScopes !== []) {
            // Fetched eagerly on the exceptional branch only — never on the
            // happy path — so the exception stays a plain data carrier.
            throw PipesReauthorizationRequiredException::forProvider(
                providerSlug: $providerSlug,
                userId: $userId,
                organizationId: $organizationId,
                missingScopes: $missingScopes,
                reauthorizationUrl: $this->reauthorizationUrl($userId, $providerSlug, $organizationId),
            );
        }

        if ($response->accessToken === null) {
            throw new RuntimeException(sprintf(
                'WorkOS returned an active access-token response with no token payload for provider "%s".',
                $providerSlug,
            ));
        }

        return PipeAccessTokenData::fromResponse($response->accessToken);
    }

    /**
     * The organization-level provider configurations — the read half of the
     * org provider-config passthrough. Provider definition itself is
     * Dashboard-only.
     *
     * @return Collection<int, ProviderConfigurationData>
     */
    public function providerConfig(string $organizationId): Collection
    {
        $response = $this->clients->client()->pipesProvider()->listOrganizationDataIntegrationConfigurations(
            organizationId: $organizationId,
        );

        return Collection::make($response->data)
            ->map(fn (DataIntegrationConfigurationResponse $config): ProviderConfigurationData => ProviderConfigurationData::fromResponse($config))
            ->values();
    }

    /**
     * Create or update one provider's organization-level configuration:
     * enable/disable, custom scopes, or organization-supplied OAuth
     * credentials (client id + secret together). Note WorkOS does not
     * retroactively revoke already-issued access tokens when a provider is
     * disabled — enforcement of that boundary is WorkOS's, not the
     * package's.
     *
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
        $response = $this->clients->client()->pipesProvider()->updateOrganizationDataIntegrationConfiguration(
            organizationId: $organizationId,
            slug: $providerSlug,
            enabled: $enabled,
            scopes: $scopes,
            clientId: $clientId,
            clientSecret: $clientSecret,
            config: $config,
        );

        return ProviderConfigurationData::fromResponse($response);
    }

    private function reauthorizationUrl(string $userId, string $providerSlug, ?string $organizationId): string
    {
        $response = $this->clients->client()->pipes()->authorizeDataIntegration(
            slug: $providerSlug,
            userId: $userId,
            organizationId: $organizationId,
        );

        return $response->url;
    }
}
