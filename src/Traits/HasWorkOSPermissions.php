<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Traits;

use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions as BaseHasWorkOSPermissions;

/**
 * @deprecated Use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions instead
 */
trait HasWorkOSPermissions
{
    use BaseHasWorkOSPermissions;
}
