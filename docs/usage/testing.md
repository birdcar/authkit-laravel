# Testing

Write tests for your authentication and authorization logic.

## Overview

AuthKit provides a testing API that allows you to:
- Set up a fake WorkOS service in tests
- Authenticate as a specific user with roles and permissions
- Assert on authentication state
- Audit logging assertions

All without making real API calls to WorkOS.

## Basic Setup

### Using the InteractsWithWorkOS Trait

Add the `InteractsWithWorkOS` trait to your test class:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS;

class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithWorkOS;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->tearDownInteractsWithWorkOS();
    }

    public function test_authenticated_user_can_view_dashboard()
    {
        $user = User::factory()->create();

        $this->actingAsWorkOS($user);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Welcome');
    }
}
```

The trait provides:
- `actingAsWorkOS()` - Authenticate as a user
- `fakeWorkOS()` - Set up a fake WorkOS service
- Auto-cleanup in tearDown

## Authenticating Test Users

### Simple Authentication

Authenticate as a user without roles or permissions:

```php
public function test_user_can_login()
{
    $user = User::factory()->create();

    $this->actingAsWorkOS($user);

    $this->assertTrue(auth()->check());
    $this->assertEquals($user->id, auth()->user()->id);
}
```

### With Roles

Authenticate with specific roles:

```php
public function test_admin_can_access_admin_panel()
{
    $user = User::factory()->create();

    $this->actingAsWorkOS($user, roles: ['admin']);

    $response = $this->get('/admin');
    $response->assertStatus(200);
}
```

### With Permissions

Authenticate with specific permissions:

```php
public function test_user_can_create_posts()
{
    $user = User::factory()->create();

    $this->actingAsWorkOS($user, permissions: ['posts:create']);

    $response = $this->post('/posts', [
        'title' => 'My Post',
        'content' => 'Hello world',
    ]);

    $response->assertStatus(201);
}
```

### With Organization Context

Authenticate within a specific organization:

```php
public function test_user_can_view_org_dashboard()
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $this->actingAsWorkOS($user, organizationId: $org->workos_id);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
}
```

### Combined

Use all options together:

```php
public function test_admin_can_manage_org_users()
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $this->actingAsWorkOS(
        $user,
        roles: ['admin'],
        permissions: ['users:manage', 'users:delete'],
        organizationId: $org->workos_id,
    );

    $response = $this->post('/organizations/'.$org->workos_id.'/users/ban', [
        'user_id' => 'user_123',
    ]);

    $response->assertStatus(200);
}
```

## Using WorkOSFake Directly

For more control, use the `fakeWorkOS()` method:

```php
public function test_session_management()
{
    $fake = $this->fakeWorkOS();

    $user = User::factory()->create();

    $fake->actingAs($user, roles: ['member']);

    $this->assertTrue($fake->isAuthenticated());
    $this->assertTrue($fake->hasRole('member'));
    $this->assertFalse($fake->hasRole('admin'));
}
```

### Builder Methods

Chain builder methods to configure the fake:

```php
$fake = WorkOS::fake()
    ->actingAs($user)
    ->withRoles(['admin', 'editor'])
    ->withPermissions(['posts:create', 'posts:delete'])
    ->inOrganization('org_123abc')
    ->impersonating(['email' => 'admin@example.com', 'reason' => 'Testing']);

// Verify settings
$this->assertTrue($fake->isImpersonating());
$this->assertTrue($fake->hasPermission('posts:create'));
```

### Accessing Fake Session

Get the generated session object:

```php
$session = $fake->session();

$this->assertEquals($user->id, $session->userId);
$this->assertContains('admin', $session->roles);
$this->assertContains('posts:create', $session->permissions);
```

## Route Authorization Tests

### Test Route Access

```php
public function test_guest_cannot_access_dashboard()
{
    $response = $this->get('/dashboard');
    $response->assertRedirect('/auth/login');
}

public function test_authenticated_user_can_access_dashboard()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
}

