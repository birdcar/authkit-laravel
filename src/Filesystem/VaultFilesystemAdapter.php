<?php

declare(strict_types=1);

namespace Authkit\Authkit\Filesystem;

use Authkit\Authkit\Exceptions\VaultFileTooLargeException;
use Authkit\Authkit\Vault\VaultCrypto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;

/**
 * Decorator over any configured disk that envelope-encrypts contents through
 * WorkOS Vault on the way in and decrypts on the way out. Not a new storage
 * backend: the wrapped disk named by the vault disk's 'disk' config key does
 * all the actual I/O and only ever sees ciphertext envelopes.
 *
 * There is no streaming encryption — get()/put()/readStream()/writeStream()
 * fully materialize plaintext in PHP memory (the SDK's encrypt()/decrypt()
 * operate on single strings). The size guard replaces pretending to stream.
 *
 * append()/prepend() are decrypt-then-concatenate-then-re-encrypt: every call
 * costs a full file round trip plus a fresh createDataKey network call — do
 * not use this driver for log-like append-heavy files.
 */
final class VaultFilesystemAdapter implements Filesystem
{
    /**
     * @param  array<string, string>  $context
     */
    public function __construct(
        private readonly Filesystem $inner,
        private readonly VaultCrypto $crypto,
        private readonly array $context,
        private readonly int $maxEncryptBytes,
    ) {}

    /**
     * @param  string  $path
     */
    public function get(mixed $path): ?string
    {
        $envelope = $this->inner->get($path);

        return $envelope === null ? null : $this->crypto->decrypt($envelope);
    }

    /**
     * The size guard runs before any network call — an oversized file never
     * triggers a wasted createDataKey round trip.
     *
     * @param  string  $path
     * @param  StreamInterface|File|UploadedFile|string|resource  $contents
     */
    public function put(mixed $path, mixed $contents, mixed $options = []): bool
    {
        $plaintext = $this->normalizeContents($contents);

        if (strlen($plaintext) > $this->maxEncryptBytes) {
            throw VaultFileTooLargeException::forPath((string) $path, strlen($plaintext), $this->maxEncryptBytes);
        }

        $envelope = $this->crypto->encryptWithContext($plaintext, $this->context);

        return $this->inner->put($path, $envelope, $options);
    }

