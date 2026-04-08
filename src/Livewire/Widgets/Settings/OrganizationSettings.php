<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class OrganizationSettings extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<string, mixed> */
    public array $settings = [];

    public bool $loading = true;

    public ?string $error = null;

    protected function widgetScope(): string
    {
        return 'widgets:settings:read';
    }

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/settings');

        if (! empty($result)) {
            $this->settings = $result;
        }

        $this->loading = false;
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.settings.organization-settings');
    }
}
