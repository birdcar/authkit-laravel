<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvitationRevoked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $invitationId,
    ) {}
}