public function test_non_admin_cannot_access_admin_panel()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user); // No admin role

    $response = $this->get('/admin');
    $response->assertStatus(403);
}

public function test_admin_can_access_admin_panel()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user, roles: ['admin']);

    $response = $this->get('/admin');
    $response->assertStatus(200);
}
```

### Test Permission Middleware

```php
public function test_user_without_permission_cannot_create_post()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user); // No permissions

    $response = $this->post('/posts', [
        'title' => 'Test',
        'content' => 'Content',
    ]);

    $response->assertStatus(403);
}

public function test_user_with_permission_can_create_post()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user, permissions: ['posts:create']);

    $response = $this->post('/posts', [
        'title' => 'Test',
        'content' => 'Content',
    ]);

    $response->assertStatus(201);
}
```

## Assertion Methods

The fake WorkOS service provides these assertions:

### assertAuthenticated()

Verify user is authenticated:

```php
$fake = WorkOS::fake()->actingAs($user);
$fake->assertAuthenticated(); // Passes

$fake->destroySession();
$fake->assertGuest(); // Passes
```

### assertGuest()

Verify user is not authenticated:

```php
$fake = WorkOS::fake();
$fake->assertGuest(); // Passes
```

### assertHasRole()

Verify user has a specific role:

```php
$fake = WorkOS::fake()
    ->actingAs($user, roles: ['admin']);

$fake->assertHasRole('admin'); // Passes
$fake->assertHasRole('editor'); // Fails
```

### assertHasPermission()

Verify user has a specific permission:

```php
$fake = WorkOS::fake()
    ->actingAs($user, permissions: ['posts:create']);

$fake->assertHasPermission('posts:create'); // Passes
$fake->assertHasPermission('posts:delete'); // Fails
```

### assertIsImpersonating()

Verify admin is impersonating the user:

```php
$fake = WorkOS::fake()
    ->actingAs($user)
    ->impersonating(['email' => 'admin@example.com', 'reason' => 'Testing']);

$fake->assertIsImpersonating(); // Passes
```

## Testing Model Methods

Test role and permission methods on your User model:

```php
public function test_user_has_role_method()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user, roles: ['admin']);

    $this->assertTrue($user->hasWorkOSRole('admin'));
    $this->assertFalse($user->hasWorkOSRole('guest'));
}

public function test_user_has_permission_method()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user, permissions: ['posts:create', 'posts:read']);

    $this->assertTrue($user->hasWorkOSPermission('posts:create'));
    $this->assertTrue($user->hasAllWorkOSPermissions(['posts:create', 'posts:read']));
    $this->assertFalse($user->hasAllWorkOSPermissions(['posts:delete']));
}

public function test_user_has_any_role()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user, roles: ['member']);

    $this->assertTrue($user->hasAnyWorkOSRole(['admin', 'member']));
    $this->assertFalse($user->hasAnyWorkOSRole(['admin', 'editor']));
}
```

## Testing Organization Features

### Test Organization Membership

```php
public function test_user_belongs_to_organization()
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    
    $user->organizations()->attach($org);

    $this->assertTrue($user->belongsToOrganization($org->workos_id));
}

public function test_user_cannot_access_other_org()
{
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    
    $user->organizations()->attach($org1);

    $this->assertTrue($user->belongsToOrganization($org1->workos_id));
    $this->assertFalse($user->belongsToOrganization($org2->workos_id));
}
```

### Test Organization Switching

```php
public function test_user_can_switch_organizations()
{
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    
    $user->organizations()->attach($org1);
    $user->organizations()->attach($org2);
    
    $this->actingAsWorkOS($user, organizationId: $org1->workos_id);
    $this->assertEquals($org1->workos_id, auth()->user()->currentOrganizationId());

    // Switch organizations
    $this->post('/organizations/switch', [
        'organization_id' => $org2->workos_id,
    ]);
}
```

## Testing Controllers

### Test Authentication Controller

```php
public function test_login_redirects_to_workos()
{
    $response = $this->get('/auth/login');
    $response->assertRedirect();
    $this->assertStringContainsString('workos.com', $response->headers->get('Location'));
}

