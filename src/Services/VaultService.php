<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use Illuminate\Support\Facades\Http;

class VaultService
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function store(string $name, string $value, array $context = []): array
    {
        $body = ['name' => $name, 'value' => $value];
        if (! empty($context)) {
            $body['context'] = $context;
        }

        return $this->request('post', '/vault/objects', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->request('get', "/vault/objects/{$id}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getByName(string $name): array
    {
        return $this->request('get', "/vault/objects/by-name/{$name}");
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $id, string $value): array
    {
        return $this->request('patch', "/vault/objects/{$id}", ['value' => $value]);
    }

    public function delete(string $id): void
    {
        $this->request('delete', "/vault/objects/{$id}");
    }

    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 10, ?string $after = null): array
    {
        $params = ['limit' => $limit];
        if ($after !== null) {
            $params['after'] = $after;
        }

        return $this->request('get', '/vault/objects', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function versions(string $id): array
    {
        return $this->request('get', "/vault/objects/{$id}/versions");
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function encrypt(string $plaintext, array $context = []): array
    {
        $body = ['plaintext' => $plaintext];
        if (! empty($context)) {
            $body['context'] = $context;
        }

        return $this->request('post', '/vault/keys/encrypt', $body);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function decrypt(string $ciphertext, array $context = []): array
    {
        $body = ['ciphertext' => $ciphertext];
        if (! empty($context)) {
            $body['context'] = $context;
        }

        return $this->request('post', '/vault/keys/decrypt', $body);
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
            throw new \RuntimeException("WorkOS Vault API error: {$response->status()} {$response->body()}");
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }
}
