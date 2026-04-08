<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

trait WithWidgetApi
{
    use WithWidgetToken;

    private ?Client $widgetClient = null;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function widgetGet(string $path, array $query = []): array
    {
        return $this->widgetRequest('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function widgetPost(string $path, array $data = [], array $headers = []): array
    {
        return $this->widgetRequest('POST', $path, ['json' => $data, 'headers' => $headers]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function widgetDelete(string $path, array $headers = []): array
    {
        return $this->widgetRequest('DELETE', $path, ['headers' => $headers]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function widgetRequest(string $method, string $path, array $options = []): array
    {
        $client = $this->getWidgetClient();
        $token = $this->getWidgetToken($this->widgetScope());

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ]);

        try {
            $response = $client->request($method, "/_widgets{$path}", $options);

            /** @var array<string, mixed> */
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (ClientException $e) {
            /** @var array<string, mixed> $body */
            $body = json_decode($e->getResponse()->getBody()->getContents(), true) ?: [];

            if ($e->getResponse()->getStatusCode() === 401) {
                $this->clearWidgetToken();
            }

            /** @phpstan-ignore method.notFound */
            $this->addError('widget', $body['message'] ?? 'Widget API error');

            return [];
        }
    }

    private function getWidgetClient(): Client
    {
        return $this->widgetClient ??= new Client([
            'base_uri' => config('workos.widgets.base_url', 'https://api.workos.com'),
        ]);
    }

    abstract protected function widgetScope(): string;
}
