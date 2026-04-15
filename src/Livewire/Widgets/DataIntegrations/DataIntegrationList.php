<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\DataIntegrations;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class DataIntegrationList extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $integrations = [];

    public bool $loading = true;

    public ?string $error = null;

    protected function widgetScope(): string
    {
        return 'widgets:users-table:manage';
    }

    public function mount(): void
    {
        $this->loadIntegrations();
    }

    public function loadIntegrations(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/DataIntegrations/mine');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->integrations = $result['data'];
        }

        $this->loading = false;
    }

    public function authorize(string $slug): void
    {
        $this->resetErrorBag('widget');

        $result = $this->widgetPost("/DataIntegrations/{$slug}/authorize");

        if (! $this->getErrorBag()->has('widget')) {
            $url = $result['link'] ?? $result['url'] ?? $result['redirect_url'] ?? null;

            if (is_string($url)) {
                $dataIntegrationId = is_string($result['dataIntegrationId'] ?? $result['id'] ?? null)
                    ? ($result['dataIntegrationId'] ?? $result['id'])
                    : '';
                $state = is_string($result['state'] ?? null) ? $result['state'] : '';

                $this->dispatch('open-integration-auth-url', url: $url, dataIntegrationId: $dataIntegrationId, state: $state);
            } else {
                /** @phpstan-ignore method.notFound */
                $this->addError('widget', 'Could not retrieve authorization URL.');
            }
        }
    }

    public function checkAuthStatus(string $dataIntegrationId, string $state): void
    {
        $this->resetErrorBag('widget');

        $result = $this->widgetGet("/DataIntegrations/{$dataIntegrationId}/authorization-status/{$state}");

        if (! $this->getErrorBag()->has('widget') && ! empty($result)) {
            $status = $result['status'] ?? $result['state'] ?? null;

            if ($status === 'completed' || $status === 'active') {
                $slug = is_string($result['slug'] ?? null) ? $result['slug'] : '';
                $this->dispatch('integration-installed', slug: $slug);
                $this->loadIntegrations();
            }
        }
    }

    public function removeInstallation(string $installationId): void
    {
        $this->resetErrorBag('widget');
        $this->widgetDelete("/DataIntegrations/installations/{$installationId}");

        if (! $this->getErrorBag()->has('widget')) {
            $this->dispatch('integration-removed', installationId: $installationId);
            $this->loadIntegrations();
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.data-integrations.data-integration-list');
    }
}
