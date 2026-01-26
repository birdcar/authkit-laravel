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
    | When 'cookie_session' is true (default), WorkOS's wos-session cookie is
    | used as the single source of truth for authentication. This is the
    | recommended approach as it avoids session synchronization issues.
    |
    | When false, session data is stored in Laravel's session instead. This
    | gives you more control but requires managing token refresh locally.
    |
    | The cookie is decrypted using your APP_KEY - no additional config needed.
    |
    | Note: Session DURATION is controlled by your WorkOS Dashboard settings.
    |
    */

    'session' => [
        'cookie_session' => env('WORKOS_COOKIE_SESSION', true),
        'cookie_name' => env('WORKOS_COOKIE_NAME', 'wos-session'),
        'refresh_buffer_minutes' => env('WORKOS_SESSION_REFRESH_BUFFER', 5),
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
        'sync_enabled' => env('WORKOS_WEBHOOK_SYNC_ENABLED', true),
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
