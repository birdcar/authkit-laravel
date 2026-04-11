<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSMembershipDeleted
{
    use HasEventData;

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
}
