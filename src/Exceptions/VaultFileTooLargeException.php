<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use RuntimeException;

/**
 * Thrown by the vault filesystem driver BEFORE any network call when a write
 * exceeds the configured size guard. There is no streaming cipher in the
 * WorkOS SDK — encryption fully materializes plaintext in memory (a 3-4x
 * peak), so the guard is what stands between a large upload and an OOM.
 */
final class VaultFileTooLargeException extends RuntimeException
{
    public static function forPath(string $path, int $actualBytes, int $maxEncryptBytes): self
    {
        return new self(sprintf(
            'Refusing to vault-encrypt [%s]: %d bytes exceeds the %d-byte limit. '
            .'Raise it via authkit.vault.filesystem.max_encrypt_bytes (or the disk\'s own '
            ."'max_encrypt_bytes' key), or store large media on a plain disk — application-layer "
            .'envelope encryption is meant for documents, certs, and small exports.',
            $path,
            $actualBytes,
            $maxEncryptBytes,
        ));
    }
}
