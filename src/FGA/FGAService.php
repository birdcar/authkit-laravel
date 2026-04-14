<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\FGA;

use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\Client;

class FGAService
{
    /** @var array<string, FGAAccessResult> */
    private array $checkCache = [];

    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function check(
        string $userId,
        string $permission,
        string $resourceType,
        string $resourceId,
    ): bool {
        $cacheKey = $this->cacheKey($userId, $permission, $resourceType, $resourceId);

        if (isset($this->checkCache[$cacheKey])) {
            return $this->checkCache[$cacheKey]->allowed;
        }

        $result = $this->performCheck($userId, $permission, $resourceType, $resourceId);
        $this->checkCache[$cacheKey] = $result;

        return $result->allowed;
    }

    public function checkForCurrentUser(
        string $permission,
        string $resourceType,
        string $resourceId,
    ): bool {
        $session = $this->session->getSession();
        if ($session === null) {
            return false;
        }

        return $this->check($session->userId, $permission, $resourceType, $resourceId);
    }

    /**
     * @return array<FGAResource>
     */
    public function listResources(
        string $userId,
        string $permission,
        string $resourceType,
    ): array {
        try {
            /** @var array<string, mixed> $response */
            $response = Client::request(
                Client::METHOD_GET,
                'fga/v1/access-checks/resources',
                null,
                [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'resource_type' => $resourceType,
                ],
                true,
            );

            /** @var array<array{resource_type: string, resource_id: string}> $data */
            $data = $response['data'] ?? [];

            return array_map(
                fn (array $r) => new FGAResource($r['resource_type'], $r['resource_id']),
                $data,
            );
        } catch (\Exception) {
            return [];
        }
    }

    public function assign(
        string $userId,
        string $roleSlug,
        string $resourceType,
        string $resourceId,
    ): bool {
        try {
            Client::request(
                Client::METHOD_POST,
                'fga/v1/role-assignments',
                null,
                [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ],
                true,
            );

            $this->flushCache($userId);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function unassign(
        string $userId,
        string $roleSlug,
        string $resourceType,
        string $resourceId,
    ): bool {
        try {
            Client::request(
                Client::METHOD_DELETE,
                'fga/v1/role-assignments',
                null,
                [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ],
                true,
            );

            $this->flushCache($userId);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function flushCache(?string $userId = null): void
    {
        if ($userId === null) {
            $this->checkCache = [];

            return;
        }

        foreach (array_keys($this->checkCache) as $key) {
            if (str_starts_with($key, $userId.':')) {
                unset($this->checkCache[$key]);
            }
        }
    }

    private function performCheck(
        string $userId,
        string $permission,
        string $resourceType,
        string $resourceId,
    ): FGAAccessResult {
        try {
            /** @var array<string, mixed> $response */
            $response = Client::request(
                Client::METHOD_POST,
                'fga/v1/access-checks',
                null,
                [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ],
                true,
            );

            $allowed = (bool) ($response['allowed'] ?? false);
        } catch (\Exception) {
            $allowed = false;
        }

        return new FGAAccessResult(
            allowed: $allowed,
            userId: $userId,
            permission: $permission,
            resource: new FGAResource($resourceType, $resourceId),
        );
    }

    private function cacheKey(
        string $userId,
        string $permission,
        string $resourceType,
        string $resourceId,
    ): string {
        return "{$userId}:{$permission}:{$resourceType}:{$resourceId}";
    }
}
