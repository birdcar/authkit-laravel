<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\AdminPortal;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class SsoConnectionList extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $connections = [];

    public bool $loading = true;

    public ?string $error = null;

    protected function widgetScope(): string
    {
        return 'widgets:sso:manage';
    }

    public function mount(): void
    {
        $this->loadConnections();
    }

    public function loadConnections(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/admin-portal/sso-connections');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->connections = $result['data'];
        }

        $this->loading = false;
    }

    public function generateManageLink(): void
    {
        $result = $this->widgetPost('/admin-portal/generate-link', [
            'intent' => 'sso',
        ]);

        if (! empty($result['link'])) {
            $this->dispatch('open-admin-portal-link', url: $result['link']);
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.admin-portal.sso-connection-list');
    }
}
