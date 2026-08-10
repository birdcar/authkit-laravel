<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes;

use WorkOS\Resource\DataIntegrationAuthMethods;

/**
 * Package-owned twin of the SDK's connection auth-method enum — the second
 * of the two sealing enums keeping WorkOS types out of the public Pipes
 * boundary.
 */
enum AuthMethod: string
{
    case OAuth = 'oauth';
    case ApiKey = 'api_key';
    case ClientCredentials = 'client_credentials';

    public static function fromSdk(DataIntegrationAuthMethods $method): self
    {
        return self::from($method->value);
    }
}
