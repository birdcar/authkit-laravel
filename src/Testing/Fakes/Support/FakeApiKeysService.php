<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes\Support;

use DateTimeImmutable;
use WorkOS\HttpClient;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\ApiKeyValidationResponse;
use WorkOS\Resource\OrganizationApiKey;
use WorkOS\Resource\OrganizationApiKeyWithValue;
use WorkOS\Resource\PaginationOrder;
use WorkOS\Service\ApiKeys;

/**
 * The SDK's ApiKeys service backed by the in-memory registry for exactly the
 * endpoints the package's key surface uses. Constructed with a REAL
 * HttpClient so any inherited method not overridden here behaves like the
 * genuine service instead of fataling on missing state.
 *
 * @internal support type for ApiKeysFake — not part of the public testing API
 */
final class FakeApiKeysService extends ApiKeys
{
    public function __construct(
        HttpClient $httpClient,
        private readonly ApiKeyRegistry $registry,
    ) {
        parent::__construct($httpClient);
    }

    /**
     * @param  list<string>|null  $permissions
     */
    public function createOrganizationApiKey(
        string $organizationId,
        string $name,
        ?array $permissions = null,
        ?DateTimeImmutable $expiresAt = null,
        ?RequestOptions $options = null,
    ): OrganizationApiKeyWithValue {
        $key = $this->registry->createOrganizationKey($organizationId, $name, $permissions, $expiresAt);

        return OrganizationApiKeyWithValue::fromArray($this->registry->toWireArray($key, withValue: true));
    }

    public function listOrganizationApiKeys(
        string $organizationId,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        PaginationOrder $order = PaginationOrder::Desc,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        $keys = array_map(
            fn (array $key): OrganizationApiKey => OrganizationApiKey::fromArray($this->registry->toWireArray($key)),
            $this->registry->listOrganizationKeys($organizationId),
        );

        return new PaginatedResponse($keys, []);
    }

    /**
     * Mirrors the real endpoint's semantics: an unknown, revoked, or expired
     * key is a 200 with `api_key: null`, never an exception.
     */
    public function createValidation(
        string $value,
        ?RequestOptions $options = null,
    ): ApiKeyValidationResponse {
        $key = $this->registry->findValid($value);

        return ApiKeyValidationResponse::fromArray(
            $key === null ? [] : ['api_key' => $this->registry->toWireArray($key)],
        );
    }

    public function deleteApiKey(
        string $id,
        ?RequestOptions $options = null,
    ): void {
        $this->registry->revoke($id);
    }
}
