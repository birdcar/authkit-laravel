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
        // Honors the emulate override exactly like WorkosClientManager::fromConfig
        // — the guard's JWKS verification and the logout URL must talk to the
        // same host the client manager does, or an emulate-backed login mints
        // sessions the guard then rejects against production WorkOS (found by
        // the Phase 13 acceptance suite; Phase 1's emulate promise is that the
        // whole package follows the override, not just the SDK client).
        if ((bool) config('authkit.emulate.enabled', false)) {
            return (string) config('authkit.emulate.base_url', 'http://localhost:4100');
        }

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
