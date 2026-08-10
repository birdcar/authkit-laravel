<?php

declare(strict_types=1);

use Authkit\Authkit\Exceptions\VaultFileTooLargeException;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler — emulate has ZERO Vault coverage. The vault disk
// wraps a FAKED inner disk: Storage::fake('vault') directly would replace the
// custom driver with a plain local fake and the suite would pass even with
// completely broken encryption (spec-phase-9 §7).

/**
 * Same-key createDataKey/createDecrypt pair — see VaultedCastTest for why this
 * makes the AES-256-GCM round trip real rather than endpoint-shaped.
 *
 * @return array<int, Response>
 */
function vaultDiskRoundTripResponses(): array
{
    $b64Key = base64_encode(random_bytes(32));

    return [
        new Response(200, [], (string) json_encode([
            'context' => ['probe' => 'value'],
            'data_key' => $b64Key,
            'encrypted_keys' => base64_encode('opaque-wrapped-key-blob'),
            'id' => 'key_123',
        ])),
        new Response(200, [], (string) json_encode([
            'data_key' => $b64Key,
            'id' => 'key_123',
        ])),
    ];
}

beforeEach(function (): void {
    config()->set('filesystems.disks.vault-test-inner', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/vault'),
    ]);
    config()->set('filesystems.disks.vault', [
        'driver' => 'vault',
        'disk' => 'vault-test-inner',
    ]);

    Storage::fake('vault-test-inner'); // safe — a plain local disk, not the decorator
});

