<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\ApiKeys;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class ApiKeyList extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $apiKeys = [];

    /** @var array<int, array<string, mixed>> */
    public array $permissions = [];

    public bool $loading = true;

    public ?string $error = null;

    public bool $showCreateForm = false;

    public ?string $newKeyValue = null;

    public string $newKeyName = '';

    /** @var array<int, string> */
    public array $selectedPermissions = [];

    protected function widgetScope(): string
    {
        return 'widgets:api-keys:manage';
    }

    public function mount(): void
    {
        $this->loadPermissions();
        $this->loadApiKeys();
    }

    public function loadApiKeys(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/ApiKeys/organization-api-keys');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->apiKeys = $result['data'];
        }

        $this->loading = false;
    }

    public function loadPermissions(): void
    {
        $result = $this->widgetGet('/ApiKeys/permissions');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->permissions = $result['data'];
        }
    }

    public function createKey(): void
    {
        $this->resetErrorBag('widget');

        $result = $this->widgetPost('/ApiKeys/organization-api-keys', [
            'name' => $this->newKeyName,
            'permissions' => $this->selectedPermissions,
        ]);

        if (! $this->getErrorBag()->has('widget') && ! empty($result)) {
            $this->newKeyValue = is_string($result['value'] ?? null) ? $result['value'] : null;
            $this->dispatch('api-key-created', id: is_string($result['id'] ?? null) ? $result['id'] : '');
            $this->loadApiKeys();
            $this->showCreateForm = false;
            $this->newKeyName = '';
            $this->selectedPermissions = [];
        }
    }

    public function dismissNewKey(): void
    {
        $this->newKeyValue = null;
    }

    public function revokeKey(string $apiKeyId): void
    {
        $this->resetErrorBag('widget');
        $this->widgetDelete("/ApiKeys/{$apiKeyId}");

        if (! $this->getErrorBag()->has('widget')) {
            $this->dispatch('api-key-revoked', id: $apiKeyId);
            $this->loadApiKeys();
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.api-keys.api-key-list');
    }
}
