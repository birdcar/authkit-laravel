<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * One place that knows how the sealed session cookie is shaped. Three call sites
 * clear it (logout with and without a live session, hard-expiry in the refresh
 * middleware) and two issue it (callback, refresh), and they must agree on every
 * attribute or the browser treats them as different cookies.
 *
 * Path and domain matter most: a browser only replaces a cookie when name, path,
 * and domain all match. Issuing at `/` with no domain while clearing at the app's
 * configured `session.domain` would leave the sealed cookie in place after logout,
 * and the guard would keep accepting it for the rest of the access token's life.
 */
final class SessionCookie
{
    public static function name(): string
    {
        return (string) config('authkit.session.cookie', 'authkit_session');
    }

    public static function issue(string $sealed): Cookie
    {
        return self::make($sealed, 0);
    }

    public static function forget(): Cookie
    {
        return self::make('', time() - 31536000);
    }

    private static function make(string $value, int $expire): Cookie
    {
        return new Cookie(
            name: self::name(),
            value: $value,
            // Session cookie; the WorkOS-side refresh token lifetime is what
            // actually governs how long an issued cookie stays usable.
            expire: $expire,
            path: self::path(),
            domain: self::domain(),
            secure: (bool) config('session.secure', true),
            httpOnly: true,
            sameSite: self::sameSite(),
        );
    }

    private static function path(): string
    {
        $path = config('session.path', '/');

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function domain(): ?string
    {
        $domain = config('session.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * @return 'lax'|'none'|'strict'
     */
    private static function sameSite(): string
    {
        return match (config('authkit.session.same_site', 'lax')) {
            'strict' => 'strict',
            'none' => 'none',
            default => 'lax',
        };
    }
}
