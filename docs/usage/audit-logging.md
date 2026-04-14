# Audit Logging

Track user actions in your application with WorkOS Audit Logs.

## Overview

Audit logging lets you maintain a compliance-auditable record of actions taken in your application. Events are sent to the WorkOS Audit Logs API and indexed for searching and reporting.

**Requirements:**
- WorkOS Enterprise plan (Audit Logs API access)
- User authenticated with organization context

**Enable the feature** in `config/workos.php`:

```php
'features' => [
    'audit_logs' => true,
],
```

## Automatic Request Auditing

The `AuditMiddleware` automatically logs HTTP requests:

```php
Route::middleware([
    'auth:workos',
    'workos.audit', // Enable automatic auditing
])->group(function () {
    Route::post('/posts', [PostController::class, 'store']);
    Route::put('/posts/{id}', [PostController::class, 'update']);
    Route::delete('/posts/{id}', [PostController::class, 'destroy']);
});
```

The middleware logs:
- Action: HTTP method + path (e.g., `POST /posts`)
- Actor: Current authenticated user
- Target: Resource from route parameter (if available)
- Timestamp: When the request was made
- Context: IP address and user agent

## Manual Audit Logging

Log custom actions using the `WorkOS::audit()` method:

```php
use WorkOS\AuthKit\Facades\WorkOS;

WorkOS::audit('users.promoted', [
    ['type' => 'user', 'id' => $user->workos_id, 'name' => $user->name],
], metadata: [
    'promoted_from' => 'member',
    'promoted_to' => 'admin',
]);
```

### Method Signature

```php
WorkOS::audit(
    string $action,
    array $targets = [],
    array $metadata = [],
): void
```

**$action**
The action being logged. Use dot notation: `resource.action` (e.g., `users.promoted`, `posts.deleted`, `team.settings.updated`).

**$targets**
Array of resources affected by the action. Each target is an array:

```php
[
    'type' => 'post',                    // Resource type
    'id' => '123',                       // Resource ID
    'name' => 'My Blog Post',            // Human-readable name
    'metadata' => ['status' => 'draft'], // Optional extra data
]
```

Or use Auditable models (see below).

**$metadata**
Additional context for the action:

```php
WorkOS::audit('posts.created', targets: [...], metadata: [
    'category' => 'technology',
    'tags' => ['laravel', 'php'],
    'draft' => false,
]);
```

## Using Auditable Models

Implement the `Auditable` contract to make models audit-friendly:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use WorkOS\AuthKit\Audit\Contracts\Auditable;

class Post extends Model implements Auditable
{
    public function toAuditTarget(): array
    {
        return [
            'type' => 'post',
            'id' => (string) $this->id,
            'name' => $this->title,
            'metadata' => [
                'status' => $this->status,
                'author_id' => $this->user_id,
            ],
        ];
    }
}
```

Use the model directly in audit calls:

```php
$post = Post::find($id);

WorkOS::audit('posts.published', [
    $post, // Calls toAuditTarget() automatically
]);
```

### HasAuditTrail Trait

Use the trait for automatic audit target generation:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use WorkOS\AuthKit\Audit\Concerns\HasAuditTrail;
use WorkOS\AuthKit\Audit\Contracts\Auditable;

class Post extends Model implements Auditable
{
    use HasAuditTrail;

    // Automatically implements toAuditTarget() based on model properties
}
```

The trait generates audit targets using:
- `audit_type`: Model class name (or override)
- `audit_id`: Primary key
- `audit_name`: Model name field (or override)
- `audit_metadata`: Extra attributes (or override)

Customize behavior:

```php
class Post extends Model implements Auditable
{
    use HasAuditTrail;

    public string $auditType = 'blog_post'; // Override type
    
    protected function getAuditName(): ?string
    {
        return $this->title;
    }

    protected function getAuditMetadata(): array
    {
        return [
            'status' => $this->status,
            'word_count' => str_word_count($this->content),
        ];
    }
}
```

