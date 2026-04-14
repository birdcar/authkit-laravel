<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSDsyncActivated
{
    use HasEventData;

    public function directoryId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function name(): string
    {
        /** @var string */
        return $this->data['name'];
    }

    public function organizationId(): string
    {
        /** @var string */
        return $this->data['organization_id'];
    }

    public function type(): string
    {
        /** @var string */
        return $this->data['type'];
    }

    public function state(): string
    {
        /** @var string */
        return $this->data['state'];
    }
}
