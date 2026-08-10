<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Jobs\CreateWorkosOrganization;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Workbench\App\Models\Organization;
use WorkOS\Exception\NotFoundException;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function remoteOrganizationResponse(string $id = 'org_remote', string $name = 'Acme', ?string $externalId = '1'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => $id,
        'name' => $name,
        'domains' => [],
        'metadata' => [],
        'external_id' => $externalId,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]));
}

function workosNotFoundResponse(): Response
{
    return new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
        'message' => 'Organization not found.',
    ]));
}

it('creates a remote organization on local create and links it by workos_id', function (): void {
    $this->fakeWorkosResponses([workosNotFoundResponse(), remoteOrganizationResponse('org_created')]);

    $organization = Organization::query()->create(['name' => 'Acme']);

    expect($organization->refresh()->workos_id)->toBe('org_created')
        ->and($this->workosRequestHistory)->toHaveCount(2);

    // Lookup-before-create, and external_id is the local primary key.
    $lookup = $this->workosRequestHistory[0]['request'];
    $create = $this->workosRequestHistory[1]['request'];

    expect($lookup->getMethod())->toBe('GET')
        ->and($lookup->getUri()->getPath())->toBe('/organizations/external_id/'.$organization->getKey())
        ->and($create->getMethod())->toBe('POST')
        ->and($create->getUri()->getPath())->toBe('/organizations');

    $body = json_decode((string) $create->getBody(), true);

    expect($body['name'])->toBe('Acme')
        ->and($body['external_id'])->toBe((string) $organization->getKey());
});

it('adopts an already-existing remote organization instead of creating a duplicate', function (): void {
    $this->fakeWorkosResponses([remoteOrganizationResponse('org_existing')]);

    $organization = Organization::query()->create(['name' => 'Acme']);

    expect($organization->refresh()->workos_id)->toBe('org_existing')
        ->and($this->workosRequestHistory)->toHaveCount(1);
});

it('no-ops when the row is already synced, so a re-run costs zero API calls', function (): void {
    $this->fakeWorkosResponses([]);

    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_synced']);

    dispatch_sync(new CreateWorkosOrganization($organization));

    expect($organization->refresh()->workos_id)->toBe('org_synced')
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('adopts the winning record after losing a create-vs-create race', function (): void {
    // Lookup misses, create collides (409), the retried lookup finds the winner.
    $this->fakeWorkosResponses([
        workosNotFoundResponse(),
        new Response(409, ['Content-Type' => 'application/json'], (string) json_encode([
            'message' => 'An organization with this external_id already exists.',
        ])),
        remoteOrganizationResponse('org_winner'),
    ]);

    $organization = Organization::query()->create(['name' => 'Acme']);

    expect($organization->refresh()->workos_id)->toBe('org_winner')
        ->and($this->workosRequestHistory)->toHaveCount(3);
});

it('deletes the remote organization when the local row is deleted', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_gone']);

    $this->fakeWorkosResponses([new Response(204)]);

    $organization->delete();

    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and($this->workosRequestHistory[0]['request']->getMethod())->toBe('DELETE')
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())->toBe('/organizations/org_gone');
});

it('treats an already-deleted remote organization as a successful delete', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_gone']);

    $this->fakeWorkosResponses([workosNotFoundResponse()]);

    $organization->delete();

    expect($this->workosRequestHistory)->toHaveCount(1);
});

it('keeps the remote organization when delete_remote_on_delete is disabled', function (): void {
    config()->set('authkit.organization.delete_remote_on_delete', false);

    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_kept']);

    $this->fakeWorkosResponses([]);

    $organization->delete();

    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('makes no delete call for a row that never synced remotely', function (): void {
    $organization = Organization::query()->createQuietly(['name' => 'Acme']);

    $this->fakeWorkosResponses([]);

    $organization->delete();

    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('round-trips create and delete against a running emulator', function (): void {
    $this->server = new EmulateServer(port: 4197);
    $this->server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $this->server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    $organization = Organization::query()->create(['name' => 'Emulated Org']);
    $workosId = $organization->refresh()->workos_id;

    expect($workosId)->not->toBeNull();

    $client = app(WorkosClientManager::class)->client();
    $remote = $client->organizations()->getOrganization((string) $workosId);

    expect($remote->name)->toBe('Emulated Org')
        ->and($remote->externalId)->toBe((string) $organization->getKey());

    $organization->delete();

    expect(fn () => $client->organizations()->getOrganization((string) $workosId))
        ->toThrow(NotFoundException::class);
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
