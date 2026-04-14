<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSOrganizationDomainVerified
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
}
