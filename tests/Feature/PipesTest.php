<?php

declare(strict_types=1);

// Test path: MockHandler (Pipes has zero emulate coverage — see contract Decision #2)

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Pipes\AuthMethod;
use Authkit\Authkit\Pipes\ConnectedAccountState;
use Authkit\Authkit\Pipes\Data\ConnectedAccountData;
use Authkit\Authkit\Pipes\Data\PipeAccessTokenData;
use Authkit\Authkit\Pipes\Data\ProviderConfigurationData;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

/**
 * @return array<string, mixed>
 */
function pipesConnectedAccountJson(array $overrides = []): array
{
    return array_merge([
        'object' => 'connected_account',
        'id' => 'ca_1',
        'user_id' => 'user_1',
        'organization_id' => null,
        'scopes' => ['repo'],
        'state' => 'connected',
        'created_at' => '2026-01-01T00:00:00.000Z',
        'updated_at' => '2026-01-01T00:00:00.000Z',
        'auth_method' => 'oauth',
    ], $overrides);
}

/**
 * @param  array<string, mixed>|null  $connectedAccount
 * @return array<string, mixed>
 */
function pipesProviderJson(string $slug = 'github', ?array $connectedAccount = null): array
{
    return [
        'object' => 'data_provider',
        'id' => 'di_'.$slug,
        'name' => ucfirst($slug),
        'description' => null,
        'slug' => $slug,
        'integration_type' => $slug,
        'credentials_type' => 'oauth2',
        'scopes' => ['repo'],
        'ownership' => 'userland_user',
        'created_at' => '2026-01-01T00:00:00.000Z',
        'updated_at' => '2026-01-01T00:00:00.000Z',
        'connected_account' => $connectedAccount,
    ];
}

/**
 * @return array<string, mixed>
 */
