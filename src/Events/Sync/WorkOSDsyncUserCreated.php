<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Sync;

use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;

class WorkOSDsyncUserCreated
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

    public function idpId(): string
    {
        /** @var string */
        return $this->data['idp_id'];
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

    public function state(): string
    {
        /** @var string */
        return $this->data['state'];
    }

    /**
     * @return array<string, mixed>
     */
    public function customAttributes(): array
    {
        /** @var array<string, mixed> */
        return $this->data['custom_attributes'] ?? [];
    }
}
