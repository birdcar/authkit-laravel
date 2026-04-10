# Implementation Spec: AuthKit Laravel Enhancement - Phase 3

**PRD**: ./prd-phase-3.md
**Estimated Effort**: M (Medium)

## Technical Approach

This phase implements WorkOS AuthKit authentication and organization multi-tenancy in the example app. The AuthKit package already provides auth routes and controllers, so we leverage those. The main work is creating Livewire components for the UI and implementing organization switching.

Key technical decisions:
1. Use the package's built-in auth routes (`/auth/login`, `/auth/callback`, `/auth/logout`)
2. Store current organization ID in session
3. Create a Livewire component for organization switching
4. Use Flux components for all UI elements
5. Implement session-based organization context middleware

The authentication flow is handled entirely by the AuthKit package - we just need to configure it and build the UI.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `workbench/app/Livewire/OrganizationSwitcher.php` | Organization switcher dropdown component |
| `workbench/app/Http/Middleware/SetCurrentOrganization.php` | Set organization from session |
| `workbench/resources/views/livewire/organization-switcher.blade.php` | Organization switcher UI |
| `workbench/resources/views/auth/login.blade.php` | Login page |
| `workbench/resources/views/dashboard.blade.php` | Dashboard page |
| `workbench/app/Providers/AppServiceProvider.php` | Register middleware, share data |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `workbench/routes/web.php` | Add dashboard and protected routes |
| `workbench/config/workos.php` | Configure WorkOS settings |
| `workbench/bootstrap/app.php` | Register middleware |

### Deleted Files

| File Path | Reason |
|-----------|--------|
| `workbench/resources/views/welcome.blade.php` | Replace with login redirect |

## Implementation Details

### Configure WorkOS

**workbench/config/workos.php** (publish and customize):
```php
<?php

return [
    'api_key' => env('WORKOS_API_KEY'),
    'client_id' => env('WORKOS_CLIENT_ID'),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', 'http://localhost:8000/auth/callback'),

    'guard' => 'workos',

    'user_model' => App\Models\User::class,
    'organization_model' => App\Models\Organization::class,

    'features' => [
        'organizations' => true,
        'impersonation' => true,
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'middleware' => ['web'],
        'home' => '/dashboard',
    ],

    'webhooks' => [
        'enabled' => true,
        'prefix' => 'webhooks/workos',
        'sync_enabled' => true,
    ],
];
```

### Authentication Guard Setup

**workbench/config/auth.php** (modify):
```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'workos' => [
        'driver' => 'workos',
        'provider' => 'users',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
],
```

### Login Page

**workbench/resources/views/auth/login.blade.php**:
```blade
<x-layouts.guest>
    <x-slot name="title">Login</x-slot>

    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <img src="https://workos.imgix.net/images/workos-logo.svg" alt="WorkOS" class="h-8 mx-auto mb-4 dark:hidden">
            <img src="https://workos.imgix.net/images/workos-logo-white.svg" alt="WorkOS" class="h-8 mx-auto mb-4 hidden dark:block">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Todo App</h1>
            <p class="text-zinc-600 dark:text-zinc-400 mt-2">Sign in to manage your todos</p>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                {{ session('error') }}
            </flux:callout>
        @endif

        <flux:card>
            <div class="space-y-4">
                <flux:button href="{{ route('login') }}" variant="primary" class="w-full">
                    <flux:icon.arrow-right-end-on-rectangle class="mr-2" />
                    Sign in with WorkOS
                </flux:button>

                <div class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                    Don't have an account?
                    <flux:link href="{{ route('login') }}">Sign up</flux:link>
                </div>
            </div>
        </flux:card>
    </div>
</x-layouts.guest>
```

### Dashboard Page

**workbench/resources/views/dashboard.blade.php**:
```blade
<x-layouts.app>
    <x-slot name="title">Dashboard</x-slot>

    <flux:heading size="xl" level="1">Dashboard</flux:heading>

    <flux:subheading class="mb-6">
        Welcome back, {{ auth()->user()->name }}!
    </flux:subheading>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <flux:card>
            <flux:heading size="lg">Your Todos</flux:heading>
            <div class="mt-2">
                <p class="text-4xl font-bold text-zinc-900 dark:text-white">
                    {{ $todoCount ?? 0 }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Total tasks</p>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Completed</flux:heading>
            <div class="mt-2">
                <p class="text-4xl font-bold text-green-600 dark:text-green-400">
                    {{ $completedCount ?? 0 }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Tasks done</p>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Organization</flux:heading>
            <div class="mt-2">
                <p class="text-lg font-medium text-zinc-900 dark:text-white truncate">
                    {{ $currentOrganization?->name ?? 'Personal' }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $memberCount ?? 0 }} members
                </p>
            </div>
        </flux:card>
    </div>

    <div class="mt-8">
        <flux:heading size="lg" class="mb-4">Quick Actions</flux:heading>

        <div class="flex gap-4">
            <flux:button href="{{ route('todos.index') }}" variant="primary">
                <flux:icon.plus class="mr-2" />
                New Todo
            </flux:button>

            <flux:button href="{{ route('organizations.settings') }}" variant="ghost">
                <flux:icon.cog-6-tooth class="mr-2" />
                Organization Settings
            </flux:button>
        </div>
    </div>
</x-layouts.app>
```

