# Implementation Spec: AuthKit Laravel Enhancement - Phase 4

**PRD**: ./prd-phase-4.md
**Estimated Effort**: L (Large)

## Technical Approach

This phase implements the core Todo CRUD functionality and Admin Portal integration. Todos are scoped to both user and organization, demonstrating multi-tenancy. Admin Portal links use the WorkOS Portal API to generate temporary links to each intent.

Key technical decisions:
1. Use Livewire components for reactive Todo CRUD
2. Implement audit logging for all Todo actions using the package's AuditLogger
3. Create a controller for Admin Portal link generation (security: server-side only)
4. Use Flux Pro components for the UI (modals, tables, forms)
5. Filter-based todo views (all, active, completed)

The Admin Portal integration requires generating links server-side (they expire in 5 minutes) and redirecting users immediately.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `workbench/app/Livewire/TodoList.php` | Main todo list component |
| `workbench/app/Livewire/TodoItem.php` | Individual todo item component |
| `workbench/app/Livewire/AdminPortalLinks.php` | Admin portal link buttons |
| `workbench/app/Http/Controllers/AdminPortalController.php` | Generate portal links |
| `workbench/resources/views/livewire/todo-list.blade.php` | Todo list view |
| `workbench/resources/views/livewire/todo-item.blade.php` | Todo item view |
| `workbench/resources/views/livewire/admin-portal-links.blade.php` | Admin portal buttons |
| `workbench/resources/views/todos/index.blade.php` | Todo page |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `workbench/routes/web.php` | Add todo and admin portal routes |
| `workbench/resources/views/components/layouts/app.blade.php` | Ensure todo nav item works |

### Deleted Files

None.

## Implementation Details

### Todo List Component

**workbench/app/Livewire/TodoList.php**:
```php
<?php

namespace App\Livewire;

use App\Models\Todo;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use WorkOS\AuthKit\Facades\WorkOS;

class TodoList extends Component
{
    public string $newTodo = '';
    public string $filter = 'all';

    protected $listeners = ['todoDeleted' => '$refresh'];

    public function addTodo(): void
    {
        $this->validate([
            'newTodo' => 'required|string|max:255',
        ]);

        $user = auth()->user();
        $organization = request()->attributes->get('current_organization');

        $todo = Todo::create([
            'user_id' => $user->id,
            'organization_id' => $organization?->id,
            'title' => $this->newTodo,
            'completed' => false,
        ]);

        // Audit log
        WorkOS::audit('todo.created', [
            ['type' => 'todo', 'id' => (string) $todo->id, 'name' => $todo->title],
        ]);

        $this->newTodo = '';
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    #[Computed]
    public function todos()
    {
        $user = auth()->user();
        $organization = request()->attributes->get('current_organization');

        $query = Todo::where('user_id', $user->id);

        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        return match ($this->filter) {
            'active' => $query->where('completed', false)->latest()->get(),
            'completed' => $query->where('completed', true)->latest()->get(),
            default => $query->latest()->get(),
        };
    }

    #[Computed]
    public function counts()
    {
        $user = auth()->user();
        $organization = request()->attributes->get('current_organization');

        $query = Todo::where('user_id', $user->id);
        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        return [
            'all' => (clone $query)->count(),
            'active' => (clone $query)->where('completed', false)->count(),
            'completed' => (clone $query)->where('completed', true)->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.todo-list');
    }
}
```

**workbench/resources/views/livewire/todo-list.blade.php**:
```blade
<div>
    {{-- Add Todo Form --}}
    <form wire:submit="addTodo" class="mb-6">
        <div class="flex gap-3">
            <flux:input
                wire:model="newTodo"
                placeholder="What needs to be done?"
                class="flex-1"
            />
            <flux:button type="submit" variant="primary">
                <flux:icon.plus class="mr-2" />
                Add
            </flux:button>
        </div>
        @error('newTodo')
            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
        @enderror
    </form>

    {{-- Filters --}}
    <div class="flex gap-2 mb-4">
        <flux:button
            wire:click="setFilter('all')"
            :variant="$filter === 'all' ? 'primary' : 'ghost'"
            size="sm"
        >
            All ({{ $this->counts['all'] }})
        </flux:button>
        <flux:button
            wire:click="setFilter('active')"
            :variant="$filter === 'active' ? 'primary' : 'ghost'"
            size="sm"
        >
            Active ({{ $this->counts['active'] }})
        </flux:button>
        <flux:button
            wire:click="setFilter('completed')"
            :variant="$filter === 'completed' ? 'primary' : 'ghost'"
            size="sm"
        >
            Completed ({{ $this->counts['completed'] }})
        </flux:button>
    </div>

    {{-- Todo List --}}
    <div class="space-y-2">
        @forelse ($this->todos as $todo)
            <livewire:todo-item :todo="$todo" :key="$todo->id" />
        @empty
            <flux:card class="text-center py-8">
                <flux:icon.clipboard-document-list class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    @if ($filter === 'completed')
                        No completed todos yet.
                    @elseif ($filter === 'active')
                        All caught up! No active todos.
                    @else
                        No todos yet. Add one above!
                    @endif
                </flux:text>
            </flux:card>
        @endforelse
    </div>
</div>
```

