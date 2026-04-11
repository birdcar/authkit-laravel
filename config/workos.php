<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WorkOS API Credentials
    |--------------------------------------------------------------------------
    |
    | Your WorkOS API credentials. You can find these in your WorkOS Dashboard
    | under API Keys. The redirect URI should be the full URL to your callback
    | endpoint.
    |
    */

    'api_key' => env('WORKOS_API_KEY'),
    'client_id' => env('WORKOS_CLIENT_ID'),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', env('APP_URL').'/auth/callback'),
    'webhook_secret' => env('WORKOS_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Auth Guard Configuration
    |--------------------------------------------------------------------------
    |
    | The name of the auth guard to use for WorkOS authentication. This should
    | match the guard configured in your auth.php config file.
    |
    */

    'guard' => 'workos',

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Sessions are managed via WorkOS's sealed wos-session cookie, which is
    | the single source of truth for authentication state. The cookie is
    | encrypted using your APP_KEY — no additional configuration needed.
    |
    | Session duration is controlled by your WorkOS Dashboard settings.
    |
    */

    'session' => [
        'cookie_name' => env('WORKOS_COOKIE_NAME', 'wos-session'),
        'access_token_lifetime' => env('WORKOS_ACCESS_TOKEN_LIFETIME', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific WorkOS features. These can be toggled based
    | on your subscription tier or application requirements.
    |
    */

    'features' => [
        'audit_logs' => env('WORKOS_FEATURE_AUDIT_LOGS', false),
        'organizations' => env('WORKOS_FEATURE_ORGANIZATIONS', true),
        'impersonation' => env('WORKOS_FEATURE_IMPERSONATION', true),
        'webhooks' => env('WORKOS_FEATURE_WEBHOOKS', true),
        'widgets' => env('WORKOS_FEATURE_WIDGETS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the WorkOS Widgets API base URL. Override for staging or
    | local development environments.
    |
    */

    'widgets' => [
        'base_url' => env('WORKOS_BASE_API_URL', 'https://api.workos.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the built-in authentication routes. Set enabled to false to
    | register your own routes manually.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'organizations_prefix' => 'organizations',
        'middleware' => ['web'],
        'home' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the webhook endpoint for receiving events from WorkOS.
    |
    */

    'webhooks' => [
        'enabled' => true,
        'prefix' => 'webhooks/workos',
    ],

    /*
    |--------------------------------------------------------------------------
    | Events Configuration
    |--------------------------------------------------------------------------
    |
    | Configure event sync routing and the Events API polling worker.
    | Each event category can be synced via 'webhooks', 'events_api', or
    | 'both'. Per-event-type overrides take precedence over category defaults.
    |
    */

    'events' => [
        'routing' => [
            'categories' => [
                'user' => env('WORKOS_SYNC_USER', 'webhooks'),
                'organization' => env('WORKOS_SYNC_ORGANIZATION', 'webhooks'),
                'organization_membership' => env('WORKOS_SYNC_MEMBERSHIP', 'webhooks'),
                'dsync' => env('WORKOS_SYNC_DSYNC', 'events_api'),
                'session' => env('WORKOS_SYNC_SESSION', 'webhooks'),
                'authentication' => env('WORKOS_SYNC_AUTH', 'webhooks'),
            ],

            'overrides' => [],
        ],

        'poll_interval' => env('WORKOS_EVENTS_POLL_INTERVAL', 5),
        'lookback_days' => env('WORKOS_EVENTS_LOOKBACK_DAYS', 7),
        'limit' => env('WORKOS_EVENTS_LIMIT', 100),
        'cache_store' => env('WORKOS_EVENTS_CACHE_STORE'),
        'cache_key' => 'workos.events.cursor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Listeners
    |--------------------------------------------------------------------------
    |
    | Control which listeners handle WorkOS sync events. By default, the
    | package registers its own listeners for user, organization, and
    | membership events. Override any event's listener by mapping the event
    | class to your own listener class. Set to null to disable a listener.
    | Omit an event to keep the package default.
    |
    */

    'sync' => [
        'listeners' => [
            // WorkOS\AuthKit\Events\Sync\WorkOSUserCreated::class => App\Listeners\MyUserSync::class,
            // WorkOS\AuthKit\Events\Sync\WorkOSUserDeleted::class => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The fully qualified class name of your User model. This is used by the
    | user provider to look up users by their WorkOS ID.
    |
    */

    'user_model' => env('WORKOS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Organization Model
    |--------------------------------------------------------------------------
    |
    | The fully qualified class name of your Organization model. This is used
    | for organization-related functionality.
    |
    */

    'organization_model' => env('WORKOS_ORGANIZATION_MODEL', 'App\\Models\\Organization'),
];
