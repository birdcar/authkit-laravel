<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\Resource\AuthenticateResponse;

final readonly class Login
{
    public function __construct(
        public Authenticatable $user,
        public AuthenticateResponse $response,
    ) {}
}
