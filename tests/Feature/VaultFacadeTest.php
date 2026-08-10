<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Vault;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use WorkOS\Exception\ConflictException;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\ObjectMetadata;
use WorkOS\Resource\ObjectSummary;
use WorkOS\Resource\ObjectWithoutValue;
use WorkOS\Resource\VaultObject;
use WorkOS\Resource\VaultOrder;
use WorkOS\Resource\VersionListResponse;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler — emulate has ZERO Vault coverage. Unlike the cast
// and filesystem suites there is no local AES-GCM here: KV values are
// encrypted SERVER-side, so these are plaintext-over-the-wire passthroughs.

/**
 * Minimal ObjectMetadata JSON — every key is required by fromArray().
 *
 * @return array<string, mixed>
 */
function vaultKvMetadata(?string $versionId = null): array
{
    return [
        'context' => ['organization_id' => 'org_01HKV'],
        'environment_id' => 'environment_01H',
        'id' => 'kv_01H',
        'key_id' => 'key_01H',
        'updated_at' => '2026-08-09T12:00:00.000Z',
        'updated_by' => ['id' => 'user_01H', 'name' => 'Test Actor'],
        'version_id' => $versionId,
    ];
}

describe('Vault', function (): void {
    describe('VaultFacade', function (): void {
        it('CRUDs a secret: set() then get() round-trips the value by name', function (): void {
            $this->fakeWorkosResponses([
                new Response(200, [], (string) json_encode(vaultKvMetadata('version_1'))),
                new Response(200, [], (string) json_encode([
                    'id' => 'kv_01H',
                    'metadata' => vaultKvMetadata('version_1'),
                    'name' => 'db-password',
                    'value' => 'hunter2',
                ])),
            ]);

            $created = Vault::set(['organization_id' => 'org_01HKV'], 'db-password', 'hunter2');

            expect($created)->toBeInstanceOf(ObjectMetadata::class)
                ->and($created->id)->toBe('kv_01H');

            $fetched = Vault::get('db-password');

            expect($fetched)->toBeInstanceOf(VaultObject::class)
                ->and($fetched->value)->toBe('hunter2');

            $setRequest = $this->workosRequestHistory[0]['request'];

            expect($setRequest->getMethod())->toBe('POST')
                ->and($setRequest->getUri()->getPath())->toEndWith('vault/v1/kv')
                ->and(json_decode((string) $setRequest->getBody(), true))->toBe([
                    'key_context' => ['organization_id' => 'org_01HKV'],
                    'name' => 'db-password',
                    'value' => 'hunter2',
                ]);

            expect($this->workosRequestHistory[1]['request']->getUri()->getPath())
                ->toEndWith('vault/v1/kv/name/db-password');
        });

        it('finds a secret by id', function (): void {
            $this->fakeWorkosResponses([
                new Response(200, [], (string) json_encode([
                    'id' => 'kv_01H',
                    'metadata' => vaultKvMetadata(),
                    'name' => 'db-password',
                    'value' => 'hunter2',
                ])),
            ]);

            $fetched = Vault::find('kv_01H');

            expect($fetched->value)->toBe('hunter2')
                ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())->toEndWith('vault/v1/kv/kv_01H');
        });

        it('updates with an optimistic version check', function (): void {
            $this->fakeWorkosResponses([
                new Response(200, [], (string) json_encode([
                    'id' => 'kv_01H',
                    'metadata' => vaultKvMetadata('version_2'),
                    'name' => 'db-password',
                ])),
            ]);

            $updated = Vault::update('kv_01H', 'correct-horse', 'version_1');

            expect($updated)->toBeInstanceOf(ObjectWithoutValue::class);

            $request = $this->workosRequestHistory[0]['request'];

            expect($request->getMethod())->toBe('PUT')
                ->and(json_decode((string) $request->getBody(), true))->toBe([
                    'value' => 'correct-horse',
                    'version_check' => 'version_1',
                ]);
        });

        it('surfaces a stale version check as a ConflictException', function (): void {
            $this->fakeWorkosResponses([
                new Response(409, [], (string) json_encode(['message' => 'Version check failed.'])),
            ]);

            expect(fn (): ObjectWithoutValue => Vault::update('kv_01H', 'stale-write', 'version_0'))
                ->toThrow(ConflictException::class);
        });

        it('deletes a secret, forwarding the version check as a query parameter', function (): void {
            $this->fakeWorkosResponses([new Response(204)]);

            Vault::delete('kv_01H', 'version_2');

            $request = $this->workosRequestHistory[0]['request'];

            expect($request->getMethod())->toBe('DELETE')
                ->and($request->getUri()->getPath())->toEndWith('vault/v1/kv/kv_01H')
                ->and($request->getUri()->getQuery())->toBe('version_check=version_2');
        });

        it('reads metadata without ever touching the value', function (): void {
            $this->fakeWorkosResponses([
                new Response(200, [], (string) json_encode([
                    'id' => 'kv_01H',
                    'metadata' => vaultKvMetadata('version_2'),
                    'name' => 'db-password',
                ])),
            ]);

            $metadata = Vault::metadata('kv_01H');

            expect($metadata)->toBeInstanceOf(ObjectWithoutValue::class)
                // Compile-time proof metadata never carries the secret: the DTO
                // has no value property at all.
                ->and(property_exists($metadata, 'value'))->toBeFalse()
                ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
                ->toEndWith('vault/v1/kv/kv_01H/metadata');
        });

        it('lists the versions of a secret', function (): void {
            $this->fakeWorkosResponses([
                new Response(200, [], (string) json_encode([
                    'data' => [[
                        'created_at' => '2026-08-09T12:00:00.000Z',
                        'current_version' => true,
                        'etag' => 'abc123',
                        'id' => 'version_2',
                        'size' => 12,
                    ]],
                    'list_metadata' => ['after' => null, 'before' => null],
                ])),
            ]);

            $versions = Vault::versions('kv_01H');

            expect($versions)->toBeInstanceOf(VersionListResponse::class)
                ->and($versions->data)->toHaveCount(1)
                ->and($versions->data[0]->currentVersion)->toBeTrue();
        });

        it('lists secrets with cursor pagination, forwarding filters verbatim', function (): void {
            $this->fakeWorkosResponses([
                new Response(200, [], (string) json_encode([
                    'data' => [
                        ['id' => 'kv_01H', 'name' => 'db-password', 'updated_at' => '2026-08-09T12:00:00.000Z'],
                    ],
                    'list_metadata' => ['after' => null, 'before' => null],
                ])),
            ]);

            $page = Vault::list(limit: 5, order: VaultOrder::Desc, search: 'db-');

            expect($page)->toBeInstanceOf(PaginatedResponse::class)
                ->and($page->data)->toHaveCount(1)
                ->and($page->data[0])->toBeInstanceOf(ObjectSummary::class)
                ->and($page->data[0]->name)->toBe('db-password');

            parse_str($this->workosRequestHistory[0]['request']->getUri()->getQuery(), $query);

            expect($query)->toBe(['limit' => '5', 'order' => 'desc', 'search' => 'db-']);
        });
    });
});
