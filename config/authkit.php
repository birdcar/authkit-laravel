<?php

declare(strict_types=1);
use Authkit\Authkit\Organizations\MembershipProjectionResolver;
use Authkit\Authkit\Vault\DefaultVaultKeyContextResolver;

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
            'switch_organization' => 'organizations/{organizationId}/switch',
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

        // 'queue' keeps a slow or failing WorkOS call from ever blocking the
        // request that created the local org row; 'sync' is a deliberate
        // opt-in for CLI seeders and tests that need workos_id immediately.
        'sync_mode' => env('AUTHKIT_ORGANIZATION_SYNC_MODE', 'queue'),
        'delete_remote_on_delete' => (bool) env('AUTHKIT_ORGANIZATION_DELETE_REMOTE_ON_DELETE', true),

        'retry' => [
            'tries' => (int) env('AUTHKIT_ORGANIZATION_SYNC_TRIES', 5),
            'backoff' => [10, 30, 60, 300, 900], // seconds
        ],

        'middleware' => [
            'on_missing' => env('AUTHKIT_ORGANIZATION_MIDDLEWARE_ON_MISSING', 'abort'), // 'abort'|'redirect'
            'redirect_route' => env('AUTHKIT_ORGANIZATION_MIDDLEWARE_REDIRECT_ROUTE'),
        ],
    ],

    'api_keys' => [
        // Where the authkit-key guard reads the key from. 'bearer' reads
        // `Authorization: Bearer <value>`; any other string is treated as a
        // literal header name read via $request->header(), e.g. set this to
        // 'X-Api-Key' to accept `X-Api-Key: <value>` instead.
        'header' => env('AUTHKIT_API_KEYS_HEADER', 'bearer'),
    ],

    'authorization' => [
        // Swappable seam: resolves a WorkOS organization_membership_id for a
        // (user, organization) pair from the local workos_memberships
        // projection — never a live API call per check.
        'membership_resolver' => MembershipProjectionResolver::class,
    ],

    'events' => [
        // Seconds the authkit:work poller sleeps between empty polls.
        'poll_interval' => (int) env('AUTHKIT_EVENTS_POLL_INTERVAL', 5),
        // Events fetched per batch — matches the API's documented 1-100 cap.
        'batch_limit' => (int) env('AUTHKIT_EVENTS_BATCH_LIMIT', 100),
        // First-run rangeStart lookback (also the stale-cursor fallback floor).
        'backfill_minutes' => (int) env('AUTHKIT_EVENTS_BACKFILL_MINUTES', 5),
        // Seconds; must exceed one batch's worst-case dispatch time — the
        // poller renews the singleton lock at the top of every loop iteration.
        'lock_ttl' => (int) env('AUTHKIT_EVENTS_LOCK_TTL', 90),
    ],

    'webhooks' => [
        'secret' => env('WORKOS_WEBHOOK_SECRET'),
        // Seconds a signed timestamp stays valid — matches the SDK
        // WebhookVerification's own default.
        'tolerance' => (int) env('AUTHKIT_WEBHOOKS_TOLERANCE', 180),
    ],

    'feature_flags' => [
        'cache_ttl' => (int) env('AUTHKIT_FEATURE_FLAGS_CACHE_TTL', 30),
    ],

    'vault' => [
        // Derives the per-attribute encryption key context (the tenant-isolation
        // boundary for envelope encryption). Any class implementing
        // Authkit\Authkit\Vault\ResolvesVaultKeyContext.
        'key_context_resolver' => DefaultVaultKeyContextResolver::class,

        'filesystem' => [
            // Hard ceiling for a single vault-disk write. There is no streaming
            // cipher in the SDK — encryption materializes the whole plaintext in
            // memory at a 3-4x peak, so this guard is what stands between a large
            // upload and an OOM. Overridable per disk via 'max_encrypt_bytes'.
            'max_encrypt_bytes' => (int) env('AUTHKIT_VAULT_MAX_ENCRYPT_BYTES', 10 * 1024 * 1024),
        ],
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
