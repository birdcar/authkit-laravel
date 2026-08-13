<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes\Support;

use Authkit\Authkit\Testing\Fakes\ApiKeysFake;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The in-memory key store shared by {@see FakeApiKeysService} and
 * {@see FakeUserManagementService} — one registry per
 * {@see ApiKeysFake} instance, so user-scoped
 * and organization-scoped keys validate against the same set the way one
 * WorkOS environment would.
 *
 * @internal support type for ApiKeysFake — not part of the public testing API
 *
 * @phpstan-type KeyEntry array{
 *     id: string,
 *     value: string,
 *     name: string,
 *     owner_type: 'organization'|'user',
 *     owner_id: string,
 *     organization_id: string|null,
 *     permissions: list<string>,
 *     expires_at: DateTimeImmutable|null,
 *     created_at: DateTimeImmutable,
 *     revoked: bool,
 * }
 */
final class ApiKeyRegistry
{
    /** @var array<string, KeyEntry> */
    private array $keys = [];

    /** @var list<string> */
    private array $revoked = [];

    private int $sequence = 0;

    /**
     * @param  list<string>|null  $permissions
     * @return KeyEntry
     */
    public function createUserKey(string $userId, string $name, string $organizationId, ?array $permissions, ?DateTimeImmutable $expiresAt): array
    {
        return $this->store('user', $userId, $name, $organizationId, $permissions, $expiresAt);
    }

    /**
     * @param  list<string>|null  $permissions
     * @return KeyEntry
     */
    public function createOrganizationKey(string $organizationId, string $name, ?array $permissions, ?DateTimeImmutable $expiresAt): array
    {
        return $this->store('organization', $organizationId, $name, null, $permissions, $expiresAt);
    }

    /**
     * @return list<KeyEntry>
     */
    public function listUserKeys(string $userId, ?string $organizationId = null): array
    {
        return array_values(array_filter(
            $this->keys,
            static fn (array $key): bool => $key['owner_type'] === 'user'
                && $key['owner_id'] === $userId
                && ! $key['revoked']
                && ($organizationId === null || $key['organization_id'] === $organizationId),
        ));
    }

    /**
     * @return list<KeyEntry>
     */
    public function listOrganizationKeys(string $organizationId): array
    {
        return array_values(array_filter(
            $this->keys,
            static fn (array $key): bool => $key['owner_type'] === 'organization'
                && $key['owner_id'] === $organizationId
                && ! $key['revoked'],
        ));
    }

    /**
     * @return KeyEntry|null
     */
    public function findValid(string $value): ?array
    {
        foreach ($this->keys as $key) {
            if ($key['value'] !== $value || $key['revoked']) {
                continue;
            }

            if ($key['expires_at'] !== null && $key['expires_at'] < new DateTimeImmutable) {
                continue;
            }

            return $key;
        }

        return null;
    }

    public function revoke(string $id): void
    {
        if (! isset($this->keys[$id])) {
            // Production parity: WorkOS 404s a delete for an unknown key id,
            // and recording it would let assertRevoked() pass for a key that
            // never existed.
            throw new InvalidArgumentException(
                "No fake API key [{$id}] exists to revoke. Create one first through the key traits.",
            );
        }

        $this->keys[$id]['revoked'] = true;
        $this->revoked[] = $id;
    }

    /**
     * @return list<KeyEntry>
     */
    public function all(): array
    {
        return array_values($this->keys);
    }

    /**
     * @return list<string>
     */
    public function revokedIds(): array
    {
        return $this->revoked;
    }

    /**
     * The wire shape of a key entry, as the WorkOS API would serialize it.
     *
     * @param  KeyEntry  $key
     * @return array<string, mixed>
     */
    public function toWireArray(array $key, bool $withValue = false): array
    {
        $owner = $key['owner_type'] === 'user'
            ? ['type' => 'user', 'id' => $key['owner_id'], 'organization_id' => (string) $key['organization_id']]
            : ['type' => 'organization', 'id' => $key['owner_id']];

        $wire = [
            'object' => 'api_key',
            'id' => $key['id'],
            'owner' => $owner,
            'name' => $key['name'],
            'obfuscated_value' => 'sk_fake_...'.substr($key['value'], -4),
            'expires_at' => $key['expires_at']?->format(DateTimeInterface::RFC3339_EXTENDED),
            'permissions' => $key['permissions'],
            'created_at' => $key['created_at']->format(DateTimeInterface::RFC3339_EXTENDED),
            'updated_at' => $key['created_at']->format(DateTimeInterface::RFC3339_EXTENDED),
        ];

        if ($withValue) {
            $wire['value'] = $key['value'];
        }

        return $wire;
    }

    /**
     * @param  'organization'|'user'  $ownerType
     * @param  list<string>|null  $permissions
     * @return KeyEntry
     */
    private function store(string $ownerType, string $ownerId, string $name, ?string $organizationId, ?array $permissions, ?DateTimeImmutable $expiresAt): array
    {
        $sequence = ++$this->sequence;

        $key = [
            'id' => 'api_key_fake_'.$sequence,
            'value' => 'sk_fake_'.$sequence.'_'.bin2hex(random_bytes(8)),
            'name' => $name,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'organization_id' => $organizationId,
            'permissions' => $permissions ?? [],
            'expires_at' => $expiresAt,
            'created_at' => new DateTimeImmutable,
            'revoked' => false,
        ];

        return $this->keys[$key['id']] = $key;
    }
}
