<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSDsyncUserDeleted
{
    use HasEventData;

    public function directoryUserId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function directoryId(): string
    {
        /** @var string */
        return $this->data['directory_id'];
    }

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['organization_id'];
    }

    public function email(): string
    {
        /** @var string */
        return $this->data['email'];
    }

    public function state(): string
    {
        /** @var string */
        return $this->data['state'];
    }
}