## Common Audit Patterns

### User Authentication

```php
use WorkOS\AuthKit\Events\UserAuthenticated;

Event::listen(UserAuthenticated::class, function (UserAuthenticated $event) {
    WorkOS::audit('user.authenticated', [
        ['type' => 'user', 'id' => $event->user->workos_id, 'name' => $event->user->name],
    ], metadata: [
        'session_id' => $event->session->sessionId,
    ]);
});
```

### User Logout

```php
use WorkOS\AuthKit\Events\UserLoggedOut;

Event::listen(UserLoggedOut::class, function (UserLoggedOut $event) {
    WorkOS::audit('user.logged_out', [
        ['type' => 'user', 'id' => $event->user->workos_id],
    ]);
});
```

### Resource Creation

```php
public function store(Request $request)
{
    $post = Post::create($request->validated());

    WorkOS::audit('posts.created', [
        $post,
    ], metadata: [
        'content_length' => strlen($post->content),
    ]);

    return redirect()->route('posts.show', $post);
}
```

### Resource Deletion

```php
public function destroy(Post $post)
{
    WorkOS::audit('posts.deleted', [
        $post,
    ]);

    $post->delete();

    return back();
}
```

### Bulk Operations

```php
public function deleteMany(Request $request)
{
    $ids = $request->input('ids');
    $posts = Post::whereIn('id', $ids)->get();

    $targets = $posts->map(fn ($post) => [
        'type' => 'post',
        'id' => (string) $post->id,
        'name' => $post->title,
    ])->toArray();

    WorkOS::audit('posts.deleted_bulk', $targets, metadata: [
        'count' => count($ids),
    ]);

    Post::whereIn('id', $ids)->delete();

    return back();
}
```

### Permission Changes

```php
public function updateRole(Request $request, User $user)
{
    $oldRole = $user->workos_role;
    $newRole = $request->input('role');

    $user->updateRole($newRole);

    WorkOS::audit('users.role_changed', [
        ['type' => 'user', 'id' => $user->workos_id, 'name' => $user->name],
    ], metadata: [
        'old_role' => $oldRole,
        'new_role' => $newRole,
    ]);

    return back();
}
```

### Settings Changes

```php
public function updateSettings(Request $request)
{
    $organization = request('current_organization');
    $changes = [];

    if ($request->has('name') && $organization->name !== $request->input('name')) {
        $changes['name'] = [
            'from' => $organization->name,
            'to' => $request->input('name'),
        ];
    }

    $organization->update($request->validated());

    if (! empty($changes)) {
        WorkOS::audit('organization.settings_updated', [
            ['type' => 'organization', 'id' => $organization->workos_id, 'name' => $organization->name],
        ], metadata: [
            'changes' => $changes,
        ]);
    }

    return back();
}
```

## Testing Audit Logs

Test audit logging using the fake:

```php
use WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS;

class PostTest extends TestCase
{
    use InteractsWithWorkOS;

    public function test_audit_logs_post_creation()
    {
        $fake = WorkOS::fake();
        $user = User::factory()->create();

        $this->actingAsWorkOS($user);

        $this->post('/posts', [
            'title' => 'Test Post',
            'content' => 'Content here',
        ]);

        $fake->assertAudited('posts.created');
    }

    public function test_audit_logs_not_fired_on_validation_error()
    {
        $fake = WorkOS::fake();
        $user = User::factory()->create();

        $this->actingAsWorkOS($user);

        $this->post('/posts', [
            'title' => '', // Invalid
        ]);

        $fake->assertNotAudited('posts.created');
    }
}
```

### Assertion Methods

**assertAudited()**
Assert an action was audited. Pass an optional callback to inspect event data:

```php
$fake->assertAudited('posts.created');

// Check metadata via callback:
$fake->assertAudited('posts.created', fn ($e) => $e['metadata']['category'] === 'technology');
```

