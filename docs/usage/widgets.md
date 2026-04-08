# Widgets

Embed ready-made Livewire components for user management, settings, and more.

## Overview

AuthKit includes 8 widget groups with pre-built UI components for common authentication and organization management tasks. All widgets are Livewire 4+ components styled with Tailwind CSS.

**Requirements:**
- Livewire ^4.0
- Tailwind CSS ^4.1
- Flux/Flux Pro ^2.11 (headless component library)

**Enable the feature** in `config/workos.php`:

```php
'features' => [
    'widgets' => true, // default
],
```

## Available Widgets

### 1. User Management

Manage organization members, roles, and invitations.

**Components:**
- `UserManagement` (parent container)
- `MembersTable` (list of organization members)
- `MemberActions` (edit/remove actions per member)
- `InviteUser` (send invitations)

**Usage:**

```blade
<livewire:workos-user-management />
```

This renders the full user management interface with tabs for members and invitations.

**Features:**
- List all organization members
- View member roles
- Remove members from organization
- Send invitations
- Revoke pending invitations
- Filter by role or status

### 2. User Profile

User account settings and session management.

**Components:**
- `UserProfile` (parent container with tab navigation)
- `ProfileInfo` (edit name, email, avatar)
- `SecuritySettings` (password, MFA, verification)
- `SessionManagement` (active sessions, logout other devices)

**Usage:**

```blade
<livewire:workos-user-profile />
```

**Features:**
- View and edit profile information
- Manage security settings
- View and manage active sessions
- Logout from other devices

### 3. Admin Portal

Organization administration interface for settings and configurations.

**Components:**
- `AdminPortal` (parent container)
- `SsoConnectionList` (SSO provider management)
- `DomainList` (domain configuration)

**Usage:**

```blade
<livewire:workos-admin-portal />
```

**Features:**
- Configure SSO connections
- Manage organization domains
- View connection status and settings

### 4. API Keys

Manage organization API keys for integrations.

**Components:**
- `ApiKeys` (parent container)
- `ApiKeyList` (list and manage keys)

**Usage:**

```blade
<livewire:workos-api-keys />
```

**Features:**
- Generate new API keys
- View key details
- Revoke keys
- Copy keys to clipboard

### 5. Directory Sync

Configure directory synchronization (LDAP, SCIM).

**Components:**
- `DirectorySync` (parent container)
- `DirectoryList` (list connected directories)

**Usage:**

```blade
<livewire:workos-directory-sync />
```

**Features:**
- View connected directories
- Configure sync settings
- Monitor sync status

### 6. Data Integrations

Manage third-party data integrations.

**Components:**
- `DataIntegrations` (parent container)
- `DataIntegrationList` (list integrations)

**Usage:**

```blade
<livewire:workos-data-integrations />
```

**Features:**
- View available integrations
- Enable/disable integrations
- Configure integration settings

### 7. Settings

Organization settings and configuration.

**Components:**
- `Settings` (parent container)
- `OrganizationSettings` (org info, defaults, policies)

**Usage:**

```blade
<livewire:workos-settings />
```

**Features:**
- Edit organization name and details
- Configure defaults (roles, permissions)
- Manage policies

### 8. Sessions

Manage user sessions for the organization.

Note: Session management is primarily included within the UserProfile widget.

## Adding Widgets to Your Application

### 1. Publish Widget Views

Make widget views customizable:

```bash
php artisan vendor:publish --tag=workos-widget-views
```

This copies views to `resources/views/vendor/workos/livewire/widgets/`.

### 2. Publish Widget Styles

Copy the stylesheet:

```bash
php artisan vendor:publish --tag=workos-widget-styles
```

This copies `public/vendor/workos/widgets.css`.

Include the stylesheet in your layout:

```blade
<link rel="stylesheet" href="{{ asset('vendor/workos/widgets.css') }}">
```

### 3. Add Widget to a Page

Use Livewire component syntax in your views:

```blade
@extends('layouts.app')

@section('content')
    <div class="container mx-auto py-8">
        <h1 class="text-2xl font-bold mb-6">Organization Settings</h1>
        
        <livewire:workos-user-management />
    </div>
@endsection
```

## Theming and Customization

### CSS Custom Properties

Customize widget colors using CSS custom properties:

```css
:root {
    --workos-primary: #3b82f6;
    --workos-danger: #ef4444;
    --workos-success: #10b981;
    --workos-gray-900: #111827;
    --workos-gray-50: #fafafa;
}
```

Include this in your main stylesheet or in `<style>` tags in your layout.

### Appearance Prop

Some widgets accept an `appearance` prop to change styling:

```blade
<livewire:workos-user-management appearance="dark" />
```

Available values: `light`, `dark`, `system` (default)

### Publishing and Modifying Views

After publishing widget views, customize them in `resources/views/vendor/workos/livewire/widgets/`.

For example, to customize the user management header:

```blade
<!-- resources/views/vendor/workos/livewire/widgets/user-management/user-management.blade.php -->
@extends('workos::livewire.widgets.user-management.user-management')

@section('header')
    <div class="custom-header">
        <!-- Your custom header -->
    </div>
@endsection
```

### Widget CSS Structure

Widgets use these CSS classes for styling:

```css
.workos-widget                  /* Main widget container */
.workos-widget__header         /* Header section */
.workos-widget__content        /* Main content area */
.workos-widget__footer         /* Footer/actions */
.workos-widget__table          /* Table layouts */
.workos-widget__form           /* Form elements */
.workos-widget__button         /* Button styling */
.workos-widget--dark           /* Dark mode variant */
.workos-widget--light          /* Light mode variant */
```

## Widget API Tokens

Widgets need valid API tokens to communicate with WorkOS. The package automatically handles token generation and management through the `WithWidgetToken` trait.

### Token Requirements

- Valid organization context (from session)
- User must be authenticated
- Token is scoped to the current organization
- Tokens auto-refresh when expired

### Testing Tokens

In tests, use `WorkOS::fake()` to provide test tokens:

```php
public function test_user_management_widget()
{
    WorkOS::actingAs($this->user, roles: ['admin']);
    
    $response = $this->get('/organization/users');
    $response->assertStatus(200);
}
```

## Disabling Widgets

Disable all widgets in `config/workos.php`:

```php
'features' => [
    'widgets' => false,
],
```

Or disable specific widget groups by not rendering them in your views.

## Usage Examples

### Organization User Management Page

```blade
@extends('layouts.app')

@section('content')
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Sidebar navigation -->
            <aside class="md:col-span-1">
                <nav class="space-y-2">
                    <a href="/org/members" class="block px-4 py-2 rounded bg-blue-100">
                        Members
                    </a>
                    <a href="/org/settings" class="block px-4 py-2 rounded">
                        Settings
                    </a>
                </nav>
            </aside>

            <!-- Main content -->
            <main class="md:col-span-2">
                <livewire:workos-user-management />
            </main>
        </div>
    </div>
@endsection
```

### User Settings Dashboard

```blade
@extends('layouts.app')

@section('content')
    <div class="max-w-2xl mx-auto">
        <h1 class="text-3xl font-bold mb-8">Account Settings</h1>
        
        <livewire:workos-user-profile />
    </div>
@endsection
```

### Admin Control Center

```blade
@extends('layouts.app')

@section('content')
    <div class="container mx-auto">
        <h1 class="text-3xl font-bold mb-8">Admin Portal</h1>
        
        @if (auth()->user()->hasWorkOSRole('admin'))
            <livewire:workos-admin-portal />
        @else
            <div class="alert alert-warning">
                You do not have permission to access the admin portal.
            </div>
        @endif
    </div>
@endsection
```

## Routes for Widgets

Widgets require these routes to be protected with authentication:

```php
Route::middleware('auth:workos')->group(function () {
    Route::get('/account/profile', function () {
        return view('profile');
    });
    
    Route::get('/org/members', function () {
        return view('members');
    });
    
    Route::get('/org/settings', function () {
        return view('settings');
    });
});
```

## Troubleshooting

**Widgets not rendering**
1. Ensure Livewire is installed: `composer require livewire/livewire`
2. Check that widgets are enabled in config
3. Verify `public/vendor/workos/widgets.css` is published and included
4. Check browser console for JavaScript errors

**Styling looks wrong**
1. Ensure Tailwind CSS is properly configured
2. Include `widgets.css` in your layout
3. Check that Flux components are installed (for some widgets)
4. Verify custom CSS doesn't override widget styles

**Widget can't authenticate**
1. Check that user is authenticated (`auth:workos`)
2. Verify organization context is set (use `SetCurrentOrganization` middleware)
3. Ensure user has required role/permissions for widget
4. Check WorkOS API credentials in config

**Token errors**
1. Ensure `WORKOS_API_KEY` and `WORKOS_CLIENT_ID` are set
2. Verify user belongs to the organization
3. Check that the organization exists in WorkOS
4. Run `php artisan config:clear` to reload configuration

**Buttons/Actions not working**
1. Check browser console for JavaScript errors
2. Verify Livewire wire attributes are present in DOM
3. Ensure form CSRF tokens are included
4. Check that the form is properly wrapped in `<form>` tags
