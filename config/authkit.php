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

    // SessionManager does not verify iss/aud (decodeAccessToken TODO). These
    // MUST be replaced with values confirmed by docs/token-audit.md before
    // Phase 2 implements guard-level enforcement — see docs/token-audit-findings.md.
    'jwt' => [
        'issuer' => env('WORKOS_JWT_ISSUER'),
        'audience' => env('WORKOS_JWT_AUDIENCE'),
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
