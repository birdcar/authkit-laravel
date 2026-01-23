<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOSUserUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

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
