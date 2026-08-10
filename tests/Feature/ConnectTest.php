<?php

declare(strict_types=1);

// Test path: MockHandler-backed (emulate has zero Connect coverage — contract
// decision D3 lists Connect/MCP as MockHandler-only).

use Authkit\Authkit\Connect\ConnectManager;
use Authkit\Authkit\Connect\Data\ConnectApplication;
use Authkit\Authkit\Connect\Data\ConnectApplicationSecret;
use Authkit\Authkit\Connect\Data\NewConnectApplicationSecret;
use Authkit\Authkit\Connect\Exceptions\ConnectException;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;

uses(UsesWorkosMockHandler::class)->group('connect-mcp');

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function connectApplicationJson(array $overrides = []): array
{
    return array_merge([
        'object' => 'connect_application',
        'id' => 'conn_app_01',
        'client_id' => 'client_conn_01',
        'name' => 'Demo Application',
        'description' => null,
        'scopes' => ['openid'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
        'application_type' => 'oauth',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function connectSecretJson(array $overrides = []): array
{
    return array_merge([
        'object' => 'connect_application_secret',
        'id' => 'conn_secret_01',
        'secret_hint' => '...abcd',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ], $overrides);
}

function connectJsonResponse(array $payload, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

it('creates an OAuth application with a plain-scalar input boundary', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse(connectApplicationJson([
        'redirect_uris' => [['uri' => 'https://app.example.test/callback', 'default' => true]],
        'uses_pkce' => true,
    ]))]);

    $application = Authkit::connect()->createOAuthApplication(
        name: 'Demo Application',
        isFirstParty: true,
        description: 'A demo',
        scopes: ['openid', 'profile'],
        redirectUris: ['https://app.example.test/callback'],
        usesPkce: true,
    );

    expect($application)->toBeInstanceOf(ConnectApplication::class)
        ->and($application->id)->toBe('conn_app_01')
        ->and($application->clientId)->toBe('client_conn_01')
        ->and($application->redirectUris)->toBe([['uri' => 'https://app.example.test/callback', 'default' => true]]);

    $request = $this->workosRequestHistory[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/connect/applications')
        ->and($body['application_type'])->toBe('oauth')
        ->and($body['name'])->toBe('Demo Application')
        ->and($body['is_first_party'])->toBeTrue()
        ->and($body['scopes'])->toBe(['openid', 'profile'])
        ->and($body['uses_pkce'])->toBeTrue()
        ->and($body['redirect_uris'][0]['uri'])->toBe('https://app.example.test/callback');
});

it('creates an M2M application carrying organization_id in the request body', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse(connectApplicationJson(['application_type' => 'm2m', 'organization_id' => 'org_01']))]);

    $application = Authkit::connect()->createM2MApplication(
        name: 'Worker Bot',
        organizationId: 'org_01',
    );

    expect($application->applicationType)->toBe('m2m')
        ->and($application->organizationId)->toBe('org_01');

    $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

    expect($body['application_type'])->toBe('m2m')
        ->and($body['organization_id'])->toBe('org_01');
});

it('rejects a blank organizationId before any request reaches the wire', function (string $organizationId): void {
    $this->fakeWorkosResponses([]);

    expect(fn (): ConnectApplication => Authkit::connect()->createM2MApplication('Worker Bot', $organizationId))
        ->toThrow(ConnectException::class, 'non-blank organizationId');

    expect($this->workosRequestHistory)->toBeEmpty();
})->with(['empty string' => [''], 'whitespace only' => ['   ']]);

it('lists applications, translating order and registration types internally', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse([
        'data' => [connectApplicationJson(), connectApplicationJson(['id' => 'conn_app_02', 'client_id' => 'client_conn_02'])],
        'list_metadata' => ['before' => null, 'after' => null],
    ])]);

    $applications = Authkit::connect()->listApplications(
        limit: 10,
        order: 'asc',
        registrationTypes: ['dynamic', 'authenticated'],
        organizationId: 'org_01',
    );

    expect($applications)->toHaveCount(2)
        ->and($applications->first())->toBeInstanceOf(ConnectApplication::class)
        ->and($applications->last()->id)->toBe('conn_app_02');

    $request = $this->workosRequestHistory[0]['request'];
    parse_str($request->getUri()->getQuery(), $query);

    expect($request->getMethod())->toBe('GET')
        ->and($query['order'])->toBe('asc')
        ->and($query['registration_types'])->toBe(['dynamic', 'authenticated'])
        ->and($query['organization_id'])->toBe('org_01')
        ->and($query['limit'])->toBe('10');
});

it('gets a single application by id', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse(connectApplicationJson())]);

    $application = Authkit::connect()->getApplication('conn_app_01');

    expect($application->name)->toBe('Demo Application');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/connect/applications/conn_app_01');
});

it('updates an application with plain string redirect URIs', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse(connectApplicationJson(['name' => 'Renamed']))]);

    $application = Authkit::connect()->updateApplication(
        id: 'conn_app_01',
        name: 'Renamed',
        redirectUris: ['https://app.example.test/new-callback'],
    );

    expect($application->name)->toBe('Renamed');

    $request = $this->workosRequestHistory[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect($request->getMethod())->toBe('PUT')
        ->and($request->getUri()->getPath())->toBe('/connect/applications/conn_app_01')
        ->and($body['name'])->toBe('Renamed')
        ->and($body['redirect_uris'][0]['uri'])->toBe('https://app.example.test/new-callback');
});

