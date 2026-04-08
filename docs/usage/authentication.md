# Authentication

Understand session management, login flows, and how WorkOS handles authentication in your Laravel application.

## How Authentication Works

WorkOS AuthKit uses a cookie-based session approach:

1. User visits `/auth/login`
2. Redirected to WorkOS sign-in page
3. User authenticates (SSO, magic link, password, etc.)
4. WorkOS redirects to `/auth/callback` with an authorization code
5. Your app exchanges the code for tokens and stores them in an encrypted cookie
6. The `wos-session` cookie becomes your single source of truth for authentication state

The `wos-session` cookie is encrypted using your Laravel app key (via Halite encryption) and is automatically refreshed when expired.

## Auto-Registered Routes

These routes are automatically registered when `config('workos.routes.enabled')` is `true` (default):

### GET /auth/login

Initiates the login flow. Redirects to WorkOS.

**Query Parameters:**
- `organization_id` (optional): Pre-select an organization for login
- `return_to` (optional): URL to return to after successful authentication
- `state` (optional): Custom state parameter to preserve context

**Example:**

```html
<a href="/auth/login?return_to=/dashboard">Sign In</a>

<!-- Pre-select organization -->
<a href="/auth/login?organization_id=org_123abc">Sign In to Acme Corp</a>
```

### GET /auth/callback

Handles the OAuth callback from WorkOS. Do not visit this manually.

The callback controller:
1. Validates the authorization code
2. Exchanges it for access and refresh tokens
3. Stores tokens in the `wos-session` cookie
4. Syncs user data to your database
5. Fires the `UserAuthenticated` event
6. Redirects to the `return_to` parameter or `config('workos.routes.home')`

### GET|POST /auth/logout

Destroys the session and clears the `wos-session` cookie.

**Query Parameters:**
- `return_to` (optional): URL to redirect to after logout

**Example:**

```html
<!-- As a link -->
<a href="/auth/logout">Sign Out</a>

<!-- As a form -->
<form action="/auth/logout" method="POST">
    @csrf
    <button type="submit">Sign Out</button>
</form>

<!-- With return URL -->
<a href="/auth/logout?return_to=/goodbye">Sign Out</a>
```

## Using the WorkOS Facade

Access authentication state and methods via the `WorkOS` facade:

### Check if Authenticated

```php
use WorkOS\AuthKit\Facades\WorkOS;

if (WorkOS::isAuthenticated()) {
    // User has a valid session
}
```

### Get Current Session

```php
$session = WorkOS::session(); // May be expired
$validSession = WorkOS::validSession(); // Automatically refreshes if needed
```

Both return a `WorkOSSession` object or `null`.

### Get Current User

```php
$user = WorkOS::user();
```

Returns your User model or `null` if not authenticated.

### Get Login URL

Generate a link to the login page:

```php
// Basic login
$url = WorkOS::loginUrl();

// With organization pre-selected
$url = WorkOS::loginUrl(organizationId: 'org_123abc');

// With custom state
$url = WorkOS::loginUrl(state: ['order_id' => 123]);
```

### Get Logout URL

Get the WorkOS logout URL before the session is destroyed:

```php
$logoutUrl = WorkOS::getLogoutUrl(returnTo: '/goodbye');
```

### Check Permissions and Roles

```php
if (WorkOS::hasRole('admin')) {
    // User has admin role
}

if (WorkOS::hasPermission('posts:create')) {
    // User can create posts
}
```

## WorkOSSession Object

The session object contains all authentication data:

```php
$session = WorkOS::validSession();

// Access properties
$session->userId;           // "user_123abc"
$session->accessToken;      // "Bearer token..."
$session->refreshToken;     // "Refresh token..."
$session->expiresAt;        // Carbon instance
$session->sessionId;        // "sess_123abc"
$session->roles;            // ['admin', 'member']
$session->permissions;      // ['posts:create', 'posts:read']
$session->organizationId;   // "org_123abc" or null
$session->impersonator;     // Impersonator data or null

// Check expiry
$session->isExpired();           // bool
$session->needsRefresh(5);       // bool - needs refresh within 5 minutes?

// Check roles/permissions
$session->hasRole('admin');              // bool
$session->hasPermission('posts:create'); // bool
```

## Using the Auth Guard

Authenticate routes using Laravel's native auth system:

### Protect Routes

```php
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth:workos');
```

Or using a route group:

```php
Route::middleware('auth:workos')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/settings', SettingsController::class);
});
```

### Access Authenticated User

```php
$user = auth()->user();          // Your User model
$user = auth('workos')->user();  // Explicit guard

if (auth()->check()) {
    // User is authenticated
}

if (auth()->guest()) {
    // User is not authenticated
}
```

### Redirect Guests

The `EnsureWorkOSAuthenticated` middleware (aliased as `workos.auth`) handles authentication:

```php
Route::middleware('workos.auth')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

If the user is not authenticated:
- **Web requests:** Redirected to the login route
- **API requests:** Returns 401 JSON response

You can specify a custom redirect:

```php
Route::middleware('workos.auth:/login?return_to=/dashboard')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

## User Model Setup

Add traits to your User model to enable WorkOS integration:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $fillable = ['workos_id', 'email', 'name'];
}
```

### HasWorkOSId Trait

Methods for finding users by their WorkOS ID:

```php
// Create or update by WorkOS user data
$user = User::findOrCreateByWorkOS([
    'id' => 'user_123abc',
    'email' => 'john@example.com',
    'first_name' => 'John',
    'last_name' => 'Doe',
]);

// Find by WorkOS ID
$user = User::findByWorkOSId('user_123abc');
```

### HasWorkOSPermissions Trait

Methods to check roles and permissions:

```php
// Check role
if ($user->hasWorkOSRole('admin')) {
    // ...
}

// Check permission
if ($user->hasWorkOSPermission('posts:create')) {
    // ...
}

// Check any role
if ($user->hasAnyWorkOSRole(['admin', 'editor'])) {
    // ...
}

// Check all permissions
if ($user->hasAllWorkOSPermissions(['posts:read', 'posts:create'])) {
    // ...
}

// Get current organization
$orgId = $user->currentOrganizationId();

// Check if impersonating
if ($user->isImpersonating()) {
    $impersonator = $user->impersonator();
    // $impersonator = ['email' => 'admin@example.com', 'reason' => 'Testing']
}
```

## Blade Templates

Use Blade directives to check authentication in views:

```html
@auth('workos')
    <!-- User is authenticated -->
    <p>Welcome, {{ auth()->user()->name }}!</p>
@endauth

@guest('workos')
    <!-- User is not authenticated -->
    <a href="/auth/login">Sign In</a>
@endguest

<!-- Check role -->
@workosRole('admin')
    <!-- Admin-only content -->
@endworkosRole

<!-- Check permission -->
@workosPermission('posts:create')
    <!-- User can create posts -->
@endworkosPermission

<!-- Check if impersonating -->
@impersonating
    <div class="alert alert-warning">
        You are being impersonated by {{ session()->get('impersonator.email') }}
    </div>
@endimpersonating
```

## Custom User Creation

By default, the auth callback creates or updates users with basic fields. To customize this, add a `findOrCreateByWorkOS` method to your User model:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /**
     * Create or update user from WorkOS user data.
     *
     * @param  array<string, mixed>  $workosUser
     */
    public static function findOrCreateByWorkOS(array $workosUser): self
    {
        return self::updateOrCreate(
            ['workos_id' => $workosUser['id']],
            [
                'email' => $workosUser['email'] ?? null,
                'name' => trim(($workosUser['first_name'] ?? '').' '.($workosUser['last_name'] ?? '')),
                'avatar' => $workosUser['profile_image_url'] ?? null,
                'phone' => $workosUser['phone_number'] ?? null,
            ]
        );
    }
}
```

## Session Lifecycle

Sessions are managed by the `SessionManager` class:

**Initial Authentication:**
1. Authorization code exchanged for tokens
2. Tokens sealed using Halite encryption
3. Stored in `wos-session` cookie (30-day duration)
4. WorkOSSession object created from response

**Per-Request:**
1. `wos-session` cookie decrypted
2. Session validated with WorkOS API
3. Cached in memory for the request
4. Automatically refreshed if within buffer time

**Expiry:**
- Access token lifetime: configured in `config('workos.session.access_token_lifetime')` (default 60 minutes)
- Cookie duration: 30 days
- Automatic refresh: Used when access token expires

**Logout:**
- `wos-session` cookie cleared
- Cache invalidated
- `UserLoggedOut` event fired
- Redirect to logout URL or home page

## Events

The package fires events during authentication lifecycle:

```php
// User successfully authenticated
event(new UserAuthenticated($user, $session));

// User logged out
event(new UserLoggedOut($user, $session));
```

Listen to these in your service provider:

```php
Event::listen(UserAuthenticated::class, function (UserAuthenticated $event) {
    // Log the authentication
    Log::info('User authenticated', ['user_id' => $event->user->id]);
});

Event::listen(UserLoggedOut::class, function (UserLoggedOut $event) {
    // Clean up resources
    Log::info('User logged out', ['user_id' => $event->user->id]);
});
```

## Troubleshooting

**Session is null even when logged in**
The session might be expired. Use `WorkOS::validSession()` instead of `WorkOS::session()` to trigger automatic refresh.

**User is authenticated but can't access protected routes**
Ensure the route middleware includes `auth:workos`. Check that your config guard is set to `'workos'`.

**Permissions are empty**
Ensure roles and permissions are assigned in your WorkOS Dashboard. They're stored in the session, not the database.

**Custom redirect after login not working**
Pass the `return_to` parameter to `/auth/login` as a query parameter or in the state object.
