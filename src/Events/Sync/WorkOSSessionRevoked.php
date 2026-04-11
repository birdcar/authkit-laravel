<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSSessionRevoked
{
    use HasEventData;

    public function sessionId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function userId(): string
    {
        /** @var string */
        return $this->data['user_id'];
    }
}
