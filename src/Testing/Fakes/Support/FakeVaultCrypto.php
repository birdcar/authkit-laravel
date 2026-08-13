<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes\Support;

use Authkit\Authkit\Vault\ResolvesVaultKeyContext;
use Authkit\Authkit\Vault\VaultCrypto;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Envelope "encryption" with no key service: seal is a marker-prefixed
 * base64 wrapper, so the Vaulted cast and the vault filesystem driver run
 * their REAL code paths offline. Key-context resolution stays real too —
 * encryptAttribute() still consults the bound {@see ResolvesVaultKeyContext},
 * so BYOK-style context conventions are exercised, not skipped.
 *
 * The marker prefix is deliberate and load-bearing: fake output must be
 * VISIBLY not ciphertext, so a value that leaks out of a test (into a
 * fixture, a seeder, a copied .env) can never masquerade as encrypted data.
 *
 * @internal support type for VaultFake — reach it via VaultFake::crypto()
 */
final class FakeVaultCrypto extends VaultCrypto
{
    /**
     * Every fake envelope starts with this. NOT a security boundary — the
     * payload is plain base64 — it exists so tests can assert storage is
     * non-plaintext and humans can spot fake output at a glance.
     */
    public const string MARKER = 'authkit-fake-vault:v1:';

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    public function encryptAttribute(Model $model, string $attribute, string $plaintext): string
    {
        return $this->seal($plaintext, app(ResolvesVaultKeyContext::class)->resolve($model, $attribute));
    }

    /**
     * @param  array<string, string>  $context
     */
    public function encryptWithContext(string $plaintext, array $context): string
    {
        return $this->seal($plaintext, $context);
    }

    public function decrypt(string $envelope): string
    {
        if (! str_starts_with($envelope, self::MARKER)) {
            throw new InvalidArgumentException(
                'FakeVaultCrypto was asked to decrypt a value it did not seal. Only envelopes produced '
                .'while the vault fake was active can be opened here — real Vault ciphertext needs the real VaultCrypto.',
            );
        }

        $decoded = base64_decode(substr($envelope, strlen(self::MARKER)), strict: true);
        $payload = $decoded === false ? null : json_decode($decoded, associative: true);

        if (! is_array($payload) || ! is_string($payload['value'] ?? null)) {
            throw new InvalidArgumentException('FakeVaultCrypto envelope is corrupted and cannot be opened.');
        }

        return $payload['value'];
    }

    /**
     * The key context a fake envelope was sealed under — how a test asserts
     * BYOK-style context routing without real key infrastructure.
     *
     * @return array<string, string>
     */
    public function sealedContext(string $envelope): array
    {
        $this->decrypt($envelope); // validates marker + integrity

        /** @var array{context: array<string, string>, value: string} $payload */
        $payload = json_decode((string) base64_decode(substr($envelope, strlen(self::MARKER)), strict: true), associative: true);

        return $payload['context'];
    }

    /**
     * @param  array<string, string>  $context
     */
    private function seal(string $plaintext, array $context): string
    {
        return self::MARKER.base64_encode((string) json_encode(['context' => $context, 'value' => $plaintext]));
    }
}