### Organization Switcher Component

**workbench/app/Livewire/OrganizationSwitcher.php**:
```php
<?php

namespace App\Livewire;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use WorkOS\AuthKit\Events\OrganizationSwitched;

class OrganizationSwitcher extends Component
{
    public ?int $currentOrganizationId = null;

    public function mount(): void
    {
        $this->currentOrganizationId = Session::get('current_organization_id');
    }

    public function switch(int $organizationId): void
    {
        $user = auth()->user();
        $organization = Organization::find($organizationId);

        if (!$organization || !$user->organizations->contains($organization)) {
            return;
        }

        $previousId = $this->currentOrganizationId;
        $this->currentOrganizationId = $organizationId;
        Session::put('current_organization_id', $organizationId);

        event(new OrganizationSwitched(
            $user,
            $organization,
            $previousId ? Organization::find($previousId) : null
        ));

        $this->redirect(request()->header('Referer', route('dashboard')));
    }

    public function render(): View
    {
        $user = auth()->user();
        $organizations = $user?->organizations ?? collect();
        $current = $this->currentOrganizationId
            ? $organizations->firstWhere('id', $this->currentOrganizationId)
            : $organizations->first();

        // Auto-select first organization if none selected
        if (!$this->currentOrganizationId && $current) {
            Session::put('current_organization_id', $current->id);
            $this->currentOrganizationId = $current->id;
        }

        return view('livewire.organization-switcher', [
            'organizations' => $organizations,
            'current' => $current,
        ]);
    }
}
```

**workbench/resources/views/livewire/organization-switcher.blade.php**:
```blade
<div>
    @if ($organizations->count() > 0)
        <flux:dropdown position="top" align="start">
            <flux:button variant="ghost" class="w-full justify-start">
                <flux:icon.building-office class="mr-2" />
                <span class="truncate">{{ $current?->name ?? 'Select Organization' }}</span>
                <flux:icon.chevron-up-down class="ml-auto" />
            </flux:button>

            <flux:menu>
                @foreach ($organizations as $org)
                    <flux:menu.item
                        wire:click="switch({{ $org->id }})"
                        :active="$current?->id === $org->id"
                    >
                        {{ $org->name }}
                        @if ($current?->id === $org->id)
                            <flux:icon.check class="ml-auto text-green-500" />
                        @endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @else
        <div class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">
            No organizations
        </div>
    @endif
</div>
```

### Current Organization Middleware

**workbench/app/Http/Middleware/SetCurrentOrganization.php**:
```php
<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $organizationId = Session::get('current_organization_id');
            $organization = null;

            if ($organizationId) {
                $organization = $user->organizations->firstWhere('id', $organizationId);
            }

            // Fall back to first organization
            if (!$organization) {
                $organization = $user->organizations->first();
                if ($organization) {
                    Session::put('current_organization_id', $organization->id);
                }
            }

            // Share with all views
            View::share('currentOrganization', $organization);

            // Make available on request
            $request->attributes->set('current_organization', $organization);
        }

        return $next($request);
    }
}
```

### Routes

**workbench/routes/web.php**:
```php
<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

// Guest routes
Route::view('/', 'auth.login')->name('home')->middleware('guest');

// Protected routes
Route::middleware(['auth:workos', \App\Http\Middleware\SetCurrentOrganization::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Organization routes
    Route::prefix('organizations')->name('organizations.')->group(function () {
        Route::get('/settings', [OrganizationController::class, 'settings'])->name('settings');
    });

    // Todo routes will be added in Phase 4
});
```

### Dashboard Controller

