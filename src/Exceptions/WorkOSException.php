<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Exceptions;

use Exception;

class WorkOSException extends Exception
{
    public static function invalidApiKey(): self
    {
        return new self('Invalid WorkOS API key. Please check your WORKOS_API_KEY environment variable.');
    }

    public static function invalidClientId(): self
    {
        return new self('Invalid WorkOS Client ID. Please check your WORKOS_CLIENT_ID environment variable.');
    }

    public static function missingConfiguration(string $key): self
    {
        return new self("Missing required WorkOS configuration: {$key}");
    }
}
