<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use WorkOS\AuthKit\Events\Webhooks\Concerns\HasWebhookData;

class WorkOSOrganizationUpdated
{
    use HasWebhookData;

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function name(): string
    {
        /** @var string */
        return $this->data['name'];
    }
}
