<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSSessionCreated
{
    use HasEventData;

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
