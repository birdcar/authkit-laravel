<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use Illuminate\Support\Facades\Http;

class PipesService
{
    /**
     * @return array<string, mixed>
     */
    public function listProviders(): array
    {
        return $this->request('get', '/data-integrations');
    }

    public function getAuthorizationUrl(
        string $slug,
        string $userId,
        ?string $returnTo = null,
        ?string $organizationId = null,
    ): string {
        $body = [
            'user_id' => $userId,
            'return_to' => $returnTo ?? (string) config('workos.routes.home', '/'),
        ];

        if ($organizationId !== null) {
            $body['organization_id'] = $organizationId;
        }

        $result = $this->request('post', "/data-integrations/{$slug}/authorize", $body);

        return (string) ($result['url'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getConnectedAccount(
        string $userId,
        string $slug,
        ?string $organizationId = null,
    ): array {
        $params = [];
        if ($organizationId !== null) {
            $params['organization_id'] = $organizationId;
        }

        return $this->request('get', "/user_management/users/{$userId}/connected_accounts/{$slug}", $params);
    }

    public function deleteConnectedAccount(
        string $userId,
        string $slug,
        ?string $organizationId = null,
    ): void {
        $params = [];
        if ($organizationId !== null) {
            $params['organization_id'] = $organizationId;
        }

        $this->request('delete', "/user_management/users/{$userId}/connected_accounts/{$slug}", $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessToken(
        string $userId,
        string $slug,
        ?string $organizationId = null,
    ): array {
        $params = [];
        if ($organizationId !== null) {
            $params['organization_id'] = $organizationId;
        }

        return $this->request('get', "/user_management/users/{$userId}/connected_accounts/{$slug}/access_token", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $url = rtrim((string) config('workos.widgets.base_url', 'https://api.workos.com'), '/').$path;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('workos.api_key'),
        ])->$method($url, $data);

        if (! $response->successful()) {
            throw new \RuntimeException("WorkOS Pipes API error: {$response->status()} {$response->body()}");
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }
}
