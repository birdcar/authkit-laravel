<?php

declare(strict_types=1);

namespace Authkit\Authkit\Support;

use RuntimeException;

/**
 * Validated accessors for the credentials the auth flow cannot work without.
 *
 * Read raw, an empty `cookie_password` surfaces as an opaque sodium failure deep
 * inside SessionManager::unsealData() and an empty `client_id` as a silent full
 * lockout — neither pointing at the config key actually missing. Every path that
 * needs one of these (guard binding, callback, logout) goes through here so the
 * failure names the key instead.
 */
final class AuthkitConfig
{
    public static function cookiePassword(): string
    {
        return self::require('authkit.cookie_password');
    }

    public static function clientId(): string
    {
        return self::require('authkit.client_id');
    }

    public static function baseUrl(): string
    {
        return (string) config('authkit.base_url', 'https://api.workos.com');
    }

    public static function redirectUri(): string
    {
        return self::require('authkit.redirect_uri');
    }

    public static function require(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The [{$key}] config value is required by AuthKit but is empty. Set it before authenticating.");
        }

        return $value;
    }
}
