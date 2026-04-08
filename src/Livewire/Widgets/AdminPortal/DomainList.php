<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\AdminPortal;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class DomainList extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $domains = [];

    public bool $loading = true;

    public ?string $error = null;

    protected function widgetScope(): string
    {
        return 'widgets:domain-verification:manage';
    }

    public function mount(): void
    {
        $this->loadDomains();
    }

    public function loadDomains(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/admin-portal/organization-domains');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->domains = $result['data'];
        }

        $this->loading = false;
    }

    public function removeDomain(string $domainId): void
    {
        $this->resetErrorBag('widget');
        $this->widgetDelete("/admin-portal/organization-domains/{$domainId}");

        if (! $this->getErrorBag()->has('widget')) {
            $this->dispatch('domain-removed', domainId: $domainId);
            $this->loadDomains();
        }
    }

    public function reverifyDomain(string $domainId): void
    {
        $this->resetErrorBag('widget');
        $this->widgetPost("/admin-portal/organization-domains/{$domainId}/reverify");

        if (! $this->getErrorBag()->has('widget')) {
            $this->dispatch('domain-reverification-started', domainId: $domainId);
            $this->loadDomains();
        }
    }

    public function generateAddDomainLink(): void
    {
        $result = $this->widgetPost('/admin-portal/generate-link', [
            'intent' => 'domain_verification',
        ]);

        if (! empty($result['link'])) {
            $this->dispatch('open-admin-portal-link', url: $result['link']);
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.admin-portal.domain-list');
    }
}
