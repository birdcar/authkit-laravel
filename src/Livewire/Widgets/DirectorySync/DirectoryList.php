<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\DirectorySync;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class DirectoryList extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $directories = [];

    public bool $loading = true;

    public ?string $error = null;

    /** @var array<string, string> */
    protected const DIRECTORY_TYPE_LABELS = [
        'azure_scim_v2_0' => 'Azure SCIM',
        'bamboo_hr' => 'BambooHR',
        'breathe_hr' => 'Breathe HR',
        'cezanne_hr' => 'Cezanne HR',
        'cyberark_scim_v2_0' => 'CyberArk SCIM',
        'fourth_hr' => 'Fourth HR',
        'generic_scim_v2_0' => 'Generic SCIM',
        'gsuite_directory' => 'Google Workspace',
        'hibob' => 'HiBob',
        'jumpcloud_scim_v2_0' => 'JumpCloud SCIM',
        'okta_scim_v2_0' => 'Okta SCIM',
        'onelogin_scim_v2_0' => 'OneLogin SCIM',
        'people_hr' => 'PeopleHR',
        'personio' => 'Personio',
        'pingfederate_scim_v2_0' => 'PingFederate SCIM',
        'rippling_scim_v2_0' => 'Rippling SCIM',
        'workday' => 'Workday',
    ];

    protected function widgetScope(): string
    {
        return 'widgets:directory-sync:manage';
    }

    public function mount(): void
    {
        $this->loadDirectories();
    }

    public function loadDirectories(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/directory-sync/directories');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->directories = $result['data'];
        }

        $this->loading = false;
    }

    public function removeDirectory(string $directoryId): void
    {
        $this->resetErrorBag('widget');
        $this->widgetDelete("/directory-sync/directories/{$directoryId}");

        if (! $this->getErrorBag()->has('widget')) {
            $this->dispatch('directory-removed', directoryId: $directoryId);
            $this->loadDirectories();
        }
    }

    public static function typeLabel(string $type): string
    {
        return self::DIRECTORY_TYPE_LABELS[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.directory-sync.directory-list');
    }
}
