<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOSSessionCreated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

    public function sessionId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function userId(): string
    {
        /** @var string */
        return $this->data['user_id'];
    }

    public function ipAddress(): ?string
    {
        /** @var string|null */
        return $this->data['ip_address'] ?? null;
    }

    public function userAgent(): ?string
    {
        /** @var string|null */
        return $this->data['user_agent'] ?? null;
    }
}