    /**
     * Mirrors Illuminate\Filesystem\FilesystemAdapter's own argument
     * normalization so stream-based uploads behave identically to the stock
     * local driver — the only difference is that the final put() goes through
     * the encrypt path instead of straight to Flysystem.
     *
     * @param  File|UploadedFile|string  $path
     * @param  File|UploadedFile|string|array<string, mixed>|null  $file
     */
    public function putFile(mixed $path, mixed $file = null, mixed $options = []): string|false
    {
        if (is_null($file) || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        $file = is_string($file) ? new File($file) : $file;

        return $this->putFileAs($path, $file, $file->hashName(), $options);
    }

    /**
     * @param  File|UploadedFile|string  $path
     * @param  File|UploadedFile|string|array<string, mixed>|null  $file
     * @param  string|array<string, mixed>|null  $name
     */
    public function putFileAs(mixed $path, mixed $file, mixed $name = null, mixed $options = []): string|false
    {
        if (is_null($name) || is_array($name)) {
            [$path, $file, $name, $options] = ['', $path, $file, $name ?? []];
        }

        $file = is_string($file) ? new File($file) : $file;

        if (! $file instanceof SplFileInfo) {
            throw new InvalidArgumentException('Unsupported file type for the vault filesystem driver.');
        }

        $location = $file->getRealPath();

        if ($location === false) {
            throw new InvalidArgumentException("File [{$file->getPathname()}] does not exist.");
        }

        $stream = fopen($location, 'r');

        if ($stream === false) {
            throw new InvalidArgumentException("File [{$location}] could not be opened.");
        }

        $storedPath = trim((string) $path.'/'.(string) $name, '/');

        $result = $this->put($storedPath, $stream, $options);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $result ? $storedPath : false;
    }

    /**
     * @param  string  $path
     * @param  resource  $resource
     * @param  array<string, mixed>  $options
     */
    public function writeStream(mixed $path, mixed $resource, array $options = []): bool
    {
        $contents = is_resource($resource) ? stream_get_contents($resource) : false;

        return $this->put($path, $contents === false ? '' : $contents, $options);
    }

    /**
     * Native `mixed` because PHP has no resource return type; the docblock is
     * the real contract.
     *
     * @param  string  $path
     * @return resource|null
     */
    public function readStream(mixed $path): mixed
    {
        $plaintext = $this->get($path);

        if ($plaintext === null) {
            return null;
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $plaintext);
        rewind($stream);

        return $stream;
    }

    /**
     * @param  string  $path
     * @param  string  $data
     */
    public function prepend(mixed $path, mixed $data): bool
    {
        return $this->put($path, (string) $data.PHP_EOL.($this->get($path) ?? ''));
    }

    /**
     * @param  string  $path
     * @param  string  $data
     */
    public function append(mixed $path, mixed $data): bool
    {
        return $this->put($path, ($this->get($path) ?? '').PHP_EOL.(string) $data);
    }

    // Pure passthrough — encryption is transparent to these:

    /**
     * @param  string  $path
     */
    public function path(mixed $path): string
    {
        return $this->inner->path($path);
    }

    /**
     * @param  string  $path
     */
    public function exists(mixed $path): bool
    {
        return $this->inner->exists($path);
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function delete(mixed $paths): bool
    {
        return $this->inner->delete($paths);
    }

    /**
     * @param  string  $from
     * @param  string  $to
     */
    public function copy(mixed $from, mixed $to): bool
    {
        return $this->inner->copy($from, $to);
    }

    /**
     * @param  string  $from
     * @param  string  $to
     */
    public function move(mixed $from, mixed $to): bool
    {
        return $this->inner->move($from, $to);
    }

    /**
     * Reports the ENCRYPTED envelope's size on the underlying disk
     * (base64-inflated ciphertext + IV/tag/key-blob header), not the original
     * plaintext size — recomputing plaintext size would mean decrypting on
     * every call, defeating the point of cheap metadata reads.
     *
     * @param  string  $path
     */
    public function size(mixed $path): int
    {
        return $this->inner->size($path);
    }

    /**
     * @param  string  $path
     */
    public function lastModified(mixed $path): int
    {
        return $this->inner->lastModified($path);
    }

    /**
     * @param  string  $path
     */
    public function getVisibility(mixed $path): string
    {
        return $this->inner->getVisibility($path);
    }

    /**
     * @param  string  $path
     * @param  string  $visibility
     */
    public function setVisibility(mixed $path, mixed $visibility): bool
    {
        return $this->inner->setVisibility($path, $visibility);
    }

    /**
     * @param  string|null  $directory
     * @param  bool  $recursive
     * @return array<string>
     */
    public function files(mixed $directory = null, mixed $recursive = false): array
    {
        return $this->inner->files($directory, $recursive);
    }

    /**
     * @param  string|null  $directory
     * @return array<string>
     */
    public function allFiles(mixed $directory = null): array
    {
        return $this->inner->allFiles($directory);
    }

    /**
     * @param  string|null  $directory
     * @param  bool  $recursive
     * @return array<string>
     */
    public function directories(mixed $directory = null, mixed $recursive = false): array
    {
        return $this->inner->directories($directory, $recursive);
    }

    /**
     * @param  string|null  $directory
     * @return array<string>
     */
    public function allDirectories(mixed $directory = null): array
    {
        return $this->inner->allDirectories($directory);
    }

    /**
     * @param  string  $path
     */
    public function makeDirectory(mixed $path): bool
    {
        return $this->inner->makeDirectory($path);
    }

    /**
     * @param  string  $directory
     */
    public function deleteDirectory(mixed $directory): bool
    {
        return $this->inner->deleteDirectory($directory);
    }

    /**
     * @param  StreamInterface|File|UploadedFile|string|resource  $contents
     */
    private function normalizeContents(mixed $contents): string
    {
        if (is_string($contents)) {
            return $contents;
        }

        if (is_resource($contents)) {
            $read = stream_get_contents($contents);

            return $read === false ? '' : $read;
        }

        if ($contents instanceof StreamInterface) {
            return (string) $contents;
        }

        if ($contents instanceof File || $contents instanceof UploadedFile) {
            $location = $contents->getRealPath();
            $read = $location === false ? false : file_get_contents($location);

            return $read === false ? '' : $read;
        }

        throw new InvalidArgumentException('Unsupported contents type for the vault filesystem driver.');
    }
}
