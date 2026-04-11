<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSUserCreated
{
    use HasEventData;

    public function userId(): string
    {
        /** @var string */
        return $this->data['id'];
    }

    public function email(): string
    {
        /** @var string */
        return $this->data['email'];
    }

    public function firstName(): ?string
    {
        /** @var string|null */
        return $this->data['first_name'] ?? null;
    }

    public function lastName(): ?string
    {
        /** @var string|null */
        return $this->data['last_name'] ?? null;
    }
}
