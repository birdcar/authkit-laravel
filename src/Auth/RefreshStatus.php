<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

enum RefreshStatus
{
    case Refreshed;
    case ProceedWithExisting;
    case HardExpired;
}
