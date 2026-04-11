<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSOrganizationUpdated
{
    use HasEventData;

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
