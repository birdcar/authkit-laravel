<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use WorkOS\AuthKit\Events\Webhooks\Concerns\HasWebhookData;

class WorkOSUserDeleted
{
    use HasWebhookData;

    public function userId(): string
    {
        /** @var string */
        return $this->data['id'];
    }
}
