<?php

declare(strict_types=1);

use WorkOS\AuthKit\WorkOS;

if (! function_exists('workos')) {
    /**
     * Get the WorkOS service instance.
     */
    function workos(): WorkOS
    {
        return app('workos');
    }
}
