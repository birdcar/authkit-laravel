<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\FGA;

use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Auth\SessionManager;

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
            $response = $this->request('get', 'fga/v1/access-checks/resources', [
                'user_id' => $userId,
                'permission' => $permission,
                'resource_type' => $resourceType,
            ]);

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
            $this->request('post', 'fga/v1/role-assignments', [
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ]);

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
            $this->request('delete', 'fga/v1/role-assignments', [
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ]);

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
            $response = $this->request('post', 'fga/v1/access-checks', [
                'user_id' => $userId,
                'permission' => $permission,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ]);

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $url = rtrim((string) config('workos.api_base_url', 'https://api.workos.com'), '/').'/'.$path;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('workos.api_key'),
        ])->$method($url, $data);

        if (! $response->successful()) {
            throw new \RuntimeException("WorkOS FGA API error: {$response->status()} {$response->body()}");
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
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
