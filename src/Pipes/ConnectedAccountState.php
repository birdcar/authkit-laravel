<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes;

use WorkOS\Resource\ConnectedAccountState as SdkConnectedAccountState;

/**
 * Package-owned twin of the SDK's connected-account state enum, so no
 * WorkOS type ever appears in a public Pipes signature or DTO property
 * (the same sealing job ResourceTarget does for Authorization).
 */
enum ConnectedAccountState: string
{
    case Connected = 'connected';
    case NeedsReauthorization = 'needs_reauthorization';
    case Disconnected = 'disconnected';

    public static function fromSdk(SdkConnectedAccountState $state): self
    {
        return self::from($state->value);
    }
}
