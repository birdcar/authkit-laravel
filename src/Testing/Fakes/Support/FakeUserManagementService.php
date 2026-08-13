<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes\Support;

use DateTimeImmutable;
use WorkOS\HttpClient;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\PaginationOrder;
use WorkOS\Resource\UserApiKey;
use WorkOS\Resource\UserApiKeyWithValue;
use WorkOS\Service\UserManagement;

/**
 * The SDK's UserManagement service with ONLY the user-scoped API key
 * endpoints redirected to the in-memory registry — everything else (login,
 * invitations, user CRUD) is inherited against a REAL HttpClient and behaves
 * exactly as it would unfaked, which is what keeps a partial
 * Authkit::fake(['api-keys']) from breaking unrelated managers that also
 * resolve this service.
 *
 * @internal support type for ApiKeysFake — not part of the public testing API
 */
final class FakeUserManagementService extends UserManagement
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
    public function createUserApiKey(
        string $userId,
        string $name,
        string $organizationId,
        ?array $permissions = null,
        ?DateTimeImmutable $expiresAt = null,
        ?RequestOptions $options = null,
    ): UserApiKeyWithValue {
        $key = $this->registry->createUserKey($userId, $name, $organizationId, $permissions, $expiresAt);

        return UserApiKeyWithValue::fromArray($this->registry->toWireArray($key, withValue: true));
    }

    public function listUserApiKeys(
        string $userId,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        PaginationOrder $order = PaginationOrder::Desc,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        $keys = array_map(
            fn (array $key): UserApiKey => UserApiKey::fromArray($this->registry->toWireArray($key)),
            $this->registry->listUserKeys($userId, $organizationId),
        );

        return new PaginatedResponse($keys, []);
    }
}
