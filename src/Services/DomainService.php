<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use Illuminate\Support\Facades\Http;

class DomainService
{
    /**
     * @return array<string, mixed>
     */
    public function create(string $organizationId, string $domain): array
    {
        return $this->request('post', '/organization_domains', [
            'organization_id' => $organizationId,
            'domain' => $domain,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->request('get', "/organization_domains/{$id}");
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $id): array
    {
        return $this->request('post', "/organization_domains/{$id}/verify");
    }

    public function delete(string $id): void
    {
        $this->request('delete', "/organization_domains/{$id}");
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
            throw new \RuntimeException("WorkOS Domain API error: {$response->status()} {$response->body()}");
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }
}
