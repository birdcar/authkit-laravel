# Organizations

Support multiple organizations per user with seamless switching and role management.

## Overview

The organizations feature allows your application to support multi-tenant scenarios where users belong to multiple organizations with different roles in each.

**Enable the feature** in `config/workos.php`:

```php
'features' => [
    'organizations' => true, // default
],
```

## Organization Model Setup

Create an Organization model to sync data from WorkOS:

```bash
php artisan make:model Organization
```

Update the model with the `HasOrganization` trait:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use WorkOS\AuthKit\Models\Concerns\HasOrganization;

class Organization extends Model
{
    use HasOrganization;

    protected $fillable = ['workos_id', 'name', 'slug'];

    // Define other relationships here
}
```

Update your User model with the trait as well:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use WorkOS\AuthKit\Models\Concerns\HasOrganization;

class User extends Authenticatable
{
    use HasOrganization;
    // ...
}
```

## HasOrganization Trait

The trait provides methods for managing organization relationships:

### organizations()

Get all organizations the user belongs to:

```php
$orgs = $user->organizations;

foreach ($orgs as $org) {
    echo $org->name; // "Acme Corp"
    echo $org->pivot->role; // "admin", "member", etc.
}
```

### currentOrganization()

Get the currently active organization from the session:

```php
$org = $user->currentOrganization();
if ($org) {
    echo $org->name;
}
```

### belongsToOrganization()

Check if user is a member of an organization:

```php
if ($user->belongsToOrganization('org_123abc')) {
    // User is a member
}
```

### organizationRole()

Get the user's role in a specific organization:

```php
$role = $user->organizationRole('org_123abc');
// Returns 'admin', 'member', or null
```

### hasOrganizationRole()

Check if user has a specific role in an organization:

```php
if ($user->hasOrganizationRole('org_123abc', 'admin')) {
    // User is an admin in this organization
}
```

### hasOrganizationPermission()

Check if user has a permission in a specific organization's context:

```php
if ($user->hasOrganizationPermission('org_123abc', 'users:manage')) {
    // User can manage users in this organization
}
```

## Switching Organizations

Users can switch between organizations using the `/organizations/switch` endpoint:

```html
<form action="/organizations/switch" method="POST">
    @csrf
    <select name="organization_id" onchange="this.form.submit()">
        @foreach (auth()->user()->organizations as $org)
            <option value="{{ $org->workos_id }}">{{ $org->name }}</option>
        @endforeach
    </select>
</form>
```

The endpoint validates that the user belongs to the organization before switching.

**Programmatically:**

```php
return redirect('/organizations/switch')
    ->with(['organization_id' => 'org_123abc']);
```

## Setting Current Organization Context

Use the `SetCurrentOrganization` middleware to load the current organization into every request:

```php
Route::middleware(['auth:workos', 'workos.organization.current'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

The middleware:
1. Reads the organization ID from the session
2. Loads the organization from the database
3. Shares it with all views as `$currentOrganization`
4. Sets it in the request attributes as `request('current_organization')`

**In your controller:**

```php
public function index()
{
    $org = request('current_organization');
    
    return view('dashboard', ['organization' => $org]);
}
```

**In your view:**

```blade
<h1>{{ $currentOrganization->name }}</h1>
```

## Checking Organization Membership

Use the `CheckOrganization` middleware to require membership in a specific organization:

```php
Route::middleware([
    'auth:workos',
    'workos.organization:org_123abc',
])->group(function () {
    Route::get('/acme-dashboard', AcmeDashboardController::class);
});
```

This throws a 403 if the user doesn't belong to that organization.

## Invitation Management

Send and manage organization invitations through WorkOS:

### Send Invitation

```php
use WorkOS\AuthKit\Facades\WorkOS;

$userManagement = WorkOS::userManagement();

$invitation = $userManagement->sendInvitation(
    email: 'newuser@example.com',
    organizationId: 'org_123abc',
    roleSlug: 'member', // optional
);
```

Or use the auto-registered endpoint:

```html
<form action="/organizations/org_123abc/invitations" method="POST">
    @csrf
    <input type="email" name="email" required>
    <select name="role">
        <option value="">Default Role</option>
        <option value="admin">Admin</option>
        <option value="member">Member</option>
    </select>
    <button type="submit">Send Invitation</button>
</form>
```

**Event:** The `InvitationSent` event is fired when an invitation is sent.

### Revoke Invitation

```php
$userManagement = WorkOS::userManagement();
$userManagement->revokeInvitation('invitation_123abc');
```

Or use the endpoint:

```php
DELETE /organizations/org_123abc/invitations/invitation_123abc
```

**Event:** The `InvitationRevoked` event is fired when an invitation is revoked.

## Auto-Sync from Webhooks

When webhooks are enabled, organization data is automatically synced from WorkOS:

- **organization.created**: Creates a new Organization record
- **organization.updated**: Updates the organization name and metadata
- **organization.deleted**: Deletes the Organization record
- **organization_membership.created**: Links user to organization
- **organization_membership.updated**: Updates the user's role in the organization
- **organization_membership.deleted**: Removes the user from the organization

Disable auto-sync for a specific listener by setting it to `null` in `config/workos.php`:

```php
'sync' => [
    'listeners' => [
        \WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated::class => null,
    ],
],
```

Then handle sync events manually by listening to them:

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;

Event::listen(WorkOSOrganizationCreated::class, function ($event) {
    // Custom sync logic
    Organization::updateOrCreate(
        ['workos_id' => $event->data['id']],
        ['name' => $event->data['name']]
    );
});
```

