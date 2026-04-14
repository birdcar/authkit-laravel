<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSOrganizationDomainVerificationFailed
{
    use HasEventData;

    public function domainId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['organization_id'];
    }

    public function domain(): string
    {
        /** @var string */
        return $this->data['domain'];
    }

    public function state(): string
    {
        /** @var string */
        return $this->data['state'];
    }

    public function reason(): ?string
    {
        /** @var string|null */
        return $this->data['reason'] ?? null;
    }
}
