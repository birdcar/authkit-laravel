<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use WorkOS\AuthKit\Events\Webhooks\Concerns\HasWebhookData;

class WorkOSMembershipCreated
{
    use HasWebhookData;

    public function membershipId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function userId(): string
    {
        /** @var string */
        return $this->data['user_id'];
    }

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['organization_id'];
    }

    public function role(): ?string
    {
        /** @var string|null */
        return $this->data['role']['slug'] ?? null;
    }
}