it('deletes an application', function (): void {
    $this->fakeWorkosResponses([new Response(204)]);

    Authkit::connect()->deleteApplication('conn_app_01');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('DELETE')
        ->and($request->getUri()->getPath())->toBe('/connect/applications/conn_app_01');
});

it('lists client secrets as hint-only DTOs', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse([connectSecretJson(), connectSecretJson(['id' => 'conn_secret_02'])])]);

    $secrets = Authkit::connect()->listClientSecrets('conn_app_01');

    expect($secrets)->toHaveCount(2)
        ->and($secrets->first())->toBeInstanceOf(ConnectApplicationSecret::class)
        ->and($secrets->first()->secretHint)->toBe('...abcd');

    expect($this->workosRequestHistory[0]['request']->getUri()->getPath())
        ->toBe('/connect/applications/conn_app_01/client_secrets');
});

it('creates a client secret returning the plaintext value exactly once', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse(connectSecretJson(['secret' => 'cs_plaintext_value']))]);

    $secret = Authkit::connect()->createClientSecret('conn_app_01');

    expect($secret)->toBeInstanceOf(NewConnectApplicationSecret::class)
        ->and($secret->secret)->toBe('cs_plaintext_value');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/connect/applications/conn_app_01/client_secrets');
});

it('deletes a client secret', function (): void {
    $this->fakeWorkosResponses([new Response(204)]);

    Authkit::connect()->deleteClientSecret('conn_secret_01');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('DELETE')
        ->and($request->getUri()->getPath())->toBe('/connect/client_secrets/conn_secret_01');
});

it('rotates by creating the new secret before deleting the old one, never reversed', function (): void {
    $this->fakeWorkosResponses([
        connectJsonResponse(connectSecretJson(['id' => 'conn_secret_new', 'secret' => 'cs_new_value'])),
        new Response(204),
    ]);

    $secret = Authkit::connect()->rotateClientSecret('conn_app_01', 'conn_secret_old');

    expect($secret->id)->toBe('conn_secret_new')
        ->and($secret->secret)->toBe('cs_new_value');

    $first = $this->workosRequestHistory[0]['request'];
    $second = $this->workosRequestHistory[1]['request'];

    expect($first->getMethod())->toBe('POST')
        ->and($first->getUri()->getPath())->toBe('/connect/applications/conn_app_01/client_secrets')
        ->and($second->getMethod())->toBe('DELETE')
        ->and($second->getUri()->getPath())->toBe('/connect/client_secrets/conn_secret_old');
});

it('surfaces a failed rotation delete without rolling back the new secret', function (): void {
    // No SDK-level 5xx retries: a retried delete would consume extra queued
    // responses and hide the single-attempt ordering being asserted.
    config()->set('authkit.max_retries', 0);

    $this->fakeWorkosResponses([
        connectJsonResponse(connectSecretJson(['id' => 'conn_secret_new', 'secret' => 'cs_new_value'])),
        connectJsonResponse(['message' => 'server error'], 500),
    ]);

    expect(fn (): NewConnectApplicationSecret => Authkit::connect()->rotateClientSecret('conn_app_01', 'conn_secret_old'))
        ->toThrow(ConnectException::class);

    // Exactly two requests: the create and the failed delete. No compensating
    // delete of the just-created secret was attempted (failure mode F12 —
    // callers retry only deleteClientSecret on this path).
    expect($this->workosRequestHistory)->toHaveCount(2)
        ->and($this->workosRequestHistory[0]['request']->getMethod())->toBe('POST')
        ->and($this->workosRequestHistory[1]['request']->getMethod())->toBe('DELETE')
        ->and($this->workosRequestHistory[1]['request']->getUri()->getPath())->toBe('/connect/client_secrets/conn_secret_old');
});

it('forwards an idempotency key as the Idempotency-Key header', function (): void {
    $this->fakeWorkosResponses([connectJsonResponse(connectApplicationJson(['application_type' => 'm2m']))]);

    Authkit::connect()->createM2MApplication(
        name: 'Worker Bot',
        organizationId: 'org_01',
        idempotencyKey: 'idem-key-123',
    );

    expect($this->workosRequestHistory[0]['request']->getHeaderLine('Idempotency-Key'))->toBe('idem-key-123');
});

it('wraps SDK exceptions so consumers never catch WorkOS types by name', function (): void {
    config()->set('authkit.max_retries', 0);

    $this->fakeWorkosResponses([connectJsonResponse(['message' => 'not found'], 404)]);

    expect(fn (): ConnectApplication => Authkit::connect()->getApplication('conn_app_missing'))
        ->toThrow(ConnectException::class, 'Connect operation failed');
});

it('resolves the manager through the container and the facade accessor', function (): void {
    expect(Authkit::connect())->toBeInstanceOf(ConnectManager::class)
        ->and(app(ConnectManager::class))->toBeInstanceOf(ConnectManager::class);
});
