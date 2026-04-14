# Authorization

Control access to routes and features using roles and permissions from WorkOS.

## Overview

WorkOS roles and permissions are assigned in your WorkOS Dashboard and synced into your user's session. Your Laravel app checks these permissions to control access.

Permissions follow a hierarchical naming convention: `resource:action` (e.g., `posts:create`, `teams:manage`).

## Protecting Routes with Middleware

The package provides two middleware for authorization:

### CheckRole Middleware

Require one or more roles:

```php
Route::middleware('workos.role:admin')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});

// Multiple roles (user must have one)
Route::middleware('workos.role:admin,editor')->group(function () {
    Route::get('/posts/manage', PostController::class);
});
```

**Error Handling:**
- Throws `MissingRoleException` if user doesn't have the required role
- The exception includes role information for custom error pages

### CheckPermission Middleware

Require one or more permissions:

```php
Route::middleware('workos.permission:posts:create')->group(function () {
    Route::get('/posts/new', [PostController::class, 'create']);
    Route::post('/posts', [PostController::class, 'store']);
});

// Multiple permissions (user must have all)
Route::middleware('workos.permission:posts:create,posts:edit')->group(function () {
    Route::get('/posts/admin', PostController::class);
});
```

**Error Handling:**
- Throws `MissingPermissionException` if user doesn't have all required permissions
- The exception includes permission information for custom error pages

### CheckFGAAccess Middleware

Require FGA resource-level access (requires FGA enabled in config):

```php
Route::middleware('workos.fga:viewer,document,{document}')->group(function () {
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
});
```

The middleware accepts a permission slug and an `FGAResource` value object identifying the resource. FGA access checks are evaluated against the WorkOS Fine-Grained Authorization service.

### CheckFeatureFlag Middleware

Require a feature flag to be enabled for the current user:

```php
Route::middleware('workos.feature:new-dashboard')->group(function () {
    Route::get('/dashboard/v2', NewDashboardController::class);
});
```

### Combined Middleware

Combine authentication, roles, and permissions:

```php
Route::middleware([
    'auth:workos',
    'workos.role:admin',
    'workos.permission:users:manage',
])->group(function () {
    Route::get('/admin/users', UserController::class);
});
```

## Using Traits in Your User Model

Add the `HasWorkOSPermissions` trait to check roles and permissions in your code:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSPermissions;
}
```

The trait provides these methods:

### hasWorkOSRole()

Check if user has a specific role:

```php
if ($user->hasWorkOSRole('admin')) {
    // User has admin role
}
```

### hasWorkOSPermission()

Check if user has a specific permission:

```php
if ($user->hasWorkOSPermission('posts:create')) {
    // User can create posts
}
```

### hasAnyWorkOSRole()

Check if user has any of the provided roles:

```php
if ($user->hasAnyWorkOSRole(['admin', 'editor', 'moderator'])) {
    // User has at least one of these roles
}
```

### hasAllWorkOSPermissions()

Check if user has all of the provided permissions:

```php
if ($user->hasAllWorkOSPermissions(['posts:read', 'posts:create', 'posts:delete'])) {
    // User can read, create, and delete posts
}
```

### currentOrganizationId()

Get the current organization context from the session:

```php
$orgId = $user->currentOrganizationId();
```

### isImpersonating()

Check if admin is impersonating this user:

```php
if ($user->isImpersonating()) {
    // User is being impersonated
}
```

### impersonator()

Get details about the admin impersonating the user:

```php
$impersonator = $user->impersonator();
// Returns: ['email' => 'admin@example.com', 'reason' => 'Testing']
```

### setWorkOSSession() and getWorkOSSession()

Manually manage the user's WorkOS session:

```php
$session = $user->getWorkOSSession();
$user->setWorkOSSession($newSession);
```

## Using the WorkOS Facade

Check permissions directly via the facade:

```php
use WorkOS\AuthKit\Facades\WorkOS;

if (WorkOS::hasRole('admin')) {
    // User has admin role
}

if (WorkOS::hasPermission('posts:create')) {
    // User can create posts
}
```

## In Blade Templates

Use Blade directives to conditionally show content:

### @workosRole

```html
@workosRole('admin')
    <!-- Admin-only content -->
    <a href="/admin">Admin Panel</a>
@endworkosRole
```

### @workosPermission

```html
@workosPermission('posts:create')
    <!-- Only shown if user has this permission -->
    <a href="/posts/new" class="btn btn-primary">New Post</a>
@endworkosPermission
```

### @workosAccess

Check FGA resource-level access (requires FGA enabled in config):

```html
@workosAccess('viewer', $document)
    <!-- Only shown if user has viewer access to this resource -->
    <a href="/documents/{{ $document->id }}">View Document</a>
@endworkosAccess
```

The second argument is an `FGAResource` value object identifying the resource to check access against.

### @workosFeature

Check if a feature flag is enabled for the current user:

```html
@workosFeature('new-dashboard')
    <!-- Only shown if feature flag is enabled -->
    <a href="/dashboard/v2">Try New Dashboard</a>
