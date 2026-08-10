<?php

declare(strict_types=1);

namespace Authkit\Authkit\Vault;

use Illuminate\Database\Eloquent\Model;

/**
 * Derives the WorkOS Vault key context for a model attribute.
 *
 * The key context governs which key-encryption-key wraps the fresh data key
 * minted at encrypt time — it is a tenant-isolation boundary, not a lookup
 * key. Getting it wrong is a silent failure: decryption still succeeds (the
 * envelope's wrapped key blob is self-describing), so only context-derivation
 * tests catch a drift. Swappable via `authkit.vault.key_context_resolver`.
 */
interface ResolvesVaultKeyContext
{
    /**
     * @return array<string, string>
     */
    public function resolve(Model $model, string $attribute): array;
}
