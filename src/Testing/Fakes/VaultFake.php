<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Testing\Fakes\Support\FakeVaultCrypto;
use Authkit\Authkit\Vault\VaultManager;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use WorkOS\Exception\ConflictException;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\ObjectMetadata;
use WorkOS\Resource\ObjectSummary;
use WorkOS\Resource\ObjectWithoutValue;
use WorkOS\Resource\VaultObject;
use WorkOS\Resource\VaultOrder;
use WorkOS\Resource\VersionListResponse;

/**
 * An in-memory {@see VaultManager}: the KV surface backed by a local map
 * (with per-object version history), paired with {@see FakeVaultCrypto} so
 * the Vaulted cast and the vault filesystem disk run their real code paths
 * offline. `Authkit::fake(['vault'])` binds both halves together.
 */
final class VaultFake extends VaultManager
{
    /**
     * @var array<string, array{
     *     id: string,
     *     name: string,
     *     value: string,
     *     context: array<string, string>,
     *     versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>,
     * }>
     */
    private array $objects = [];

    /** @var list<string> object names passed to set() */
    private array $sets = [];

    /** @var list<string> object ids passed to update() */
    private array $updates = [];

    /** @var list<string> object ids passed to delete() */
    private array $deletes = [];

    private int $sequence = 0;

    public function __construct(private readonly FakeVaultCrypto $fakeCrypto = new FakeVaultCrypto)
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    /**
     * The crypto half bound alongside this fake — exposes the marker constant
     * and sealedContext() for asserting what the Vaulted cast produced.
     */
    public function crypto(): FakeVaultCrypto
    {
        return $this->fakeCrypto;
    }

    /**
     * @param  array<string, string>  $keyContext
     */
    public function set(array $keyContext, string $name, string $value): ObjectMetadata
    {
        $existing = $this->findByName($name);

        if ($existing !== null) {
            $this->pushVersion($existing['id'], $value);
        } else {
            $id = 'vault_object_fake_'.++$this->sequence;

            $this->objects[$id] = [
                'id' => $id,
                'name' => $name,
                'value' => $value,
                'context' => $keyContext,
                'versions' => [$this->version($value)],
            ];
        }

        $this->sets[] = $name;

        $object = $this->findByName($name) ?? throw new InvalidArgumentException('unreachable');

        return $this->metadataFor($object);
    }

    public function get(string $name): VaultObject
    {
        $object = $this->findByName($name) ?? throw new InvalidArgumentException(
            "No fake vault object is named [{$name}]. Store one first with Vault::set().",
        );

        return $this->objectFor($object);
    }

    public function find(string $id): VaultObject
    {
        return $this->objectFor($this->entry($id));
    }

    public function update(string $id, string $value, ?string $versionCheck = null): ObjectWithoutValue
    {
        $entry = $this->entry($id);

        $this->guardVersion($entry, $versionCheck);
        $this->pushVersion($entry['id'], $value);
        $this->updates[] = $id;

        return $this->withoutValueFor($this->entry($id));
    }

    public function delete(string $id, ?string $versionCheck = null): void
    {
        $this->guardVersion($this->entry($id), $versionCheck);

        unset($this->objects[$id]);
        $this->deletes[] = $id;
    }

    public function metadata(string $id): ObjectWithoutValue
    {
        return $this->withoutValueFor($this->entry($id));
    }

    public function versions(string $id): VersionListResponse
    {
        $entry = $this->entry($id);
        $lastIndex = count($entry['versions']) - 1;

        return VersionListResponse::fromArray([
            'data' => array_map(
                static fn (array $version, int $index): array => [
                    'id' => $version['id'],
                    'etag' => hash('sha256', $version['value']),
                    'size' => strlen($version['value']),
                    'current_version' => $index === $lastIndex,
                    'created_at' => $version['created_at']->format(DateTimeInterface::RFC3339_EXTENDED),
                ],
                $entry['versions'],
                array_keys($entry['versions']),
            ),
            'list_metadata' => ['before' => null, 'after' => null],
        ]);
    }

    public function list(
        ?int $limit = null,
        ?string $before = null,
        ?string $after = null,
        ?VaultOrder $order = null,
        ?string $search = null,
        ?DateTimeImmutable $updatedAfter = null,
    ): PaginatedResponse {
        $matches = array_values(array_filter(
            $this->objects,
            static fn (array $object): bool => $search === null || str_contains($object['name'], $search),
        ));

        return new PaginatedResponse(array_map(
            fn (array $object): ObjectSummary => new ObjectSummary($object['id'], $object['name'], $this->latestVersion($object)['created_at']),
            $matches,
        ), []);
    }

