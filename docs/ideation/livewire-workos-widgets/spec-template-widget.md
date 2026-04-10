# Widget Implementation Template

**Contract**: ./contract.md
**Depends on**: Phase 1 (Infrastructure)

## Pattern

For each widget group, create:

1. **Sub-components** — Granular Livewire components for each logical section
   - `src/Livewire/Widgets/{WidgetGroup}/{SubComponent}.php`
   - `resources/views/livewire/widgets/{widget-group}/{sub-component}.blade.php`

2. **Composed parent** — Pre-assembled component that includes all sub-components
   - `src/Livewire/Widgets/{WidgetGroup}/{WidgetGroup}.php`
   - `resources/views/livewire/widgets/{widget-group}/{widget-group}.blade.php`

3. **Service provider registration** — Register all components in `configureLivewireWidgets()`

4. **Tests** — Unit tests for API interactions, feature tests for rendering

5. **CSS additions** — Add `.woswidgets-*` class definitions to `resources/css/widgets.css`

## Component Class Pattern

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\{WidgetGroup};

use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetToken;

class {SubComponent} extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;
    use WithWidgetToken;

    // Widget-specific state
    public array $data = [];
    public bool $loading = true;
    public ?string $error = null;

    protected function widgetScope(): string
    {
        return '{widget-scope}'; // e.g., 'widgets:users-table:manage'
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('{endpoint-path}', $this->queryParams());

        if (isset($result['data'])) {
            $this->data = $result['data'];
        }

        $this->loading = false;
    }

    // Widget-specific mutations
    public function {mutationMethod}(/* params */): void
    {
        $result = $this->widgetPost('{endpoint-path}', [/* body */]);

        if (! empty($result)) {
            $this->dispatch('{event-name}');
            $this->loadData(); // refresh
        }
    }

    public function render()
    {
        return view('workos::livewire.widgets.{widget-group}.{sub-component}');
    }

    protected function queryParams(): array
    {
        return [];
    }
}
```

## Blade View Pattern

```blade
<div class="{{ $this->themeClass() }} woswidgets-{widget-group}" style="{{ $this->themeStyles() }}">
    @if($loading)
        <div class="woswidgets-skeleton">
            {{-- Loading skeleton matching official widget structure --}}
        </div>
    @elseif($error)
        <div class="woswidgets-error">
            <p>{{ $error }}</p>
            <button wire:click="loadData" class="woswidgets-retry-button">Retry</button>
        </div>
    @elseif(empty($data))
        <div class="woswidgets-empty-state">
            {{-- Empty state with icon and message --}}
        </div>
    @else
        {{-- Widget content --}}
    @endif
</div>
```

## Composed Parent Pattern

```php
class {WidgetGroup} extends Component
{
    use WithWidgetTheme;

    public function render()
    {
        return view('workos::livewire.widgets.{widget-group}.{widget-group}');
    }
}
```

```blade
<div class="{{ $this->themeClass() }} woswidgets-{widget-group}-container" style="{{ $this->themeStyles() }}">
    <livewire:workos-{sub-component-1} :accent-color="$accentColor" ... />
    <livewire:workos-{sub-component-2} :accent-color="$accentColor" ... />
</div>
```

## Service Provider Registration Pattern

```php
// Inside configureLivewireWidgets()
Livewire::component('workos-{widget-name}', \WorkOS\AuthKit\Livewire\Widgets\{Group}\{Class}::class);
```

## Test Pattern

```php
use Livewire\Livewire;

test('{widget} loads data on mount', function () {
    WorkOS::fake();
    // Mock HTTP responses for widget endpoints

    Livewire::test({SubComponent}::class)
        ->assertSet('loading', false)
        ->assertSet('error', null)
        ->assertViewHas('data');
});

test('{widget} handles API errors', function () {
    WorkOS::fake();
    // Mock error response

    Livewire::test({SubComponent}::class)
        ->assertSet('error', 'Expected error message');
});
```

## CSS Class Naming Convention

Match official `.woswidgets-*` classes:
- Container: `.woswidgets-{widget-group}`
- List items: `.woswidgets-card-list-item`
- Text fields: `.woswidgets-text-field`
- Buttons: `.woswidgets-save-button`, `.woswidgets-cancel-button`
- Status: `.woswidgets-status`, `.woswidgets-status--active`, `.woswidgets-status--inactive`
- Dialogs: `.woswidgets-dialog`
- Markers/badges: `.woswidgets-marker`

## Validation

```bash
# Run package tests
composer test

# Static analysis
composer analyse

# Code style
composer format:test

# Verify component registration
php artisan livewire:list | grep workos
```

---

_Use this template with the per-phase delta files below._
