<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use RuntimeException;

/**
 * A WorkOS user whose email is not verified signed in with an address that already
 * belongs to a local account.
 *
 * Linking would be account takeover if the environment allows sign-up without
 * verification; creating a second row is impossible on the standard unique-email
 * users table. Refusing loudly is the only honest outcome.
 */
final class UnverifiedEmailCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $workosUserId,
        public readonly string $email,
    ) {
        parent::__construct(sprintf(
            'WorkOS user [%s] has an unverified email [%s] that already belongs to a local account. '
            .'Refusing to link the two — verify the address in WorkOS before signing in.',
            $workosUserId,
            $email,
        ));
    }
}
