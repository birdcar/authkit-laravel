<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Services\VaultService;

beforeEach(function () {
    config([
        'workos.api_key' => 'sk_test_vault',
        'workos.widgets.base_url' => 'https://api.workos.com',
    ]);
});

it('stores a vault object', function () {
    Http::fake([
        'api.workos.com/vault/objects' => Http::response([
            'id' => 'obj_123',
            'name' => 'stripe_key',
        ]),
    ]);

    $service = new VaultService;
    $result = $service->store('stripe_key', 'sk_live_abc', ['org' => 'org_1']);

    expect($result['id'])->toBe('obj_123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.workos.com/vault/objects'
            && $request['name'] === 'stripe_key'
            && $request['value'] === 'sk_live_abc'
            && $request['context']['org'] === 'org_1';
    });
});

it('gets a vault object by id', function () {
    Http::fake([
        'api.workos.com/vault/objects/obj_123' => Http::response([
            'id' => 'obj_123',
            'name' => 'stripe_key',
            'value' => 'sk_live_abc',
        ]),
    ]);

    $service = new VaultService;
    $result = $service->get('obj_123');

    expect($result['value'])->toBe('sk_live_abc');
});

it('gets a vault object by name', function () {
    Http::fake([
        'api.workos.com/vault/objects/by-name/stripe_key' => Http::response([
            'id' => 'obj_123',
            'value' => 'sk_live_abc',
        ]),
    ]);

    $service = new VaultService;
    $result = $service->getByName('stripe_key');

    expect($result['value'])->toBe('sk_live_abc');
});

it('updates a vault object', function () {
    Http::fake([
        'api.workos.com/vault/objects/obj_123' => Http::response([
            'id' => 'obj_123',
        ]),
    ]);

    $service = new VaultService;
    $result = $service->update('obj_123', 'new_value');

    expect($result['id'])->toBe('obj_123');
});

it('deletes a vault object', function () {
    Http::fake([
        'api.workos.com/vault/objects/obj_123' => Http::response(null, 204),
    ]);

    $service = new VaultService;
    $service->delete('obj_123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'vault/objects/obj_123')
            && $request->method() === 'DELETE';
    });
});

it('encrypts plaintext', function () {
    Http::fake([
        'api.workos.com/vault/keys/encrypt' => Http::response([
            'ciphertext' => 'encrypted_abc',
        ]),
    ]);

    $service = new VaultService;
    $result = $service->encrypt('sensitive_data');

    expect($result['ciphertext'])->toBe('encrypted_abc');
});

it('decrypts ciphertext', function () {
    Http::fake([
        'api.workos.com/vault/keys/decrypt' => Http::response([
            'plaintext' => 'sensitive_data',
        ]),
    ]);

    $service = new VaultService;
    $result = $service->decrypt('encrypted_abc');

    expect($result['plaintext'])->toBe('sensitive_data');
});

it('throws RuntimeException on API error', function () {
    Http::fake([
        'api.workos.com/vault/objects/obj_bad' => Http::response(['error' => 'not found'], 404),
    ]);

    $service = new VaultService;
    $service->get('obj_bad');
})->throws(RuntimeException::class, 'WorkOS Vault API error');

it('throws when vault feature is disabled', function () {
    config(['workos.features.vault' => false]);

    $workos = app('workos');
    $workos->vault();
})->throws(RuntimeException::class, 'WorkOS Vault is not enabled');