**workbench/app/Http/Controllers/DashboardController.php**:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');

        $todoQuery = Todo::where('user_id', $user->id);
        if ($organization) {
            $todoQuery->where('organization_id', $organization->id);
        }

        return view('dashboard', [
            'todoCount' => $todoQuery->count(),
            'completedCount' => (clone $todoQuery)->where('completed', true)->count(),
            'currentOrganization' => $organization,
            'memberCount' => $organization?->users()->count() ?? 0,
        ]);
    }
}
```

### Organization Controller

**workbench/app/Http/Controllers/OrganizationController.php**:
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function settings(Request $request): View
    {
        $organization = $request->attributes->get('current_organization');
        $members = $organization?->users()->withPivot('role')->get() ?? collect();

        return view('organizations.settings', [
            'organization' => $organization,
            'members' => $members,
        ]);
    }
}
```

### Organization Settings Page

**workbench/resources/views/organizations/settings.blade.php**:
```blade
<x-layouts.app>
    <x-slot name="title">Organization Settings</x-slot>

    <flux:heading size="xl" level="1">Organization Settings</flux:heading>

    @if ($organization)
        <flux:subheading class="mb-6">
            Manage settings for {{ $organization->name }}
        </flux:subheading>

        <div class="space-y-8">
            {{-- Organization Info --}}
            <flux:card>
                <flux:heading size="lg">Organization Details</flux:heading>

                <div class="mt-4 space-y-3">
                    <div>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Name</flux:text>
                        <flux:text class="font-medium">{{ $organization->name }}</flux:text>
                    </div>

                    <div>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Slug</flux:text>
                        <flux:text class="font-mono">{{ $organization->slug }}</flux:text>
                    </div>

                    @if ($organization->domains)
                        <div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Domains</flux:text>
                            <div class="flex flex-wrap gap-2 mt-1">
                                @foreach ($organization->domains as $domain)
                                    <flux:badge>{{ $domain }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </flux:card>

            {{-- Members --}}
            <flux:card>
                <flux:heading size="lg">Members</flux:heading>

                <div class="mt-4">
                    <flux:table>
                        <flux:columns>
                            <flux:column>Name</flux:column>
                            <flux:column>Email</flux:column>
                            <flux:column>Role</flux:column>
                        </flux:columns>

                        <flux:rows>
                            @forelse ($members as $member)
                                <flux:row>
                                    <flux:cell>
                                        <div class="flex items-center gap-3">
                                            <flux:avatar src="{{ $member->avatar_url }}" size="sm" />
                                            {{ $member->name }}
                                        </div>
                                    </flux:cell>
                                    <flux:cell>{{ $member->email }}</flux:cell>
                                    <flux:cell>
                                        <flux:badge variant="{{ $member->pivot->role === 'admin' ? 'primary' : 'default' }}">
                                            {{ ucfirst($member->pivot->role) }}
                                        </flux:badge>
                                    </flux:cell>
                                </flux:row>
                            @empty
                                <flux:row>
                                    <flux:cell colspan="3" class="text-center text-zinc-500">
                                        No members found
                                    </flux:cell>
                                </flux:row>
                            @endforelse
                        </flux:rows>
                    </flux:table>
                </div>
            </flux:card>

            {{-- Admin Portal Links - Added in Phase 4 --}}
            <livewire:admin-portal-links :organization="$organization" />
        </div>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle">
            You are not a member of any organization.
        </flux:callout>
    @endif
</x-layouts.app>
```

### Bootstrap Middleware Registration

**workbench/bootstrap/app.php** (modify):
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'current.organization' => \App\Http\Middleware\SetCurrentOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

## Testing Requirements

### Manual Testing

- [ ] Visit `/` - redirects to login page
- [ ] Click "Sign in with WorkOS" - redirects to WorkOS
- [ ] Complete auth - redirected to dashboard
- [ ] Check user created in database
- [ ] Organization switcher shows user's orgs
- [ ] Switch org - context updates
- [ ] Visit org settings - shows members
- [ ] Impersonation banner shows when impersonating
- [ ] Logout - session cleared

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| WorkOS auth fails | Redirect to login with error message |
| No organizations | Show "Personal" context, graceful fallback |
| Invalid org switch | Silently ignore, keep current |
| Missing user | Redirect to login |

## Validation Commands

```bash
# From workbench directory
cd workbench

# Verify routes registered
php artisan route:list | grep -E "(login|callback|logout|dashboard)"

# Verify middleware
php artisan make:middleware --help  # Ensure custom middleware works

# Test auth flow (manual)
composer serve
# Visit http://localhost:8000
# Click sign in, complete WorkOS flow
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: Check auth callback errors in logs
- **Alerting**: N/A for local development
- **Rollback plan**: Revert Livewire components and routes

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
