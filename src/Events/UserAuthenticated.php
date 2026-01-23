<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use WorkOS\AuthKit\Auth\WorkOSSession;

class UserAuthenticated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly WorkOSSession $session,
    ) {}
}