function pipesProviderConfigJson(array $overrides = []): array
{
    return array_merge([
        'object' => 'data_integration_configuration',
        'id' => 'dic_1',
        'organization_id' => 'org_acme',
        'slug' => 'github',
        'name' => 'GitHub',
        'enabled' => true,
        'scopes' => ['repo'],
        'config' => [],
        'created_at' => '2026-01-01T00:00:00.000Z',
        'updated_at' => '2026-01-01T00:00:00.000Z',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function pipesJsonResponse(array $payload, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

/**
 * @param  array<int, array<string, mixed>>  $providers
 */
function pipesProvidersListResponse(array $providers): Response
{
    return pipesJsonResponse(['object' => 'list', 'data' => $providers]);
}

describe('Pipes', function (): void {
    it('maps connected accounts and filters out providers the user never connected', function (): void {
        $this->fakeWorkosResponses([
            pipesProvidersListResponse([
                pipesProviderJson('github', pipesConnectedAccountJson()),
                pipesProviderJson('slack'),
            ]),
        ]);

        $accounts = Authkit::pipes()->connectedAccounts('user_1');

        expect($accounts)->toHaveCount(1)
            ->and($accounts->first())->toBeInstanceOf(ConnectedAccountData::class)
            ->and($accounts->first()?->id)->toBe('ca_1')
            ->and($accounts->first()?->providerSlug)->toBe('github')
            ->and($accounts->first()?->providerName)->toBe('Github')
            ->and($accounts->first()?->userId)->toBe('user_1')
            ->and($accounts->first()?->state)->toBe(ConnectedAccountState::Connected)
            ->and($accounts->first()?->isConnected())->toBeTrue()
            ->and($accounts->first()?->needsReauthorization())->toBeFalse()
            ->and($accounts->first()?->scopes)->toBe(['repo'])
            ->and($accounts->first()?->authMethod)->toBe(AuthMethod::OAuth);

        $request = $this->workosRequestHistory[0]['request'];
        expect($request->getMethod())->toBe('GET')
            ->and($request->getUri()->getPath())->toBe('/user_management/users/user_1/data_providers');
    });

    it('returns an empty collection when the user has connected nothing', function (array $providers): void {
        $this->fakeWorkosResponses([pipesProvidersListResponse($providers)]);

        expect(Authkit::pipes()->connectedAccounts('user_1'))->toBeEmpty();
    })->with([
        'environment has no providers' => [[]],
        'providers exist but none are connected' => [[pipesProviderJson('github'), pipesProviderJson('slack')]],
    ]);

    it('scopes the connected-accounts read to an organization when one is given', function (): void {
        $this->fakeWorkosResponses([pipesProvidersListResponse([])]);

        Authkit::pipes()->connectedAccounts('user_1', 'org_acme');

        expect($this->workosRequestHistory[0]['request']->getUri()->getQuery())
            ->toBe('organization_id=org_acme');
    });

    it('returns an access token with expiry and scopes on the happy path', function (): void {
        $this->fakeWorkosResponses([
            pipesJsonResponse([
                'active' => true,
                'access_token' => [
                    'object' => 'access_token',
                    'access_token' => 'gho_live_token',
                    'expires_at' => '2026-06-01T00:00:00.000Z',
                    'scopes' => ['repo'],
                    'missing_scopes' => [],
                ],
            ]),
        ]);

        $token = Authkit::pipes()->accessToken('user_1', 'github');

        expect($token)->toBeInstanceOf(PipeAccessTokenData::class)
            ->and($token->accessToken)->toBe('gho_live_token')
            ->and($token->expiresAt?->format(DATE_ATOM))->toBe('2026-06-01T00:00:00+00:00')
            ->and($token->scopes)->toBe(['repo'])
            ->and($token->missingScopes)->toBe([]);

        $request = $this->workosRequestHistory[0]['request'];
        expect($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/data-integrations/github/token')
            ->and(json_decode((string) $request->getBody(), true))->toBe(['user_id' => 'user_1']);
    });

    it('throws the named not-connected exception when the provider is not installed', function (): void {
        $this->fakeWorkosResponses([
            pipesJsonResponse(['active' => false, 'error' => 'not_installed']),
        ]);

        try {
            Authkit::pipes()->accessToken('user_1', 'github');

            $this->fail('Expected PipesAccountNotConnectedException was not thrown.');
        } catch (PipesAccountNotConnectedException $exception) {
            expect($exception->providerSlug)->toBe('github')
                ->and($exception->userId)->toBe('user_1')
                // Outage-vs-not-connected must stay distinguishable: exactly the
                // one token request went out, and no reauth URL was fetched.
                ->and($this->workosRequestHistory)->toHaveCount(1);
        }
    });

    it('surfaces reauthorization drift with the authorize URL attached', function (array $tokenResponse, array $expectedMissingScopes): void {
        $this->fakeWorkosResponses([
            pipesJsonResponse($tokenResponse),
            pipesJsonResponse(['url' => 'https://api.workos.com/data-integrations/github/authorize?token=abc']),
        ]);

        try {
            Authkit::pipes()->accessToken('user_1', 'github', 'org_acme');

            $this->fail('Expected PipesReauthorizationRequiredException was not thrown.');
        } catch (PipesReauthorizationRequiredException $exception) {
            expect($exception->providerSlug)->toBe('github')
                ->and($exception->userId)->toBe('user_1')
                ->and($exception->organizationId)->toBe('org_acme')
                ->and($exception->missingScopes)->toBe($expectedMissingScopes)
                ->and($exception->reauthorizationUrl)->toBe('https://api.workos.com/data-integrations/github/authorize?token=abc');
        }

        $reauthRequest = $this->workosRequestHistory[1]['request'];
        expect($reauthRequest->getMethod())->toBe('POST')
            ->and($reauthRequest->getUri()->getPath())->toBe('/data-integrations/github/authorize')
            ->and(json_decode((string) $reauthRequest->getBody(), true))
            ->toBe(['user_id' => 'user_1', 'organization_id' => 'org_acme']);
    })->with([
        'hard: error=needs_reauthorization' => [
            ['active' => false, 'error' => 'needs_reauthorization'],
            [],
        ],
        'soft: active token with missing scopes' => [
            [
                'active' => true,
                'access_token' => [
                    'object' => 'access_token',
                    'access_token' => 'gho_partial_token',
                    'expires_at' => '2026-06-01T00:00:00.000Z',
                    'scopes' => ['repo'],
                    'missing_scopes' => ['read:org'],
                ],
            ],
            ['read:org'],
        ],
    ]);

    it('fails loudly when an active response carries no token payload', function (): void {
        $this->fakeWorkosResponses([pipesJsonResponse(['active' => true])]);

        expect(fn (): PipeAccessTokenData => Authkit::pipes()->accessToken('user_1', 'github'))
            ->toThrow(RuntimeException::class, 'no token payload');
    });

    it('lists org provider configurations, exposing organization credentials only as redacted metadata', function (): void {
        $this->fakeWorkosResponses([
            pipesJsonResponse([
                'object' => 'list',
                'data' => [
                    pipesProviderConfigJson([
                        'credentials' => [
                            'credentials_type' => 'organization',
                            'has_credentials' => true,
                            'client_id' => 'oauth_client_1',
                            'client_secret_last_four' => '4242',
                            'redirect_uri' => 'https://api.workos.com/callback',
                        ],
                    ]),
                    pipesProviderConfigJson(['id' => 'dic_2', 'slug' => 'slack', 'name' => 'Slack', 'enabled' => false, 'scopes' => null]),
                ],
            ]),
        ]);

        $configs = Authkit::pipes()->providerConfig('org_acme');

        expect($configs)->toHaveCount(2)
            ->and($configs->first())->toBeInstanceOf(ProviderConfigurationData::class)
            ->and($configs->first()?->providerSlug)->toBe('github')
            ->and($configs->first()?->enabled)->toBeTrue()
            ->and($configs->first()?->hasOrganizationCredentials)->toBeTrue()
            ->and($configs->first()?->clientId)->toBe('oauth_client_1')
            ->and($configs->first()?->clientSecretLastFour)->toBe('4242')
            ->and($configs->last()?->providerSlug)->toBe('slack')
            ->and($configs->last()?->enabled)->toBeFalse()
            ->and($configs->last()?->scopes)->toBeNull()
            ->and($configs->last()?->hasOrganizationCredentials)->toBeFalse()
            ->and($configs->last()?->clientId)->toBeNull();

        $request = $this->workosRequestHistory[0]['request'];
        expect($request->getMethod())->toBe('GET')
            ->and($request->getUri()->getPath())->toBe('/organizations/org_acme/data_integration_configurations');
    });

    it('configures a provider for an organization, sending exactly the given fields', function (): void {
        $this->fakeWorkosResponses([
            pipesJsonResponse(pipesProviderConfigJson(['scopes' => ['repo', 'read:org']])),
        ]);

        $config = Authkit::pipes()->configureProvider(
            organizationId: 'org_acme',
            providerSlug: 'github',
            enabled: true,
            scopes: ['repo', 'read:org'],
        );

        expect($config->providerSlug)->toBe('github')
            ->and($config->enabled)->toBeTrue()
            ->and($config->scopes)->toBe(['repo', 'read:org']);

        $request = $this->workosRequestHistory[0]['request'];
        expect($request->getMethod())->toBe('PUT')
            ->and($request->getUri()->getPath())->toBe('/organizations/org_acme/data_integration_configurations/github')
            ->and(json_decode((string) $request->getBody(), true))
            ->toBe(['enabled' => true, 'scopes' => ['repo', 'read:org']]);
    });

    it('passes organization-supplied OAuth credentials through on configure', function (): void {
        $this->fakeWorkosResponses([
            pipesJsonResponse(pipesProviderConfigJson([
                'credentials' => [
                    'credentials_type' => 'organization',
                    'has_credentials' => true,
                    'client_id' => 'oauth_byoo',
                    'client_secret_last_four' => '9999',
                    'redirect_uri' => 'https://api.workos.com/callback',
                ],
            ])),
        ]);

        $config = Authkit::pipes()->configureProvider(
            organizationId: 'org_acme',
            providerSlug: 'github',
            clientId: 'oauth_byoo',
            clientSecret: 'super-secret-value',
        );

        expect($config->hasOrganizationCredentials)->toBeTrue()
            ->and($config->clientId)->toBe('oauth_byoo')
            ->and($config->clientSecretLastFour)->toBe('9999');

        expect(json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true))
            ->toBe(['client_id' => 'oauth_byoo', 'client_secret' => 'super-secret-value']);
    });

    describe('HasWorkosUser conveniences', function (): void {
        beforeEach(function (): void {
            $this->migratePackageDatabase();
        });

        it('delegates connectedAccounts() to the manager with this user\'s workos_id', function (): void {
            $user = UserFactory::new()->create(['workos_id' => 'user_1']);

            $this->fakeWorkosResponses([
                pipesProvidersListResponse([pipesProviderJson('github', pipesConnectedAccountJson())]),
                pipesProvidersListResponse([pipesProviderJson('github', pipesConnectedAccountJson())]),
            ]);

            $viaTrait = $user->connectedAccounts();
            $direct = Authkit::pipes()->connectedAccounts('user_1');

            expect($viaTrait)->toEqual($direct)
                ->and($viaTrait->first()?->providerSlug)->toBe('github')
                ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
                ->toBe($this->workosRequestHistory[1]['request']->getUri()->getPath());
        });

        it('delegates pipe() to accessToken() with this user\'s workos_id', function (): void {
            $user = UserFactory::new()->create(['workos_id' => 'user_1']);

            $tokenPayload = [
                'active' => true,
                'access_token' => [
                    'object' => 'access_token',
                    'access_token' => 'gho_live_token',
                    'expires_at' => '2026-06-01T00:00:00.000Z',
                    'scopes' => ['repo'],
                    'missing_scopes' => [],
                ],
            ];
            $this->fakeWorkosResponses([pipesJsonResponse($tokenPayload), pipesJsonResponse($tokenPayload)]);

            $viaTrait = $user->pipe('github', 'org_acme');
            $direct = Authkit::pipes()->accessToken('user_1', 'github', 'org_acme');

            expect($viaTrait)->toEqual($direct)
                ->and((string) $this->workosRequestHistory[0]['request']->getBody())
                ->toBe((string) $this->workosRequestHistory[1]['request']->getBody());
        });

        it('refuses Pipes calls for a user that never linked a workos_id, before any HTTP', function (): void {
            $user = UserFactory::new()->create();

            $this->fakeWorkosResponses([]);

            expect(fn () => $user->connectedAccounts())
                ->toThrow(RuntimeException::class, 'has no workos_id yet')
                ->and(fn () => $user->pipe('github'))
                ->toThrow(RuntimeException::class, 'has no workos_id yet')
                ->and($this->workosRequestHistory)->toHaveCount(0);
        });
    });
});
