<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MissingPermissionException extends HttpException
{
    /** @var array<string> */
    public readonly array $permissions;

    /**
     * @param  array<string>  $permissions
     */
    public function __construct(array $permissions, ?string $message = null)
    {
        $this->permissions = $permissions;
        $permissionList = implode(', ', $permissions);

        parent::__construct(
            403,
            $message ?? "Missing required permission. Required: {$permissionList}"
        );
    }
}