    public function assertSet(string $name): void
    {
        Assert::assertContains($name, $this->sets, sprintf(
            'Expected a vault object named [%s] to be set, but it was not. %s',
            $name,
            $this->describeObjects(),
        ));
    }

    public function assertUpdated(string $id): void
    {
        Assert::assertContains($id, $this->updates, "Expected vault object [{$id}] to be updated, but it was not.");
    }

    public function assertDeleted(string $id): void
    {
        Assert::assertContains($id, $this->deletes, "Expected vault object [{$id}] to be deleted, but it was not.");
    }

    public function assertNothingStored(): void
    {
        Assert::assertEmpty($this->objects, sprintf('Expected the fake vault to be empty. %s', $this->describeObjects()));
    }

    /**
     * @return array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}
     */
    private function entry(string $id): array
    {
        return $this->objects[$id] ?? throw new InvalidArgumentException(
            "No fake vault object has the id [{$id}]. Store one first with Vault::set().",
        );
    }

    /**
     * @return array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}|null
     */
    private function findByName(string $name): ?array
    {
        foreach ($this->objects as $object) {
            if ($object['name'] === $name) {
                return $object;
            }
        }

        return null;
    }

    /**
     * Same optimistic-locking semantics the real manager documents: no
     * versionCheck means last write wins; a supplied versionCheck that no
     * longer matches the current version raises ConflictException — so a
     * consumer's concurrency branch is exercisable offline.
     *
     * @param  array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}  $entry
     */
    private function guardVersion(array $entry, ?string $versionCheck): void
    {
        if ($versionCheck === null || $versionCheck === $this->latestVersion($entry)['id']) {
            return;
        }

        throw new ConflictException(
            sprintf('Version check failed for vault object [%s]: the object has been modified.', $entry['id']),
            statusCode: 409,
        );
    }

    private function pushVersion(string $id, string $value): void
    {
        $entry = $this->entry($id);

        $entry['value'] = $value;
        $entry['versions'][] = $this->version($value);

        $this->objects[$id] = $entry;
    }

    /**
     * @return array{id: string, value: string, created_at: DateTimeImmutable}
     */
    private function version(string $value): array
    {
        return [
            'id' => 'vault_version_fake_'.++$this->sequence,
            'value' => $value,
            'created_at' => new DateTimeImmutable,
        ];
    }

    /**
     * @param  array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}  $object
     */
    private function objectFor(array $object): VaultObject
    {
        return new VaultObject(
            id: $object['id'],
            metadata: $this->metadataFor($object),
            name: $object['name'],
            value: $object['value'],
        );
    }

    /**
     * @param  array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}  $object
     */
    private function withoutValueFor(array $object): ObjectWithoutValue
    {
        return new ObjectWithoutValue(
            id: $object['id'],
            metadata: $this->metadataFor($object),
            name: $object['name'],
        );
    }

    /**
     * @param  array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}  $object
     */
    private function metadataFor(array $object): ObjectMetadata
    {
        $latest = $this->latestVersion($object);

        return ObjectMetadata::fromArray([
            'context' => $object['context'],
            'environment_id' => 'environment_fake',
            'id' => $object['id'],
            'key_id' => 'key_fake',
            'updated_at' => $latest['created_at']->format(DateTimeInterface::RFC3339_EXTENDED),
            'updated_by' => ['id' => 'actor_fake', 'name' => 'Vault fake'],
            'version_id' => $latest['id'],
        ]);
    }

    /**
     * @param  array{id: string, name: string, value: string, context: array<string, string>, versions: list<array{id: string, value: string, created_at: DateTimeImmutable}>}  $object
     * @return array{id: string, value: string, created_at: DateTimeImmutable}
     */
    private function latestVersion(array $object): array
    {
        return $object['versions'][count($object['versions']) - 1];
    }

    private function describeObjects(): string
    {
        if ($this->objects === []) {
            return 'The fake vault holds no objects.';
        }

        $names = array_map(static fn (array $object): string => $object['name'], $this->objects);

        return 'Stored objects: '.implode(', ', array_values($names)).'.';
    }
}