describe('Vault', function (): void {
    describe('VaultFilesystemDriver', function (): void {
        it('round-trips a file on a wrapped disk, storing only ciphertext at rest', function (): void {
            $this->fakeWorkosResponses(vaultDiskRoundTripResponses());

            expect(Storage::disk('vault')->put('secret.txt', 'plaintext-content'))->toBeTrue();

            expect(Storage::disk('vault-test-inner')->get('secret.txt'))->not->toBe('plaintext-content')
                ->and(Storage::disk('vault')->get('secret.txt'))->toBe('plaintext-content');

            $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

            expect($body)->toBe(['context' => ['disk' => 'vault-test-inner']]); // default per-disk context
        });

        it('sends a custom static context from the disk config on the encrypt call', function (): void {
            config()->set('filesystems.disks.vault.context', ['app' => 'acme', 'purpose' => 'exports']);

            $this->fakeWorkosResponses(vaultDiskRoundTripResponses());

            Storage::disk('vault')->put('export.csv', 'rows');

            $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

            expect($body)->toBe(['context' => ['app' => 'acme', 'purpose' => 'exports']]);
        });

        it('round-trips an uploaded file through putFile()', function (): void {
            $this->fakeWorkosResponses(vaultDiskRoundTripResponses());

            $file = UploadedFile::fake()->createWithContent('cert.pem', 'CERTIFICATE-BYTES');

            $path = Storage::disk('vault')->putFile('certs', $file);

            expect($path)->toBeString()->toStartWith('certs/');

            expect(Storage::disk('vault-test-inner')->get((string) $path))->not->toBe('CERTIFICATE-BYTES')
                ->and(Storage::disk('vault')->get((string) $path))->toBe('CERTIFICATE-BYTES');
        });

        it('round-trips writeStream() and readStream()', function (): void {
            $this->fakeWorkosResponses(vaultDiskRoundTripResponses());

            $in = fopen('php://temp', 'r+b');
            fwrite($in, 'stream-secret');
            rewind($in);

            expect(Storage::disk('vault')->writeStream('stream.bin', $in))->toBeTrue();
            fclose($in);

            $out = Storage::disk('vault')->readStream('stream.bin');

            expect($out)->toBeResource()
                ->and(stream_get_contents($out))->toBe('stream-secret');
            fclose($out);
        });

        it('allows a write exactly at the size-guard boundary', function (): void {
            config()->set('filesystems.disks.vault.max_encrypt_bytes', 8);

            $this->fakeWorkosResponses(array_slice(vaultDiskRoundTripResponses(), 0, 1));

            expect(Storage::disk('vault')->put('fits.bin', str_repeat('x', 8)))->toBeTrue()
                ->and(Storage::disk('vault-test-inner')->exists('fits.bin'))->toBeTrue();
        });

        it('rejects an oversized write before any network call', function (): void {
            config()->set('filesystems.disks.vault.max_encrypt_bytes', 8);

            $this->fakeWorkosResponses(vaultDiskRoundTripResponses()); // 2 queued — must remain 2

            expect(fn (): bool => Storage::disk('vault')->put('big.bin', str_repeat('x', 9)))
                ->toThrow(VaultFileTooLargeException::class);

            expect($this->workosMockHandler->count())->toBe(2) // guard ran before createDataKey
                ->and(Storage::disk('vault-test-inner')->exists('big.bin'))->toBeFalse();
        });

        it('falls back to the global authkit.vault.filesystem.max_encrypt_bytes guard', function (): void {
            config()->set('authkit.vault.filesystem.max_encrypt_bytes', 4);

            $this->fakeWorkosResponses(vaultDiskRoundTripResponses());

            expect(fn (): bool => Storage::disk('vault')->put('big.bin', 'five!'))
                ->toThrow(VaultFileTooLargeException::class);

            expect($this->workosMockHandler->count())->toBe(2);
        });

        it('appends via decrypt-then-concatenate-then-re-encrypt, minting a fresh data key', function (): void {
            $first = vaultDiskRoundTripResponses();  // key K1: put encrypt + append's decrypt
            $second = vaultDiskRoundTripResponses(); // key K2: append's re-encrypt + final read decrypt

            $this->fakeWorkosResponses([$first[0], $first[1], $second[0], $second[1]]);

            $vault = Storage::disk('vault');
            $vault->put('notes.txt', 'first');

            expect($vault->append('notes.txt', 'second'))->toBeTrue();

            expect($vault->get('notes.txt'))->toBe('first'.PHP_EOL.'second');

            // put → createDataKey, append → createDecrypt + createDataKey,
            // get → createDecrypt: the documented two-wire-call append cost.
            $paths = array_map(
                fn (array $entry): string => $entry['request']->getUri()->getPath(),
                $this->workosRequestHistory,
            );

            expect($paths)->toHaveCount(4)
                ->and($paths[0])->toEndWith('vault/v1/keys/data-key')
                ->and($paths[1])->toEndWith('vault/v1/keys/decrypt')
                ->and($paths[2])->toEndWith('vault/v1/keys/data-key')
                ->and($paths[3])->toEndWith('vault/v1/keys/decrypt');
        });

        it('passes metadata and file-management operations through to the inner disk', function (): void {
            $this->fakeWorkosResponses(array_slice(vaultDiskRoundTripResponses(), 0, 1));

            $vault = Storage::disk('vault');
            $vault->put('a.txt', 'data');

            expect($vault->exists('a.txt'))->toBeTrue()
                ->and($vault->get('missing.txt'))->toBeNull();

            expect($vault->copy('a.txt', 'b.txt'))->toBeTrue()
                ->and(Storage::disk('vault-test-inner')->exists('b.txt'))->toBeTrue();

            expect($vault->move('b.txt', 'c.txt'))->toBeTrue()
                ->and($vault->exists('c.txt'))->toBeTrue()
                ->and($vault->exists('b.txt'))->toBeFalse();

            // ENCRYPTED envelope size on the underlying disk, not plaintext size.
            expect($vault->size('a.txt'))->toBeGreaterThan(strlen('data'));

            expect($vault->delete(['a.txt', 'c.txt']))->toBeTrue()
                ->and($vault->exists('a.txt'))->toBeFalse();

            // Copy/move/exists/size/delete never touched the wire: only the
            // initial put consumed a response.
            expect($this->workosMockHandler->count())->toBe(0);
        });

        it('requires a disk key naming the wrapped disk', function (): void {
            config()->set('filesystems.disks.vault-broken', ['driver' => 'vault']);

            expect(fn () => Storage::disk('vault-broken')->exists('x'))
                ->toThrow(InvalidArgumentException::class, "The 'vault' filesystem driver requires a 'disk' key");
        });
    });
});
