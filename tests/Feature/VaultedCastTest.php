<?php

declare(strict_types=1);

use Authkit\Authkit\Exceptions\InvalidVaultKeyContextResolverException;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\VaultDemoRecord;
use WorkOS\Exception\ServerException;
use WorkOS\Exception\WorkOSException;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler — emulate has ZERO Vault coverage, so this suite
// never boots workos/emulate. Every case seeds its own response queue inline.

/**
 * The canonical round-trip pair: the SAME data key is returned by the mocked
 * createDataKey (consumed inside encrypt()) and createDecrypt (consumed inside
 * decrypt()), so the SDK's local AES-256-GCM genuinely recovers the original
 * plaintext. This fails if the envelope format, the LEB128 length-prefix
 * framing, or the IV/tag placement were broken — not just the endpoint choice.
 *
 * @return array<int, Response>
 */
function vaultedCastRoundTripResponses(): array
{
    $b64Key = base64_encode(random_bytes(32)); // AES-256 key, shared across both mocked calls

    return [
        new Response(200, [], (string) json_encode([
            'context' => ['probe' => 'value'],
            'data_key' => $b64Key,
            'encrypted_keys' => base64_encode('opaque-wrapped-key-blob'), // never unwrapped locally
            'id' => 'key_123',
        ])),
        new Response(200, [], (string) json_encode([
            'data_key' => $b64Key, // SAME key — this is what makes the round trip real
            'id' => 'key_123',
        ])),
    ];
}

/**
 * Org-aware fixture: same table as VaultDemoRecord, plus the duck-typed hook
 * DefaultVaultKeyContextResolver looks for.
 */
final class VaultOrgDemoRecord extends VaultDemoRecord
{
    protected $table = 'vault_demo_records';

    public function workosOrganizationId(): ?string
    {
        return 'org_01HCAST';
    }
}

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

describe('Vault', function (): void {
    describe('VaultedCast', function (): void {
        it('round-trips a model attribute through real envelope encryption', function (string $plaintext): void {
            $this->fakeWorkosResponses(vaultedCastRoundTripResponses());

            $record = VaultDemoRecord::create(['secret' => $plaintext]);

            $stored = DB::table('vault_demo_records')->where('id', $record->getKey())->value('secret');

            expect($stored)->toBeString()
                ->and($stored)->not->toBe($plaintext); // ciphertext envelope at rest, never plaintext

            $fresh = VaultDemoRecord::query()->findOrFail($record->getKey());

            expect($fresh->secret)->toBe($plaintext);

            expect($this->workosRequestHistory)->toHaveCount(2)
                ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())->toEndWith('vault/v1/keys/data-key')
                ->and($this->workosRequestHistory[1]['request']->getUri()->getPath())->toEndWith('vault/v1/keys/decrypt');
        })->with([
            'plain ascii' => 'hunter2',
            'empty string' => '', // legitimate plaintext, NOT conflated with null
            'unicode' => 'pässwörd—秘密🔐',
            '4KB blob' => str_repeat('A7f!', 1024),
        ]);

        it('sends the resolver-derived key context on the encrypt wire call', function (): void {
            $this->fakeWorkosResponses(vaultedCastRoundTripResponses());

            VaultDemoRecord::create(['secret' => 'context-probe']);

            $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

            expect($body)->toBe(['context' => [
                'model' => VaultDemoRecord::class,
                'attribute' => 'secret',
            ]]);
        });

        it('includes organization_id in the key context for org-aware models', function (): void {
            $this->fakeWorkosResponses(vaultedCastRoundTripResponses());

            VaultOrgDemoRecord::create(['secret' => 'tenant-secret']);

            $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

            expect($body)->toBe(['context' => [
                'model' => VaultOrgDemoRecord::class,
                'attribute' => 'secret',
                'organization_id' => 'org_01HCAST',
            ]]);
        });

        it('stores null as null without any crypto call', function (): void {
            $this->fakeWorkosResponses([]); // any network call would throw on the empty queue

            $record = VaultDemoRecord::create(['secret' => null]);

            expect(DB::table('vault_demo_records')->where('id', $record->getKey())->value('secret'))->toBeNull();

            $fresh = VaultDemoRecord::query()->findOrFail($record->getKey());

            expect($fresh->secret)->toBeNull()
                ->and($this->workosRequestHistory)->toHaveCount(0);
        });

        it('throws on a corrupted envelope instead of returning garbage', function (): void {
            $this->fakeWorkosResponses(vaultedCastRoundTripResponses());

            $record = VaultDemoRecord::create(['secret' => 'pristine']);

            // Flip a ciphertext byte near the end of the envelope — the exact
            // damage a VARCHAR-truncated column or manual DB edit causes. GCM
            // tag verification must fail loudly; this is the integrity check
            // working, not a bug to route around (spec-phase-9 §8).
            $envelope = (string) DB::table('vault_demo_records')->where('id', $record->getKey())->value('secret');
            $bytes = (string) base64_decode($envelope, true);
            $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0xFF);

            DB::table('vault_demo_records')->where('id', $record->getKey())->update([
                'secret' => base64_encode($bytes),
            ]);

            $fresh = VaultDemoRecord::query()->findOrFail($record->getKey());

            expect(fn (): ?string => $fresh->secret)->toThrow(RuntimeException::class, 'AES-GCM decryption failed');
        });

        it('fails closed when the data-key mint fails: no row, no plaintext fallback', function (): void {
            config()->set('authkit.max_retries', 0); // the SDK retries 5xx internally; keep the test to one response

            $this->fakeWorkosResponses([
                new Response(500, [], (string) json_encode(['message' => 'vault is down'])),
            ]);

            try {
                VaultDemoRecord::create(['secret' => 'never-persisted']);

                $this->fail('Expected a WorkOSException was not thrown.');
            } catch (ServerException $exception) {
                // The SDK's own 5xx mapping — every WorkOSException subclass
                // propagates out of encrypt() before ciphertext exists.
                expect($exception)->toBeInstanceOf(WorkOSException::class);
            }

            expect(VaultDemoRecord::query()->count())->toBe(0); // set() threw before any INSERT was built
        });

        it('fails fast with an actionable exception when the key-context resolver is misconfigured', function (): void {
            config()->set('authkit.vault.key_context_resolver', 'App\NonexistentVaultResolver');

            $this->fakeWorkosResponses([]); // the container throws before any network call is attempted

            try {
                VaultDemoRecord::create(['secret' => 'x']);

                $this->fail('Expected '.InvalidVaultKeyContextResolverException::class.' was not thrown.');
            } catch (InvalidVaultKeyContextResolverException $exception) {
                expect($exception->getMessage())
                    ->toContain('App\NonexistentVaultResolver')
                    ->toContain('authkit.vault.key_context_resolver');
            }

            expect(VaultDemoRecord::query()->count())->toBe(0)
                ->and($this->workosRequestHistory)->toHaveCount(0);
        });
    });
});
