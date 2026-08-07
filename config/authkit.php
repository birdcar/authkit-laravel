<?php

declare(strict_types=1);

return [

    'api_key' => env('WORKOS_API_KEY', ''),
    'client_id' => env('WORKOS_CLIENT_ID', ''),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', ''),
    'cookie_password' => env('WORKOS_COOKIE_PASSWORD', ''),
    'base_url' => env('WORKOS_BASE_URL', 'https://api.workos.com'),
    'timeout' => (int) env('WORKOS_TIMEOUT', 60),
    'max_retries' => (int) env('WORKOS_MAX_RETRIES', 3),

    // SessionManager does not verify iss/aud (decodeAccessToken TODO), so the
    // guard layers those checks itself via JwtClaimsValidator.
    //
    // 'audience' falls back to authkit.client_id when null — AuthKit tokens carry
    // no `aud` claim, and `client_id` is what stops a token minted for a different
    // application in the same WorkOS environment from being accepted here.
    //
    // 'issuer' is left null on purpose: docs/token-audit-findings.md is still TBD,
    // and enforcing a guessed issuer would silently lock out every environment
    // using a custom AuthKit auth domain. While null, issuer validation is skipped;
    // setting WORKOS_JWT_ISSUER turns it on with no code change.
    'jwt' => [
        'issuer' => env('WORKOS_JWT_ISSUER'),
        'audience' => env('WORKOS_JWT_AUDIENCE'),
    ],

    'session' => [
        'cookie' => env('WORKOS_SESSION_COOKIE', 'authkit_session'),
        'same_site' => env('WORKOS_SESSION_SAME_SITE', 'lax'),
        'refresh_before_seconds' => (int) env('WORKOS_SESSION_REFRESH_BEFORE_SECONDS', 60),
        'lock_wait_seconds' => (int) env('WORKOS_SESSION_LOCK_WAIT_SECONDS', 5),
        'lock_ttl_seconds' => (int) env('WORKOS_SESSION_LOCK_TTL_SECONDS', 10),
        'max_cookie_bytes' => (int) env('WORKOS_SESSION_MAX_COOKIE_BYTES', 3800),
        // Much longer than the SDK's own 300s freshness cache: that one answers
        // "how often do we re-check", this one answers "how long will we serve
        // stale keys during an outage before giving up".
        'jwks_grace_ttl_seconds' => (int) env('WORKOS_SESSION_JWKS_GRACE_TTL_SECONDS', 86400),
    ],

    'routes' => [
        'enabled' => (bool) env('AUTHKIT_ROUTES_ENABLED', true),
        'prefix' => env('AUTHKIT_ROUTES_PREFIX', 'authkit'),
        'middleware' => ['web'],
        'paths' => [
            'login' => 'login',
            'logout' => 'logout',
            'callback' => 'callback',
        ],
    ],

    'user' => [
        // Written as a string literal rather than \App\Models\User::class: the host
        // app's User model does not exist inside this package, and PHPStan analyses
        // config/ at level 7, where a class-constant fetch on an unknown class is an
        // error even though PHP itself never autoloads it.
        'model' => env('AUTHKIT_USER_MODEL', 'App\Models\User'),
        'external_id_column' => 'workos_id',
    ],

    'organization' => [
        'model' => env('AUTHKIT_ORGANIZATION_MODEL'),
        'external_id_column' => 'workos_id',
    ],

    'events' => [
        'enabled' => (bool) env('AUTHKIT_EVENTS_ENABLED', true),
        'poll_interval' => (int) env('AUTHKIT_EVENTS_POLL_INTERVAL', 5),
        'cursor_cache_store' => env('AUTHKIT_EVENTS_CURSOR_STORE'),
    ],

    'feature_flags' => [
        'cache_ttl' => (int) env('AUTHKIT_FEATURE_FLAGS_CACHE_TTL', 30),
    ],

    'vault' => [
        'key_context' => env('AUTHKIT_VAULT_KEY_CONTEXT'),
    ],

    'mcp' => [
        'resource_indicator' => env('AUTHKIT_MCP_RESOURCE_INDICATOR'),
    ],

    'emulate' => [
        'enabled' => (bool) env('AUTHKIT_EMULATE_ENABLED', false),
        'base_url' => env('AUTHKIT_EMULATE_BASE_URL', 'http://localhost:4100'),
        'api_key' => env('AUTHKIT_EMULATE_API_KEY', 'sk_test_default'),
    ],

];
