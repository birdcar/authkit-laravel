<?php

declare(strict_types=1);

namespace Authkit\Authkit\Contracts;

use WorkOS\WorkOS;

interface WorkosClientManager
{
    public function client(): WorkOS;
}
