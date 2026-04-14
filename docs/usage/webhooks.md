# Webhooks

Receive and process real-time events from WorkOS to keep your application in sync.

## Overview

WorkOS sends webhooks when important events occur (user created, organization updated, membership changed, etc.). AuthKit automatically ingests and dispatches these as Laravel events, with built-in listeners for common sync operations.

**Enable the feature** in `config/workos.php`:

```php
'webhooks' => [
    'enabled' => true,        // default
    'prefix' => 'webhooks/workos',
],
```

Events are routed based on the `workos.events.routing` configuration. By default, webhooks handle user, organization, membership, session, and authentication events. Configure routing to decide which events come from webhooks vs the Events API polling worker.

## Webhook Endpoint

The package registers a webhook endpoint at:

```
POST /webhooks/workos
```

This endpoint:
1. Validates the webhook signature using `WORKOS_WEBHOOK_SECRET`
2. Parses the payload
3. Dispatches Laravel events
4. Auto-syncs data (if enabled)
5. Returns 200 OK

## Setting Up Webhooks in WorkOS

In your WorkOS Dashboard:

1. Go to API Configuration → Webhooks
2. Set the webhook URL to your application:
   ```
   https://yourdomain.com/webhooks/workos
   ```
3. Copy the webhook secret and add to `.env`:
   ```env
   WORKOS_WEBHOOK_SECRET=whsec_your_secret_here
   ```
4. Select events to receive (or select all)
5. Save and test

## Event Routing

By default, webhooks handle most events. However, you can configure which events come from webhooks vs the Events API polling worker using the `workos.events.routing` configuration:

```php
// config/workos.php
'events' => [
    'routing' => [
        'categories' => [
            'user' => 'webhooks',        // User events via webhooks
            'organization' => 'webhooks', // Org events via webhooks
            'dsync' => 'events_api',     // SCIM/LDAP via Events API
        ],
    ],
],
```

Webhook events are only dispatched if:
1. Webhooks are enabled (`config('workos.webhooks.enabled')`)
2. The webhook is received from WorkOS
3. The event category is routed to `'webhooks'` or `'both'`

For a comprehensive guide on event routing, see [Events API and Webhooks](events.md).

## Available Events

The package dispatches Laravel events for these WorkOS events:

### User Events

**WorkOSUserCreated**
Fired when a user is created in WorkOS.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;

Event::listen(WorkOSUserCreated::class, function ($event) {
    $data = $event->data; // WorkOS user data
    // $data['id'], $data['email'], $data['first_name'], etc.
});
```

**WorkOSUserUpdated**
Fired when a user is updated in WorkOS.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;

Event::listen(WorkOSUserUpdated::class, function ($event) {
    $data = $event->data;
});
```

**WorkOSUserDeleted**
Fired when a user is deleted in WorkOS.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSUserDeleted;

Event::listen(WorkOSUserDeleted::class, function ($event) {
    $workosUserId = $event->data['id'];
});
```

### Organization Events

**WorkOSOrganizationCreated**
Fired when an organization is created.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;

Event::listen(WorkOSOrganizationCreated::class, function ($event) {
    $data = $event->data; // $data['id'], $data['name'], etc.
});
```

**WorkOSOrganizationUpdated**
Fired when an organization is updated.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationUpdated;
```

**WorkOSOrganizationDeleted**
Fired when an organization is deleted.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDeleted;
```

### Membership Events

**WorkOSMembershipCreated**
Fired when a user joins an organization.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;

Event::listen(WorkOSMembershipCreated::class, function ($event) {
    $data = $event->data;
    // $data['id'], $data['user_id'], $data['organization_id'], $data['role']
});
```

**WorkOSMembershipUpdated**
Fired when a membership is updated (role changed, etc.).

```php
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipUpdated;
```

**WorkOSMembershipDeleted**
Fired when a user leaves an organization.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipDeleted;
```

### Session Events

**WorkOSSessionCreated**
Fired when a user authenticates successfully.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSSessionCreated;

Event::listen(WorkOSSessionCreated::class, function ($event) {
    $data = $event->data;
});
```

**WorkOSSessionRevoked**
Fired when a user's session is revoked.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSSessionRevoked;

Event::listen(WorkOSSessionRevoked::class, function ($event) {
    $data = $event->data;
});
```

### Organization Domain Events

**WorkOSOrganizationDomainCreated**
Fired when a domain is added to an organization.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainCreated;

Event::listen(WorkOSOrganizationDomainCreated::class, function ($event) {
    $data = $event->data; // $data['id'], $data['domain'], $data['organization_id'], etc.
});
```

**WorkOSOrganizationDomainUpdated**
Fired when an organization domain is updated.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainUpdated;
```

**WorkOSOrganizationDomainDeleted**
Fired when a domain is removed from an organization.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainDeleted;
```

**WorkOSOrganizationDomainVerified**
Fired when an organization domain is successfully verified.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerified;
```

