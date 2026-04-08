<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserProfile;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithUserProfileToken;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class ProfileInfo extends Component
{
    use WithUserProfileToken;
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<string, mixed> */
    public array $profile = [];

    public bool $loading = true;

    public ?string $error = null;

    public bool $editing = false;

    public string $firstName = '';

    public string $lastName = '';

    protected function widgetScope(): string
    {
        return '';
    }

    public function mount(): void
    {
        $this->loadProfile();
    }

    public function loadProfile(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/UserProfile/me');

        if (! empty($result)) {
            $this->profile = $result;
            $this->firstName = (string) ($result['firstName'] ?? '');
            $this->lastName = (string) ($result['lastName'] ?? '');
        }

        $this->loading = false;
    }

    public function startEditing(): void
    {
        $this->editing = true;
        $this->firstName = (string) ($this->profile['firstName'] ?? '');
        $this->lastName = (string) ($this->profile['lastName'] ?? '');
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->firstName = (string) ($this->profile['firstName'] ?? '');
        $this->lastName = (string) ($this->profile['lastName'] ?? '');
    }

    public function updateProfile(): void
    {
        $result = $this->widgetPost('/UserProfile/me', [
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
        ]);

        if (! empty($result)) {
            $this->profile = $result;
            $this->editing = false;
            $this->dispatch('profile-updated', firstName: $this->firstName, lastName: $this->lastName);
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-profile.profile-info');
    }
}
