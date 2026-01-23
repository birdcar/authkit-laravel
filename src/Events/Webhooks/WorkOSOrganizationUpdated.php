<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOSOrganizationUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

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