**WorkOSOrganizationDomainVerificationFailed**
Fired when an organization domain verification attempt fails.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerificationFailed;
```

### Directory Sync (DSync) Events

**WorkOSDsyncActivated**
Fired when a directory sync connection is activated.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncActivated;

Event::listen(WorkOSDsyncActivated::class, function ($event) {
    $data = $event->data; // $data['id'], $data['organization_id'], $data['type'], etc.
});
```

**WorkOSDsyncDeleted**
Fired when a directory sync connection is deleted.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncDeleted;
```

**WorkOSDsyncUserCreated**
Fired when a user is provisioned via directory sync.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserCreated;

Event::listen(WorkOSDsyncUserCreated::class, function ($event) {
    $data = $event->data; // $data['id'], $data['emails'], $data['first_name'], etc.
});
```

**WorkOSDsyncUserUpdated**
Fired when a directory sync user's attributes are updated.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserUpdated;
```

**WorkOSDsyncUserDeleted**
Fired when a directory sync user is deprovisioned.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserDeleted;
```

**WorkOSDsyncGroupCreated**
Fired when a directory sync group is created.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupCreated;

Event::listen(WorkOSDsyncGroupCreated::class, function ($event) {
    $data = $event->data; // $data['id'], $data['name'], $data['organization_id'], etc.
});
```

**WorkOSDsyncGroupUpdated**
Fired when a directory sync group is updated.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUpdated;
```

**WorkOSDsyncGroupDeleted**
Fired when a directory sync group is deleted.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupDeleted;
```

**WorkOSDsyncGroupUserAdded**
Fired when a user is added to a directory sync group.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUserAdded;

Event::listen(WorkOSDsyncGroupUserAdded::class, function ($event) {
    $data = $event->data; // $data['user'], $data['group'], $data['organization_id']
});
```

**WorkOSDsyncGroupUserRemoved**
Fired when a user is removed from a directory sync group.

```php
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUserRemoved;
```

### Generic Webhook Event

All webhooks also fire a generic `WorkOSEventReceived` event:

```php
use WorkOS\AuthKit\Events\WorkOSEventReceived;

Event::listen(WorkOSEventReceived::class, function (WorkOSEventReceived $event) {
    $eventType = $event->type;      // 'user.created', 'organization.updated', etc.
    $eventData = $event->data;      // Raw event data
});
```

## Built-in Sync Listeners

When webhooks are configured to route events (via `workos.events.routing`), the package automatically syncs data using built-in listeners.

**SyncUserFromWorkOS**
- `user.created` → Creates or updates the User record
- `user.updated` → Updates the User record

Customize by adding a `findOrCreateByWorkOS` method to your User model:

```php
public static function findOrCreateByWorkOS(array $workosUser): self
{
    return self::updateOrCreate(
        ['workos_id' => $workosUser['id']],
        [
            'email' => $workosUser['email'],
            'name' => $workosUser['first_name'].' '.$workosUser['last_name'],
            'avatar' => $workosUser['profile_image_url'] ?? null,
        ]
    );
}
```

**SyncOrganizationFromWorkOS**
- `organization.created` → Creates the Organization record
- `organization.updated` → Updates the Organization record

Customization: Add a `findOrCreateByWorkOS` method to your Organization model:

```php
public static function findOrCreateByWorkOS(array $workosOrg): self
{
    return self::updateOrCreate(
        ['workos_id' => $workosOrg['id']],
        [
            'name' => $workosOrg['name'],
            'slug' => $workosOrg['slug'] ?? str($workosOrg['name'])->slug(),
        ]
    );
}
```

**SyncMembershipFromWorkOS**
- `organization_membership.created` → Links user to organization
- `organization_membership.updated` → Updates user's role in organization
- `organization_membership.deleted` → Removes user from organization

Requires the `HasOrganization` trait on both User and Organization models.

## Custom Event Listeners

Create custom listeners to extend webhook handling:

```bash
php artisan make:listener SyncUserAvatarFromWorkOS --event=WorkOSUserUpdated
```

Edit `app/Listeners/SyncUserAvatarFromWorkOS.php`:

```php
<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;

class SyncUserAvatarFromWorkOS implements ShouldQueue
{
    public function handle(WorkOSUserUpdated $event): void
    {
        $user = User::where('workos_id', $event->data['id'])->first();

        if ($user) {
            $user->update([
                'avatar_url' => $event->data['profile_image_url'] ?? null,
            ]);
        }
    }
}
```

Register in `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Event;
use App\Listeners\SyncUserAvatarFromWorkOS;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;

public function boot(): void
{
    Event::listen(WorkOSUserUpdated::class, SyncUserAvatarFromWorkOS::class);
}
```

## Handling Webhook Data

Webhook event objects provide a `data` property with the raw WorkOS event data:

```php
Event::listen(WorkOSMembershipCreated::class, function ($event) {
    $event->data = [
        'id' => 'membership_123abc',
        'user_id' => 'user_123abc',
        'organization_id' => 'org_123abc',
        'role' => 'admin',
        'created_at' => '2024-01-15T10:30:00Z',
    ];
});
```

Implement your own sync logic:

```php
Event::listen(WorkOSMembershipCreated::class, function ($event) {
    $user = User::where('workos_id', $event->data['user_id'])->first();
    $org = Organization::where('workos_id', $event->data['organization_id'])->first();

    if ($user && $org) {
        $user->organizations()->attach($org, [
            'role' => $event->data['role'],
        ]);

        // Send welcome email
        Mail::send(new MembershipWelcome($user, $org));
    }
});
```

## Testing Webhooks Locally

For development, you can test webhook delivery in two ways:

**Using the Events API polling worker** (recommended for testing event handlers):

```bash
php artisan workos:events-listen --once
```

This fetches events from the Events API, useful for testing your event listeners.

**Configuring your application for local testing:**

Use a service like ngrok to expose your local server to the internet:

```bash
ngrok http 8000
```

Then configure your webhook URL in the WorkOS Dashboard to point to your ngrok URL:

```
https://abc123.ngrok.io/webhooks/workos
```

Now webhooks from WorkOS will reach your local development environment.

## Disabling Webhooks

To disable webhook ingestion entirely:

```php
// config/workos.php
'webhooks' => [
    'enabled' => false,
],
```

Or disable webhooks for specific event categories, routing them to the Events API instead:

```php
'events' => [
    'routing' => [
        'categories' => [
            'user' => 'events_api',  // Route user events to Events API
            'organization' => 'webhooks',  // Keep org events on webhooks
        ],
    ],
],
```

## Webhook Signature Verification

The webhook endpoint automatically verifies signatures using the `WorkOS-Signature` header and your `WORKOS_WEBHOOK_SECRET`. This is handled by the `WebhookController` and the WorkOS PHP SDK's `Webhook::constructEvent()` method.

Signature validation:
- Checks timestamp (tolerance: 180 seconds)
- Verifies HMAC-SHA256 signature
- Returns 400 if invalid
- Returns 500 if secret not configured

## Error Handling

If webhook processing fails:

1. **Invalid signature** → Returns 400 Bad Request
2. **Sync error** → Event dispatches but listener may catch/report exception
3. **Missing model method** → Silently skips (e.g., if User model doesn't have `findOrCreateByWorkOS`)

To debug webhook issues:

```bash
# Check recent webhook logs
tail -f storage/logs/laravel.log | grep webhook

# Enable detailed logging in config/logging.php
'channels' => [
    'webhooks' => [
        'driver' => 'single',
        'path' => storage_path('logs/webhooks.log'),
        'level' => 'debug',
    ],
],
```

## Best Practices

### 1. Make Listeners Async

Process webhooks asynchronously to avoid blocking the response:

```php
class SyncUserFromWorkOS implements ShouldQueue
{
    // Implements queueable processing
}
```

### 2. Idempotent Operations

Webhooks can be retried. Make sync operations safe to repeat:

```php
// Good - updateOrCreate is idempotent
User::updateOrCreate(
    ['workos_id' => $data['id']],
    ['email' => $data['email']]
);

// Bad - creates duplicates on retry
User::create(['workos_id' => $data['id'], 'email' => $data['email']]);
```

### 3. Validate Webhook Data

Don't assume all fields exist:

```php
$workosUserId = $event->data['id'] ?? null;
if (! $workosUserId) {
    Log::warning('Webhook received without user ID', $event->data);
    return;
}
```

### 4. Log Webhook Activity

Track webhook processing for debugging:

```php
Event::listen(WorkOSUserCreated::class, function ($event) {
    Log::info('Webhook: User created', [
        'workos_id' => $event->data['id'],
        'email' => $event->data['email'],
    ]);
});
```

### 5. Handle Missing Records Gracefully

Don't fail if related records don't exist:

```php
Event::listen(WorkOSMembershipCreated::class, function ($event) {
    $user = User::where('workos_id', $event->data['user_id'])->first();
    
    if (! $user) {
        Log::info('Membership event for unknown user', [
            'user_id' => $event->data['user_id'],
        ]);
        return; // Silently skip rather than failing
    }
    
    // Process membership
});
```

## Troubleshooting

**Webhooks not being received**
1. Verify webhook URL in WorkOS Dashboard
2. Check that your server is accessible from the internet
3. Use `php artisan workos:events-listen` to test locally
4. Check webhook logs in WorkOS Dashboard

**"Webhook secret not configured"**
Add `WORKOS_WEBHOOK_SECRET` to your `.env` and run `php artisan config:clear`.

**"Invalid signature"**
Ensure your `WORKOS_WEBHOOK_SECRET` matches exactly what's in your WorkOS Dashboard. Copy-paste carefully.

**Data not syncing**
1. Verify your event routing includes webhooks: `config('workos.events.routing.categories')`
2. Verify your User and Organization models have the sync methods or traits
3. Check Laravel logs for sync errors
4. Manually trigger a sync with `php artisan workos:sync-users`
5. If webhooks aren't being triggered, verify the webhook is actually being sent by WorkOS in your Dashboard

**Listeners not executing**
1. Check that the event is registered via `Event::listen()` in `AppServiceProvider::boot()`
2. Verify the listener class exists and is correctly namespaced
3. Check that the webhook is actually being sent by WorkOS