### Todo Item Component

**workbench/app/Livewire/TodoItem.php**:
```php
<?php

namespace App\Livewire;

use App\Models\Todo;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Facades\WorkOS;

class TodoItem extends Component
{
    public Todo $todo;
    public bool $confirmingDelete = false;

    public function toggle(): void
    {
        $this->todo->completed = !$this->todo->completed;
        $this->todo->save();

        $action = $this->todo->completed ? 'todo.completed' : 'todo.uncompleted';
        WorkOS::audit($action, [
            ['type' => 'todo', 'id' => (string) $this->todo->id, 'name' => $this->todo->title],
        ]);
    }

    public function confirmDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function delete(): void
    {
        $todoId = $this->todo->id;
        $todoTitle = $this->todo->title;

        $this->todo->delete();

        WorkOS::audit('todo.deleted', [
            ['type' => 'todo', 'id' => (string) $todoId, 'name' => $todoTitle],
        ]);

        $this->dispatch('todoDeleted');
    }

    public function render(): View
    {
        return view('livewire.todo-item');
    }
}
```

**workbench/resources/views/livewire/todo-item.blade.php**:
```blade
<div>
    <flux:card class="flex items-center gap-3 p-3 {{ $todo->completed ? 'opacity-60' : '' }}">
        <flux:checkbox
            wire:click="toggle"
            :checked="$todo->completed"
        />

        <span class="flex-1 {{ $todo->completed ? 'line-through text-zinc-500' : '' }}">
            {{ $todo->title }}
        </span>

        <flux:text class="text-xs text-zinc-400">
            {{ $todo->created_at->diffForHumans() }}
        </flux:text>

        <flux:button
            wire:click="confirmDelete"
            variant="ghost"
            size="sm"
            class="text-red-500 hover:text-red-600"
        >
            <flux:icon.trash class="w-4 h-4" />
        </flux:button>
    </flux:card>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="confirmingDelete" class="max-w-md">
        <flux:heading size="lg">Delete Todo?</flux:heading>

        <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
            Are you sure you want to delete "{{ $todo->title }}"? This action cannot be undone.
        </flux:text>

        <div class="flex justify-end gap-3 mt-6">
            <flux:button wire:click="cancelDelete" variant="ghost">
                Cancel
            </flux:button>
            <flux:button wire:click="delete" variant="danger">
                Delete
            </flux:button>
        </div>
    </flux:modal>
</div>
```

### Admin Portal Links Component

**workbench/app/Livewire/AdminPortalLinks.php**:
```php
<?php

namespace App\Livewire;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AdminPortalLinks extends Component
{
    public ?Organization $organization = null;

    public array $intents = [
        'sso' => [
            'label' => 'Single Sign-On',
            'description' => 'Configure SAML or OIDC identity provider',
            'icon' => 'key',
        ],
        'dsync' => [
            'label' => 'Directory Sync',
            'description' => 'Sync users and groups from your directory',
            'icon' => 'users',
        ],
        'audit_logs' => [
            'label' => 'Audit Logs',
            'description' => 'View security and activity logs',
            'icon' => 'document-text',
        ],
        'log_streams' => [
            'label' => 'Log Streams',
            'description' => 'Export logs to your SIEM',
            'icon' => 'arrow-trending-up',
        ],
        'domain_verification' => [
            'label' => 'Domain Verification',
            'description' => 'Verify ownership of your domain',
            'icon' => 'shield-check',
        ],
        'certificate_renewal' => [
            'label' => 'Certificate Renewal',
            'description' => 'Renew SAML certificates',
            'icon' => 'document-check',
        ],
    ];

    public function render(): View
    {
        return view('livewire.admin-portal-links');
    }
}
```

**workbench/resources/views/livewire/admin-portal-links.blade.php**:
```blade
<flux:card>
    <flux:heading size="lg">Admin Portal</flux:heading>
    <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
        Configure enterprise features for your organization.
    </flux:text>

    @if ($organization)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($intents as $intent => $config)
                <a
                    href="{{ route('admin-portal.redirect', ['intent' => $intent]) }}"
                    class="block p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                >
                    <div class="flex items-start gap-3">
                        <div class="p-2 bg-zinc-100 dark:bg-zinc-700 rounded-lg">
                            @switch($config['icon'])
                                @case('key')
                                    <flux:icon.key class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                    @break
                                @case('users')
                                    <flux:icon.users class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                    @break
                                @case('document-text')
                                    <flux:icon.document-text class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                    @break
                                @case('arrow-trending-up')
                                    <flux:icon.arrow-trending-up class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                    @break
                                @case('shield-check')
                                    <flux:icon.shield-check class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                    @break
                                @case('document-check')
                                    <flux:icon.document-check class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                    @break
                            @endswitch
                        </div>
                        <div>
                            <flux:heading size="sm">{{ $config['label'] }}</flux:heading>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $config['description'] }}
                            </flux:text>
                        </div>
                        <flux:icon.arrow-top-right-on-square class="w-4 h-4 text-zinc-400 ml-auto" />
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle">
            Admin Portal requires an organization. Please join or create an organization first.
        </flux:callout>
    @endif
</flux:card>
```

