<?php

declare(strict_types=1);

namespace Authkit\Authkit\Vault;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared envelope-encryption entry point for the Vaulted cast and the vault
 * filesystem driver. The SDK's encrypt()/decrypt() are client-side AES-256-GCM:
 * each makes exactly one network call (createDataKey on encrypt, createDecrypt
 * on decrypt) to fetch/unwrap a data key, then does the cipher work locally.
 *
 * Fail-closed by construction: an encrypt failure throws before any ciphertext
 * exists — never add a catch here that falls back to storing plaintext.
 */
final class VaultCrypto
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly ResolvesVaultKeyContext $contextResolver,
    ) {}

    public function encryptAttribute(Model $model, string $attribute, string $plaintext): string
    {
        return $this->clients->client()->vault()->encrypt(
            $plaintext,
            $this->contextResolver->resolve($model, $attribute),
        );
    }

    /**
     * Shared decrypt path for both the Eloquent cast and the filesystem driver —
     * decrypt() takes no context: the wrapped data-key blob embedded in the
     * envelope is self-describing server-side. See spec-phase-9 §8,
     * "Key-context drift".
     */
    public function decrypt(string $envelope): string
    {
        return $this->clients->client()->vault()->decrypt($envelope);
    }

    /**
     * Entry point for callers that already have a concrete context array and
     * are not resolving it from an Eloquent model (the filesystem driver).
     *
     * @param  array<string, string>  $context
     */
    public function encryptWithContext(string $plaintext, array $context): string
    {
        return $this->clients->client()->vault()->encrypt($plaintext, $context);
    }
}
