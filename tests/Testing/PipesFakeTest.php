<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Pipes\ConnectedAccountState;
use Authkit\Authkit\Pipes\Data\PipeAccessTokenData;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Authkit\Authkit\Pipes\PipesManager;
use Authkit\Authkit\Testing\Fakes\PipesFake;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\Database\Factories\UserFactory;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function pipesFake(): PipesFake
{
    $fake = new PipesFake;

    app()->instance(PipesManager::class, $fake);

    return $fake;
}

it('lists fixture connected accounts, scoped by user and organization', function (): void {
    $fake = pipesFake();

    $fake->connect('user_a', 'github', ['scopes' => ['repo'], 'organization_id' => 'org_acme']);
    $fake->connect('user_a', 'slack');
    $fake->connect('user_b', 'github');

    expect(Authkit::pipes()->connectedAccounts('user_a'))->toHaveCount(2)
        ->and(Authkit::pipes()->connectedAccounts('user_a', 'org_acme'))->toHaveCount(1)
        ->and(Authkit::pipes()->connectedAccounts('user_b')->first()?->providerSlug)->toBe('github');
});

it('serves the connected-accounts surface through the HasWorkosUser trait', function (): void {
    $fake = pipesFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_pipes']);

    $fake->connect('user_pipes', 'github', ['scopes' => ['repo']]);

    expect($user->connectedAccounts())->toHaveCount(1)
        ->and($user->pipe('github'))->toBeInstanceOf(PipeAccessTokenData::class);

    $fake->assertTokenRequested('github', 'user_pipes');
});

it('serves scripted and synthetic access tokens for connected providers', function (): void {
    $fake = pipesFake();

    $fake->connect('user_a', 'github', ['scopes' => ['repo']]);

    $synthetic = Authkit::pipes()->accessToken('user_a', 'github');

    expect($synthetic->accessToken)->toBe('pipes_token_fake_github')
        ->and($synthetic->scopes)->toBe(['repo']);

    $fake->scriptAccessToken('user_a', 'github', 'gho_scripted');

    expect(Authkit::pipes()->accessToken('user_a', 'github')->accessToken)->toBe('gho_scripted');
});

it('throws PipesAccountNotConnectedException for unconnected providers like production', function (): void {
    pipesFake();

    expect(fn (): PipeAccessTokenData => Authkit::pipes()->accessToken('user_a', 'github'))
        ->toThrow(PipesAccountNotConnectedException::class);
});

it('triggers reauthorization with missing scopes and a stub URL', function (): void {
    $fake = pipesFake();

    $fake->connect('user_a', 'github');
    $fake->requireReauthorization('user_a', 'github', ['repo:write']);

    try {
        Authkit::pipes()->accessToken('user_a', 'github');

        $this->fail('Expected PipesReauthorizationRequiredException.');
    } catch (PipesReauthorizationRequiredException $exception) {
        expect($exception->missingScopes)->toBe(['repo:write'])
            ->and($exception->reauthorizationUrl)->toContain('github');
    }

    // An account fixture in the needs_reauthorization state triggers it too.
    $fake->connect('user_b', 'slack', ['state' => ConnectedAccountState::NeedsReauthorization]);

    expect(fn (): PipeAccessTokenData => Authkit::pipes()->accessToken('user_b', 'slack'))
        ->toThrow(PipesReauthorizationRequiredException::class);
});

it('recovers after disconnect and reconnect', function (): void {
    $fake = pipesFake();

    $fake->connect('user_a', 'github');
    $fake->requireReauthorization('user_a', 'github');
    $fake->disconnect('user_a', 'github');

    expect(fn (): PipeAccessTokenData => Authkit::pipes()->accessToken('user_a', 'github'))
        ->toThrow(PipesAccountNotConnectedException::class);

    $fake->connect('user_a', 'github');

    expect(Authkit::pipes()->accessToken('user_a', 'github'))->toBeInstanceOf(PipeAccessTokenData::class);
});

it('manages provider configurations in memory', function (): void {
    $fake = pipesFake();

    Authkit::pipes()->configureProvider('org_acme', 'github', enabled: true, scopes: ['repo'], clientId: 'client_org', clientSecret: 'shhh-secret');

    $configurations = Authkit::pipes()->providerConfig('org_acme');

    expect($configurations)->toHaveCount(1)
        ->and($configurations->first()?->hasOrganizationCredentials)->toBeTrue()
        ->and($configurations->first()?->clientSecretLastFour)->toBe('cret');

    Authkit::pipes()->configureProvider('org_acme', 'github', enabled: false);

    expect(Authkit::pipes()->providerConfig('org_acme')->first()?->enabled)->toBeFalse()
        ->and(Authkit::pipes()->providerConfig('org_acme')->first()?->clientId)->toBe('client_org');

    $fake->assertProviderConfigured('org_acme', 'github', fn ($configuration): bool => $configuration->enabled === false);
});

it('fails assertions with readable messages', function (): void {
    $fake = pipesFake();

    expect(fn () => $fake->assertTokenRequested('github'))
        ->toThrow(AssertionFailedError::class, 'No access tokens were requested');

    $fake->connect('user_a', 'slack');
    Authkit::pipes()->accessToken('user_a', 'slack');

    expect(fn () => $fake->assertTokenRequested('github'))
        ->toThrow(AssertionFailedError::class, 'Requested tokens: [slack] by [user_a]')
        ->and(fn () => $fake->assertProviderConfigured('org_acme', 'github'))
        ->toThrow(AssertionFailedError::class, 'Expected provider [github] to be configured');
});
