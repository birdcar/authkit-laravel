<?php

declare(strict_types=1);

namespace Authkit\Authkit\Support;

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Config\Repository;
use WorkOS\WorkOS;

final class WorkosClientManager implements WorkosClientManagerContract
{
    private ?WorkOS $client = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $clientId,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $maxRetries,
        private readonly ?HandlerStack $handler = null,
    ) {}

    public static function fromConfig(Repository $config, ?HandlerStack $handler = null): self
    {
        $emulate = (bool) $config->get('authkit.emulate.enabled', false);

        return new self(
            apiKey: (string) ($emulate
                ? $config->get('authkit.emulate.api_key', 'sk_test_default')
                : $config->get('authkit.api_key', '')),
            clientId: (string) $config->get('authkit.client_id', ''),
            baseUrl: (string) ($emulate
                ? $config->get('authkit.emulate.base_url', 'http://localhost:4100')
                : $config->get('authkit.base_url', 'https://api.workos.com')),
            timeout: (int) $config->get('authkit.timeout', 60),
            maxRetries: (int) $config->get('authkit.max_retries', 3),
            handler: $handler,
        );
    }

    public function client(): WorkOS
    {
        // apiKey/clientId are ALWAYS strings, never null — WorkOS::__construct()
        // falls back to the WORKOS_API_KEY environment variable (WorkOS.php:91-92)
        // only when the argument it receives is literally null. Passing '' instead
        // of null is what keeps the SDK's own environment fallback dead.
        return $this->client ??= new WorkOS(
            apiKey: $this->apiKey,
            clientId: $this->clientId,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            maxRetries: $this->maxRetries,
            handler: $this->handler,
        );
    }
}
