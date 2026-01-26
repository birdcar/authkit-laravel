<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks\Concerns;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

trait HasWebhookData
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

    /**
     * Get a value from the webhook data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