## Organization Domain Events

The following events are dispatched when organization domain state changes in WorkOS. All are in the `WorkOS\AuthKit\Events\Sync` namespace.

| Event class | Trigger |
|---|---|
| `WorkOSOrganizationDomainCreated` | A domain is added to an organization |
| `WorkOSOrganizationDomainUpdated` | A domain record is updated |
| `WorkOSOrganizationDomainDeleted` | A domain is removed from an organization |
| `WorkOSOrganizationDomainVerified` | A domain passes verification |
| `WorkOSOrganizationDomainVerificationFailed` | A domain fails verification |

Listen to these events to react to domain lifecycle changes:

```php
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerified;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerificationFailed;

Event::listen(WorkOSOrganizationDomainVerified::class, function ($event) {
    // Domain has been verified — enable domain-restricted features
});

Event::listen(WorkOSOrganizationDomainVerificationFailed::class, function ($event) {
    // Notify org admin that verification failed
});
```

## Organization Model Migration

If you need to customize how organizations are created from WorkOS data, add a `findOrCreateByWorkOS` method:

```php
<?php

namespace App\Models;

class Organization extends Model
{
    public static function findOrCreateByWorkOS(array $data): self
    {
        return self::updateOrCreate(
            ['workos_id' => $data['id']],
            [
                'name' => $data['name'],
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'metadata' => $data,
            ]
        );
    }
}
```

## Database Tables

The package creates these tables for organization support:

### organizations
Stores organization data synced from WorkOS.

```
id, workos_id, name, slug, created_at, updated_at
```

### organization_memberships (pivot table)
Stores the many-to-many relationship between users and organizations.

```
id, user_id, organization_id, role, created_at, updated_at
```

## Example: Multi-Org Dashboard

Build a dashboard that respects organization context:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $organization = request('current_organization');

        if (! $organization) {
            return view('select-organization', [
                'organizations' => $user->organizations,
            ]);
        }

        return view('dashboard', [
            'organization' => $organization,
            'users' => $user->hasOrganizationRole($organization->workos_id, 'admin')
                ? $this->getOrganizationUsers($organization)
                : [],
        ]);
    }

    private function getOrganizationUsers($organization)
    {
        // Fetch organization members from WorkOS
        return workos()->organizations()
            ->listOrganizationMembers($organization->workos_id);
    }
}
```

Routes:

```php
Route::middleware(['auth:workos'])->group(function () {
    // Select organization
    Route::get('/organizations', [OrganizationController::class, 'list'])
        ->name('organizations.list');
    
    // Org-specific routes
    Route::middleware('workos.organization.current')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');
        
        Route::middleware('workos.role:admin')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index'])
                ->name('settings');
        });
    });
});
```

## Best Practices

### 1. Always Check Organization Membership

When loading organization-specific data, verify the user belongs to that organization:

```php
public function show($orgId, $resourceId)
{
    $user = auth()->user();
    
    if (! $user->belongsToOrganization($orgId)) {
        abort(403);
    }
    
    // Load resource scoped to organization
    $resource = Resource::where('organization_id', $orgId)
        ->findOrFail($resourceId);
}
```

### 2. Use SetCurrentOrganization Middleware

Apply this middleware to route groups to automatically load organization context:

```php
Route::middleware(['auth:workos', 'workos.organization.current'])->group(function () {
    // All routes here have access to $currentOrganization
});
```

### 3. Filter Data by Organization

Always scope queries to the current organization:

```php
$posts = Post::where('organization_id', request('current_organization')->id)
    ->latest()
    ->get();
```

### 4. Combine with Roles/Permissions

Use organization roles alongside WorkOS permissions:

```php
if ($user->hasOrganizationRole($orgId, 'admin') && 
    $user->hasPermission('users:manage')) {
    // User can manage users in this organization
}
```

### 5. Provide Clear Organization Switcher

Make it obvious which organization the user is viewing:

```blade
<div class="org-selector">
    <form action="/organizations/switch" method="POST">
        @csrf
        <select name="organization_id">
            @foreach (auth()->user()->organizations as $org)
                <option 
                    value="{{ $org->workos_id }}"
                    @selected($org->id === $currentOrganization?->id)
                >
                    {{ $org->name }}
                </option>
            @endforeach
        </select>
    </form>
</div>
```

## Troubleshooting

**Organizations relation is empty**
Make sure webhooks are enabled and organization membership events are being synced. Check the `organization_memberships` table.

**currentOrganization() returns null**
Ensure `SetCurrentOrganization` middleware is applied and the user has active organizations in their session.

**User can't switch to organization**
Verify the user belongs to that organization in your WorkOS Dashboard. Use `belongsToOrganization()` to debug.

**Organization data is stale**
Run `php artisan workos:sync-users` to manually sync user and organization data from WorkOS, or enable webhooks for real-time sync.
