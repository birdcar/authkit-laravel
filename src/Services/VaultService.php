<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use WorkOS\WorkOS;

class VaultService
{
    public function __construct(
        private readonly WorkOS $client,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function store(string $name, string $value, array $context = []): array
    {
        return $this->client->vault()->createObject(
            name: $name,
            value: $value,
            keyContext: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->vault()->readObject(objectId: $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function getByName(string $name): array
    {
        return $this->client->vault()->readObjectByName(name: $name);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $id, string $value): array
    {
        return $this->client->vault()->updateObject(objectId: $id, value: $value);
    }

    public function delete(string $id): void
    {
        $this->client->vault()->deleteObject(objectId: $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 10, ?string $after = null): array
    {
        $result = $this->client->vault()->listObjects(limit: $limit, after: $after);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function versions(string $id): array
    {
        return $this->client->vault()->listObjectVersions(objectId: $id);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function encrypt(string $plaintext, array $context = []): string
    {
        return $this->client->vault()->encrypt(data: $plaintext, keyContext: $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function decrypt(string $ciphertext, array $context = []): string
    {
        return $this->client->vault()->decrypt(encryptedData: $ciphertext);
    }
}