### Admin Portal Controller

**workbench/app/Http/Controllers/AdminPortalController.php**:
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Facades\WorkOS;

class AdminPortalController extends Controller
{
    private const VALID_INTENTS = [
        'sso',
        'dsync',
        'audit_logs',
        'log_streams',
        'domain_verification',
        'certificate_renewal',
    ];

    public function redirect(Request $request, string $intent): RedirectResponse
    {
        if (!in_array($intent, self::VALID_INTENTS, true)) {
            return redirect()
                ->route('organizations.settings')
                ->with('error', 'Invalid Admin Portal intent.');
        }

        $organization = $request->attributes->get('current_organization');

        if (!$organization) {
            return redirect()
                ->route('organizations.settings')
                ->with('error', 'No organization selected.');
        }

        try {
            /** @var \WorkOS\Portal $portal */
            $portal = WorkOS::portal();

            $link = $portal->generateLink(
                organization: $organization->workos_id,
                intent: $intent,
                returnUrl: route('organizations.settings'),
                successUrl: route('organizations.settings'),
            );

            return redirect($link->link);
        } catch (\Exception $e) {
            report($e);

            return redirect()
                ->route('organizations.settings')
                ->with('error', 'Failed to generate Admin Portal link. Please try again.');
        }
    }
}
```

### Todos Index Page

**workbench/resources/views/todos/index.blade.php**:
```blade
<x-layouts.app>
    <x-slot name="title">Todos</x-slot>

    <div class="max-w-2xl mx-auto">
        <flux:heading size="xl" level="1" class="mb-6">
            Todos
            @if ($currentOrganization)
                <flux:badge variant="outline" class="ml-2">{{ $currentOrganization->name }}</flux:badge>
            @endif
        </flux:heading>

        <livewire:todo-list />
    </div>
</x-layouts.app>
```

### Updated Routes

**workbench/routes/web.php** (additions):
```php
<?php

use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\TodoController;
use Illuminate\Support\Facades\Route;

// Guest routes
Route::view('/', 'auth.login')->name('home')->middleware('guest');

// Protected routes
Route::middleware(['auth:workos', \App\Http\Middleware\SetCurrentOrganization::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Todo routes
    Route::get('/todos', fn () => view('todos.index'))->name('todos.index');

    // Organization routes
    Route::prefix('organizations')->name('organizations.')->group(function () {
        Route::get('/settings', [OrganizationController::class, 'settings'])->name('settings');
    });

    // Admin Portal routes
    Route::get('/admin-portal/{intent}', [AdminPortalController::class, 'redirect'])
        ->name('admin-portal.redirect');
});
```

## API Design

### Admin Portal Endpoint

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin-portal/{intent}` | Generates portal link and redirects |

**Valid intents**: `sso`, `dsync`, `audit_logs`, `log_streams`, `domain_verification`, `certificate_renewal`

**Response**: HTTP 302 redirect to WorkOS Admin Portal

**Error responses**:
- Invalid intent → Redirect to settings with error
- No organization → Redirect to settings with error
- WorkOS API error → Redirect to settings with error

## Testing Requirements

### Manual Testing

- [ ] Create new todo - appears in list
- [ ] Toggle todo completion - status updates
- [ ] Delete todo - confirmation modal appears
- [ ] Confirm delete - todo removed
- [ ] Filter todos - correct items shown
- [ ] Empty state shows when no todos
- [ ] Click SSO link - redirects to WorkOS Admin Portal
- [ ] Click Directory Sync link - redirects correctly
- [ ] Click Audit Logs link - redirects correctly
- [ ] Click Log Streams link - redirects correctly
- [ ] Click Domain Verification link - redirects correctly
- [ ] Click Certificate Renewal link - redirects correctly
- [ ] Return from Admin Portal - lands on settings page

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| Empty todo title | Validation error message |
| Delete fails | Show error toast |
| Admin Portal API error | Redirect with error flash message |
| Invalid intent | Redirect with error flash message |
| No organization selected | Show warning callout |
| WorkOS API unavailable | Log error, show generic message |

## Validation Commands

```bash
# From workbench directory
cd workbench

# Build assets
npm run build

# Verify routes
php artisan route:list | grep -E "(todos|admin-portal)"

# Test Livewire components exist
php artisan livewire:make --help

# Serve and test
php artisan serve
# Visit /todos and test CRUD
# Visit /organizations/settings and test Admin Portal links
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: Check WorkOS API errors in logs
- **Alerting**: N/A for local development
- **Rollback plan**: Revert Livewire components and routes

## Open Items

- [ ] Confirm audit log schema matches WorkOS expectations
- [ ] Decide on todo title max length (currently 255)

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