**assertNotAudited()**
Assert an action was not audited:

```php
$fake->assertNotAudited('posts.deleted');
```

**assertAuditedCount()**
Assert the total number of audit events logged:

```php
$fake->assertAuditedCount(3);
```

## Disabling Audit Logs

Disable globally:

```php
// In config/workos.php
'features' => [
    'audit_logs' => false,
],
```

Or skip auditing for specific requests:

```php
// Manually
if (! config('workos.features.audit_logs')) {
    return; // Skip audit
}

WorkOS::audit('action', targets: [...]);
```

## API Response Format

Audit events sent to WorkOS include:

```php
[
    'action' => [
        'type' => 'posts.created',
        'name' => 'Posts created',
    ],
    'actor' => [
        'type' => 'user',
        'id' => 'user_123abc',
        'name' => 'John Doe',
    ],
    'targets' => [
        [
            'type' => 'post',
            'id' => '42',
            'name' => 'My Blog Post',
            'metadata' => [
                'status' => 'published',
            ],
        ],
    ],
    'context' => [
        'location' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0...',
    ],
    'metadata' => [
        'custom_field' => 'custom_value',
    ],
    'occurred_at' => '2024-01-15T10:30:00Z',
]
```

## Best Practices

### 1. Use Meaningful Action Names

Follow a consistent naming convention:

```
resource.action
user.created
user.deleted
user.role_changed
post.published
post.unpublished
organization.settings_updated
```

### 2. Include Relevant Targets

Always specify what was affected:

```php
WorkOS::audit('post.updated', [
    $post, // The resource affected
]);

// Don't just audit the action without context
WorkOS::audit('post.updated'); // Too vague
```

### 3. Audit Sensitive Operations

Log security-relevant actions:

```php
// Always audit these
WorkOS::audit('user.password_reset');
WorkOS::audit('user.mfa_enabled');
WorkOS::audit('user.role_promoted');
WorkOS::audit('api_key.generated');
WorkOS::audit('organization.member_invited');
```

### 4. Add Useful Metadata

Include information that helps investigators understand the context:

```php
WorkOS::audit('permission.changed', targets: [...], metadata: [
    'granted_permissions' => ['posts:create', 'posts:delete'],
    'removed_permissions' => ['users:manage'],
    'reason' => 'Role downgrade per security review',
]);
```

### 5. Use Auditable Models

When possible, use models implementing the `Auditable` contract:

```php
WorkOS::audit('post.deleted', [
    $post, // Automatically converts to audit target
]);
```

### 6. Handle Audit Failures Gracefully

Audit failures shouldn't break your application:

```php
// Wrapped in try-catch (done by AuditLogger automatically)
try {
    WorkOS::audit('action', targets: [...]);
} catch (\Exception $e) {
    Log::error('Audit logging failed', ['error' => $e->message]);
    // Continue without failing the request
}
```

### 7. Organization Context is Required

Ensure the user has an organization context when logging:

```php
public function handle(Request $request, Closure $next)
{
    // audit_logs require organization context
    if (! $request->user()->currentOrganizationId()) {
        // Skip or set context
    }

    return $next($request);
}
```

## Troubleshooting

**"Audit logs not being sent"**
1. Ensure `WORKOS_FEATURE_AUDIT_LOGS=true` in `.env`
2. Verify user is authenticated and belongs to an organization
3. Check `WORKOS_API_KEY` is set correctly
4. Review Laravel logs for API errors

**"Response was empty"**
Audit operations return void. Don't expect a return value from `WorkOS::audit()`.

**"Audit data missing organization ID"**
The logger uses the organization ID from the current session. Ensure you're within an authenticated organization context.

**"Too many audit logs sent"**
Review your audit calls and remove unnecessary ones. Audit logs consume API quota.

**Testing assertions fail**
Ensure you're using `WorkOS::fake()` in tests and calling `actingAsWorkOS()` with proper organization context.