@endworkosFeature
```

### @workosEntitlement

Check if the current user has an entitlement:

```html
@workosEntitlement('advanced-analytics')
    <!-- Only shown if user has this entitlement -->
    <a href="/analytics/advanced">Advanced Analytics</a>
@endworkosEntitlement
```

### Combined with @auth

```html
@auth('workos')
    <p>Welcome, {{ auth()->user()->name }}!</p>
    
    @workosPermission('posts:manage')
        <a href="/posts/admin">Manage Posts</a>
    @endworkosPermission
    
    @workosRole('admin')
        <a href="/admin">Admin Panel</a>
    @endworkosRole
@endauth
```

## Authorization Exceptions

When authorization fails, the middleware throws an exception. Handle it in your exception handler:

```php
<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use WorkOS\AuthKit\Exceptions\MissingRoleException;
use WorkOS\AuthKit\Exceptions\MissingPermissionException;

class Handler extends ExceptionHandler
{
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof MissingRoleException) {
            return response()->view('errors.forbidden', [
                'message' => 'You do not have the required role: '.$exception->getMessage(),
            ], 403);
        }

        if ($exception instanceof MissingPermissionException) {
            return response()->view('errors.forbidden', [
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return parent::render($request, $exception);
    }
}
```

## Best Practices

### 1. Use Meaningful Permission Names

Follow a consistent naming convention:

```
resource:action
team:read
team:update
team:delete
posts:create
posts:read
posts:edit
posts:delete
```

### 2. Combine with Authentication

Always require authentication before checking permissions:

```php
Route::middleware(['auth:workos', 'workos.permission:posts:create'])->group(function () {
    // ...
});
```

### 3. Fail Securely

Deny access by default. Explicitly grant permissions rather than assuming access:

```php
// Bad - assumes access if permission missing
if (! $user->hasWorkOSPermission('posts:delete')) {
    // ...
}

// Good - explicitly requires permission
if ($user->hasWorkOSPermission('posts:delete')) {
    // Grant access
} else {
    abort(403, 'Unauthorized');
}
```

### 4. Use Organization Context

If using organizations, combine permission checks with organization ownership:

```php
// Check user belongs to organization AND has permission
if ($user->belongsToOrganization($orgId) && 
    $user->hasOrganizationPermission($orgId, 'posts:delete')) {
    // Allow deletion
}
```

### 5. Log Authorization Failures

Log failed authorization attempts for security auditing:

```php
if (! $user->hasWorkOSPermission('sensitive:action')) {
    Log::warning('Unauthorized action attempted', [
        'user_id' => $user->id,
        'action' => 'sensitive:action',
        'ip' => request()->ip(),
    ]);
    abort(403);
}
```

### 6. Cache Permission Checks in Views

Avoid repeated permission checks in loops:

```blade
@php
    $canEdit = auth()->user()->hasWorkOSPermission('posts:edit');
@endphp

@foreach ($posts as $post)
    @if ($canEdit)
        <a href="/posts/{{ $post->id }}/edit">Edit</a>
    @endif
@endforeach
```

## Integration with Audit Logging

Track authorization checks in audit logs (requires audit_logs feature enabled):

```php
use WorkOS\AuthKit\Facades\WorkOS;

public function deletePost($id)
{
    $post = Post::findOrFail($id);
    
    if (! auth()->user()->hasWorkOSPermission('posts:delete')) {
        WorkOS::audit('posts.delete_denied', [
            ['type' => 'post', 'id' => $post->id, 'name' => $post->title],
        ]);
        abort(403);
    }
    
    $post->delete();
    
    WorkOS::audit('posts.deleted', [
        ['type' => 'post', 'id' => $post->id, 'name' => $post->title],
    ]);
}
```

## Middleware Reference

| Alias | Class | Description |
|---|---|---|
| `workos.auth` | `EnsureWorkOSAuthenticated` | Require authentication, redirect guests |
| `workos.role` | `CheckRole` | Require one or more roles |
| `workos.permission` | `CheckPermission` | Require one or more permissions |
| `workos.organization` | `CheckOrganization` | Require organization membership |
| `workos.apikey` | `ValidateApiKey` | Validate WorkOS API key |
| `workos.radar` | `ReportRadarAttempt` | Report authentication attempt to Radar |
| `workos.feature` | `CheckFeatureFlag` | Require feature flag enabled |
| `workos.fga` | `CheckFGAAccess` | FGA resource-level access check |
| `workos.inertia` | `ShareWorkOSData` | Share auth state with Inertia.js |

## Troubleshooting

**"User model missing HasWorkOSPermissions trait"**
The middleware error means your User model doesn't have the trait. Add it and run tests again.

**Permissions always return false**
Check that permissions are assigned in your WorkOS Dashboard under the organization's roles. Permissions come from the session, not the database.

**Middleware not executing**
Verify the middleware is registered in your route definition and spelled correctly (e.g., `workos.permission` not `workos:permission`).

**Custom error page not showing**
Ensure you're catching the exception in your exception handler before returning a view.
