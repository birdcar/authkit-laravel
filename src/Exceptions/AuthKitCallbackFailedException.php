<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An AuthKit callback that cannot be exchanged for a session: WorkOS answered
 * with an OAuth error (`?error=access_denied`, `?error=no_users`, …) or the
 * callback URL was hit without a code/state pair (bookmarked, replayed, or
 * hand-typed).
 */
final class AuthKitCallbackFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $errorDescription = null,
    ) {
        parent::__construct(sprintf('The AuthKit callback returned an error: %s', $errorCode));
    }

    /**
     * Deliberately NOT LoginRedirect: bouncing an error callback back into the
     * login flow immediately re-runs the authorize redirect, which reproduces
     * the same error and loops the browser forever (observed live against
     * workos/emulate's `no_users`; a user's `access_denied` has the same
     * shape). Home is the one guest-safe place that always exists, and the
     * flashed `authkit` error lets the app surface a friendly retry message.
     */
    public function render(Request $request): RedirectResponse
    {
        return redirect()->to('/')->withErrors([
            'authkit' => $this->errorCode === 'access_denied'
                ? 'Sign-in was cancelled. You can try again whenever you are ready.'
                : sprintf('Sign-in failed (%s). Please try again.', $this->errorCode),
        ]);
    }
}
