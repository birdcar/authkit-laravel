<?php

declare(strict_types=1);

namespace Authkit\Authkit\Casts;

use Authkit\Authkit\Vault\VaultCrypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Envelope-encrypts a single Eloquent attribute through WorkOS Vault's
 * client-side data-key encryption.
 *
 * NOT SEARCHABLE OR QUERYABLE. Every encrypt() call mints a fresh data key
 * and a fresh random IV, so the stored ciphertext is non-deterministic —
 * two saves of the identical plaintext produce different bytes. Do not:
 *   - WHERE this column, LIKE this column, or add a unique index on it
 *   - Expect re-saving the same value to no-op at the storage layer
 * If you need to look records up by a secret value, maintain a separate
 * deterministic hash column outside of this cast.
 *
 * The backing column MUST be TEXT/LONGTEXT, never VARCHAR. Truncation
 * corrupts the envelope's AES-GCM tag and surfaces as a decrypt-time
 * RuntimeException, not a save-time error — see spec-phase-9 §8.
 *
 * @implements CastsAttributes<string, string>
 */
final class Vaulted implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(VaultCrypto::class)->decrypt((string) $value);
    }

    /**
     * Null-safety short-circuits on null only: an empty string is a legitimate
     * plaintext and is encrypted normally — it is not conflated with "absent".
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(VaultCrypto::class)->encryptAttribute($model, $key, (string) $value);
    }
}
