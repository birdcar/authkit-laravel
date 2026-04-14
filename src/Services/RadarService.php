<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use Illuminate\Support\Facades\Http;

class RadarService
{
    /**
     * Create a Radar attempt and get a fraud verdict.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createAttempt(array $attributes): array
    {
        return $this->request('post', '/radar/attempts', $attributes);
    }

    /**
     * Update an existing Radar attempt.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function updateAttempt(string $id, array $attributes): array
    {
        return $this->request('patch', "/radar/attempts/{$id}", $attributes);
    }

    /**
     * Add an entry to a Radar block/allow list.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addToList(array $attributes): array
    {
        return $this->request('post', '/radar/lists', $attributes);
    }

    /**
     * Remove an entry from a Radar block/allow list.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function removeFromList(array $attributes): void
    {
        $this->request('delete', '/radar/lists', $attributes);
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
            throw new \RuntimeException("WorkOS Radar API error: {$response->status()} {$response->body()}");
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }
}
