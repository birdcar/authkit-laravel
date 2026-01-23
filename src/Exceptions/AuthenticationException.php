<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Exceptions;

class AuthenticationException extends WorkOSException
{
    public static function sessionExpired(): self
    {
        return new self('WorkOS session has expired. Please log in again.');
    }

    public static function invalidCallback(): self
    {
        return new self('Invalid authentication callback. Missing required parameters.');
    }

    public static function refreshFailed(): self
    {
        return new self('Failed to refresh authentication session.');
    }

    public static function userNotFound(string $workosId): self
    {
        return new self("No user found with WorkOS ID: {$workosId}");
    }
}
