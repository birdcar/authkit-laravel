<?php

declare(strict_types=1);

namespace Authkit\Authkit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Authkit\Authkit\Authkit
 */
class Authkit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Authkit\Authkit\Authkit::class;
    }
}
