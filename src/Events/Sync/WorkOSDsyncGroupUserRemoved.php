<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSDsyncGroupUserRemoved
{
    use HasEventData;

    public function directoryId(): string
    {
        /** @var string */
        return $this->data['directory_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function user(): array
    {
        /** @var array<string, mixed> */
        return $this->data['user'];
    }

    /**
     * @return array<string, mixed>
     */
    public function group(): array
    {
        /** @var array<string, mixed> */
        return $this->data['group'];
    }

    public function directoryUserId(): string
    {
        /** @var string */
        return $this->data['user']['id'];
    }

    public function directoryGroupId(): string
    {
        /** @var string */
        return $this->data['group']['id'];
    }

    public function userEmail(): string
    {
        /** @var string */
        return $this->data['user']['email'];
    }

    public function groupName(): string
    {
        /** @var string */
        return $this->data['group']['name'];
    }
}
