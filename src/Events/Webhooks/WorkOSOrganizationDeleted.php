<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use WorkOS\AuthKit\Events\Webhooks\Concerns\HasWebhookData;

class WorkOSOrganizationDeleted
{
    use HasWebhookData;

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['id'];
    }
}
