<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use WorkOS\Resource\Invitation;

class InvitationSent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $email,
        public readonly Invitation $invitation,
    ) {}
}
