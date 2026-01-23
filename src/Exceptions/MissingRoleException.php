<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MissingRoleException extends HttpException
{
    /** @var array<string> */
    public readonly array $roles;

    /**
     * @param  array<string>  $roles
     */
    public function __construct(array $roles, ?string $message = null)
    {
        $this->roles = $roles;
        $roleList = implode(', ', $roles);

        parent::__construct(
            403,
            $message ?? "Missing required role. Required: {$roleList}"
        );
    }
}
