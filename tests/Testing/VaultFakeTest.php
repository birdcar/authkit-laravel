<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Vault;
use Authkit\Authkit\Testing\Fakes\Support\FakeVaultCrypto;
use Authkit\Authkit\Testing\Fakes\VaultFake;
use Authkit\Authkit\Vault\VaultCrypto;
use Authkit\Authkit\Vault\VaultManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\VaultDemoRecord;
use WorkOS\Exception\ConflictException;
use WorkOS\Resource\VaultObject;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function vaultFake(): VaultFake
{
    $fake = new VaultFake;

    app()->instance(VaultManager::class, $fake);
    app()->instance(VaultCrypto::class, $fake->crypto());

    return $fake;
}

it('round-trips the KV surface in memory', function (): void {
    $fake = vaultFake();

    $metadata = Vault::set(['organization_id' => 'org_acme'], 'api-token', 'secret-value');

    expect($metadata->context)->toBe(['organization_id' => 'org_acme']);

    $object = Vault::get('api-token');

    expect($object)->toBeInstanceOf(VaultObject::class)
        ->and($object->value)->toBe('secret-value')
        ->and(Vault::find($object->id)->value)->toBe('secret-value')
        ->and(Vault::metadata($object->id)->name)->toBe('api-token');

    Vault::update($object->id, 'rotated-value');

    expect(Vault::get('api-token')->value)->toBe('rotated-value')
        ->and(Vault::versions($object->id)->data)->toHaveCount(2)
        ->and(Vault::list(search: 'api')->data)->toHaveCount(1)
        ->and(Vault::list(search: 'nope')->data)->toHaveCount(0);

    Vault::delete($object->id);

    $fake->assertSet('api-token');
    $fake->assertUpdated($object->id);
    $fake->assertDeleted($object->id);
});

it('keeps the Vaulted cast on its real code path, offline and visibly non-ciphertext', function (): void {
    vaultFake();

    $record = VaultDemoRecord::query()->create(['secret' => 'launch codes']);

    $raw = DB::table('vault_demo_records')->where('id', $record->getKey())->value('secret');

    expect($raw)->toStartWith(FakeVaultCrypto::MARKER)
        ->and($raw)->not->toContain('launch codes')
        ->and(VaultDemoRecord::query()->findOrFail($record->getKey())->getAttribute('secret'))->toBe('launch codes');
});

it('keeps the vault filesystem disk on its real code path offline', function (): void {
    vaultFake();

    config()->set('filesystems.disks.vault-test-inner', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/vault-fake'),
    ]);
    config()->set('filesystems.disks.vault', [
        'driver' => 'vault',
        'disk' => 'vault-test-inner',
    ]);

    Storage::disk('vault')->put('note.txt', 'secret file body');

    expect(Storage::disk('vault-test-inner')->get('note.txt'))->toStartWith(FakeVaultCrypto::MARKER)
        ->and(Storage::disk('vault')->get('note.txt'))->toBe('secret file body');
});

it('enforces optimistic locking like production', function (): void {
    vaultFake();

    Vault::set([], 'locked', 'v1');
    $object = Vault::get('locked');
    $currentVersion = $object->metadata->versionId;

    // A matching versionCheck succeeds; a stale one conflicts; none = last write wins.
    Vault::update($object->id, 'v2', $currentVersion);

    expect(Vault::get('locked')->value)->toBe('v2')
        ->and(fn () => Vault::update($object->id, 'v3', $currentVersion))
        ->toThrow(ConflictException::class)
        ->and(fn () => Vault::delete($object->id, 'vault_version_stale'))
        ->toThrow(ConflictException::class);

    Vault::update($object->id, 'v3');

    expect(Vault::get('locked')->value)->toBe('v3');
});

it('exposes the sealed key context for assertions', function (): void {
    $fake = vaultFake();

    $envelope = $fake->crypto()->encryptWithContext('value', ['organization_id' => 'org_byok']);

    expect($fake->crypto()->sealedContext($envelope))->toBe(['organization_id' => 'org_byok']);
});

it('refuses to open envelopes it did not seal', function (): void {
    $fake = vaultFake();

    expect(fn (): string => $fake->crypto()->decrypt('real-ciphertext-blob'))
        ->toThrow(InvalidArgumentException::class, 'did not seal');
});

it('throws with guidance for unknown objects and fails assertions readably', function (): void {
    $fake = vaultFake();

    expect(fn (): VaultObject => Vault::get('missing'))
        ->toThrow(InvalidArgumentException::class, 'Vault::set()');

    $fake->assertNothingStored();

    Vault::set([], 'present', 'value');

    expect(fn () => $fake->assertSet('absent'))
        ->toThrow(AssertionFailedError::class, 'Stored objects: present')
        ->and(fn () => $fake->assertNothingStored())
        ->toThrow(AssertionFailedError::class, 'Stored objects: present');
});
