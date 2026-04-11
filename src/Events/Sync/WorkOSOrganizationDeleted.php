<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSOrganizationDeleted
{
    use HasEventData;

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['id'];
    }
}
