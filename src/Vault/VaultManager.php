<?php

declare(strict_types=1);

namespace Authkit\Authkit\Vault;

use Authkit\Authkit\Contracts\WorkosClientManager;
use DateTimeImmutable;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\ObjectMetadata;
use WorkOS\Resource\ObjectWithoutValue;
use WorkOS\Resource\VaultObject;
use WorkOS\Resource\VaultOrder;
use WorkOS\Resource\VersionListResponse;
use WorkOS\Service\Vault;

/**
 * Direct passthroughs to WorkOS Vault's key-value surface. Unlike the Vaulted
 * cast and the vault filesystem driver, KV values are encrypted SERVER-side:
 * the plaintext value travels over the wire and WorkOS encrypts at rest using
 * the KEK resolved from the key context.
 *
 * Optimistic locking is opt-in, not default: update()/delete() without a
 * versionCheck let the last write silently win — WorkOS raises a
 * ConflictException only when a supplied versionCheck no longer matches.
 */
final class VaultManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * Include the org's `organization_id` in $keyContext to route a
     * BYOK-enabled organization's secrets to its own CMK — the same
     * convention DefaultVaultKeyContextResolver applies for vaulted
     * attributes, but enforced by the caller here since set() is a raw
     * passthrough.
     *
     * @param  array<string, string>  $keyContext
     */
    public function set(array $keyContext, string $name, string $value): ObjectMetadata
    {
        return $this->vault()->createKv($keyContext, $name, $value);
    }

    public function get(string $name): VaultObject
    {
        return $this->vault()->getName($name);
    }

    public function find(string $id): VaultObject
    {
        return $this->vault()->getKv($id);
    }

    public function update(string $id, string $value, ?string $versionCheck = null): ObjectWithoutValue
    {
        return $this->vault()->updateKv($id, $value, $versionCheck);
    }

    public function delete(string $id, ?string $versionCheck = null): void
    {
        $this->vault()->deleteKv($id, $versionCheck);
    }

    /**
     * Metadata-only read — never touches or returns the stored value.
     */
    public function metadata(string $id): ObjectWithoutValue
    {
        return $this->vault()->listKvMetadata($id);
    }

    public function versions(string $id): VersionListResponse
    {
        return $this->vault()->listKvVersions($id);
    }

    /**
     * Cursor-paginated listing of ObjectSummary items; forwards every filter
     * verbatim.
     */
    public function list(
        ?int $limit = null,
        ?string $before = null,
        ?string $after = null,
        ?VaultOrder $order = null,
        ?string $search = null,
        ?DateTimeImmutable $updatedAfter = null,
    ): PaginatedResponse {
        return $this->vault()->listKv($limit, $before, $after, $order, $search, $updatedAfter);
    }

    private function vault(): Vault
    {
        return $this->clients->client()->vault();
    }
}
