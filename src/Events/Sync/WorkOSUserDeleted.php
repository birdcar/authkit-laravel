<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSUserDeleted
{
    use HasEventData;

    public function userId(): string
    {
        /** @var string */
        return $this->data['id'];
    }
}
