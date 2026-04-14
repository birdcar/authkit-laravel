<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use WorkOS\AuthKit\Services\VaultService;
use WorkOS\Exception\NotFoundException;
use WorkOS\WorkOS;

function makeVaultClient(MockHandler $mock): WorkOS
{
    $stack = HandlerStack::create($mock);

    return new WorkOS(apiKey: 'sk_test_vault', handler: $stack);
}

it('stores a vault object', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'obj_123',
            'name' => 'stripe_key',
            'value' => 'sk_live_abc',
            'key_context' => ['org' => 'org_1'],
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
        ])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $result = $service->store('stripe_key', 'sk_live_abc', ['org' => 'org_1']);

    expect($result['id'])->toBe('obj_123')
        ->and($result['name'])->toBe('stripe_key');
});

it('gets a vault object by id', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'obj_123',
            'name' => 'stripe_key',
            'value' => 'sk_live_abc',
            'key_context' => [],
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
        ])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $result = $service->get('obj_123');

    expect($result['value'])->toBe('sk_live_abc');
});

it('gets a vault object by name', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'obj_123',
            'name' => 'stripe_key',
            'value' => 'sk_live_abc',
            'key_context' => [],
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
        ])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $result = $service->getByName('stripe_key');

    expect($result['value'])->toBe('sk_live_abc');
});

it('updates a vault object', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'obj_123',
            'name' => 'stripe_key',
            'value' => 'new_value',
            'key_context' => [],
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
        ])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $result = $service->update('obj_123', 'new_value');

    expect($result['id'])->toBe('obj_123');
});

it('deletes a vault object', function () {
    $mock = new MockHandler([
        new Response(204),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $service->delete('obj_123');

    expect($mock->count())->toBe(0);
});

it('lists vault objects', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'id' => 'obj_123',
                    'name' => 'stripe_key',
                    'key_context' => [],
                    'created_at' => '2024-01-01T00:00:00.000Z',
                    'updated_at' => '2024-01-01T00:00:00.000Z',
                ],
            ],
            'list_metadata' => ['before' => null, 'after' => null],
        ])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $result = $service->list(10);

    expect($result)->toBeArray();
});

it('lists vault object versions', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                ['version' => 1],
                ['version' => 2],
            ],
        ])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $result = $service->versions('obj_123');

    expect($result)->toBeArray();
});

it('throws on API error', function () {
    $mock = new MockHandler([
        new Response(404, [], json_encode(['message' => 'Not found'])),
    ]);

    $service = new VaultService(makeVaultClient($mock));
    $service->get('obj_bad');
})->throws(NotFoundException::class);

it('throws when vault feature is disabled', function () {
    config(['workos.features.vault' => false]);

    $workos = app('workos');
    $workos->vault();
})->throws(RuntimeException::class, 'WorkOS Vault is not enabled');
