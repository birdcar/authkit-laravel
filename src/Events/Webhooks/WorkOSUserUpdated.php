<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use WorkOS\AuthKit\Events\Webhooks\Concerns\HasWebhookData;

class WorkOSUserUpdated
{
    use HasWebhookData;

    public function userId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function email(): string
    {
        /** @var string */
        return $this->data['email'];
    }

    public function firstName(): ?string
    {
        /** @var string|null */
        return $this->data['first_name'] ?? null;
    }

    public function lastName(): ?string
    {
        /** @var string|null */
        return $this->data['last_name'] ?? null;
    }
}
