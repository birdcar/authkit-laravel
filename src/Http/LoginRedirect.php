<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/**
 * Sending a browser back to the start of the login flow, from the two places that
 * need to (an expired session, a tampered callback state).
 *
 * `authkit.routes.enabled=false` is a supported configuration, and the named route
 * does not exist then — routing to it unguarded turns a handled condition into a
 * RouteNotFoundException.
 */
final class LoginRedirect
{
    /**
     * @param  array<string, string>  $errors
     */
    public static function make(array $errors = []): RedirectResponse
    {
        $redirect = Route::has('authkit.login')
            ? redirect()->route('authkit.login')
            : redirect()->to('/');

        return $errors === [] ? $redirect : $redirect->withErrors($errors);
    }
}