public function test_logout_clears_session()
{
    $user = User::factory()->create();
    $this->actingAsWorkOS($user);

    $this->assertTrue(auth()->check());

    $this->post('/auth/logout');

    $this->assertFalse(auth()->check());
}
```

### Test Organization Controller

```php
public function test_cannot_switch_to_org_user_does_not_belong_to()
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    
    $this->actingAsWorkOS($user);

    $response = $this->post('/organizations/switch', [
        'organization_id' => $org->workos_id,
    ]);

    $response->assertStatus(302);
    $response->assertSessionHasErrors('organization');
}
```

## Complete Test Example

```php
<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS;

class PostManagementTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithWorkOS;

    protected User $user;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->user->organizations()->attach($this->organization);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->tearDownInteractsWithWorkOS();
    }

    public function test_guest_cannot_view_post_creation_form()
    {
        $response = $this->get('/posts/create');
        $response->assertRedirect('/auth/login');
    }

    public function test_authenticated_user_without_permission_cannot_create_post()
    {
        $this->actingAsWorkOS($this->user);

        $response = $this->get('/posts/create');
        $response->assertStatus(403);
    }

    public function test_user_with_permission_can_create_post()
    {
        $this->actingAsWorkOS(
            $this->user,
            permissions: ['posts:create'],
            organizationId: $this->organization->workos_id,
        );

        $response = $this->post('/posts', [
            'title' => 'My Post',
            'content' => 'Hello world',
            'organization_id' => $this->organization->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('posts', [
            'title' => 'My Post',
        ]);
    }

    public function test_admin_can_manage_other_users_posts()
    {
        $otherUser = User::factory()->create();
        $post = $otherUser->posts()->create([
            'title' => 'Other Post',
            'content' => 'Content',
        ]);

        $this->actingAsWorkOS(
            $this->user,
            roles: ['admin'],
            permissions: ['posts:delete'],
        );

        $response = $this->delete('/posts/'.$post->id);
        $response->assertStatus(204);
    }
}
```

## Mocking WorkOS API Calls

For testing code that calls WorkOS services directly:

```php
use Mockery;
use WorkOS\UserManagement;

public function test_sync_users_from_workos()
{
    $mock = Mockery::mock(UserManagement::class);
    $mock->shouldReceive('listUsers')
        ->andReturn([
            (object) ['id' => 'user_1', 'email' => 'john@example.com'],
            (object) ['id' => 'user_2', 'email' => 'jane@example.com'],
        ]);

    $this->instance(UserManagement::class, $mock);

    $this->artisan('workos:sync-users');

    $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
}
```

## Best Practices

### 1. Use Factories

Create test data consistently:

```php
$user = User::factory()
    ->has(Organization::factory()->count(2))
    ->create();
```

### 2. Test Both Success and Failure Cases

```php
public function test_authorized_action_succeeds() { }
public function test_unauthorized_action_fails() { }
public function test_guest_action_redirects() { }
```

### 3. Test at Multiple Levels

- Unit: Model methods
- Feature: Full HTTP requests
- Integration: With real database

### 4. Clean Up After Tests

```php
protected function tearDown(): void
{
    parent::tearDown();
    $this->tearDownInteractsWithWorkOS();
}
```

### 5. Use Meaningful Test Names

```php
// Good
public function test_user_without_posts_permission_cannot_delete_post() { }

// Bad
public function test_delete() { }
```

## Troubleshooting

**"Guard [workos] is not defined"**
Ensure `auth.php` has the workos guard configured and it matches `config('workos.guard')`.

**Tests still call real WorkOS API**
Call `WorkOS::fake()` or use the trait. Ensure it's called before making API requests.

**Session is null in tests**
Use `actingAsWorkOS()` to set up authentication before testing.

**Permissions always return false**
Ensure you pass permissions to `actingAsWorkOS()`: `permissions: ['posts:create']`.
