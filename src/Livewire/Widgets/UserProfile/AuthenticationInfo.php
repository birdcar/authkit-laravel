<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserProfile;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithUserProfileToken;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class AuthenticationInfo extends Component
{
    use WithUserProfileToken;
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<string, mixed> */
    public array $authInfo = [];

    public bool $loading = true;

    public ?string $error = null;

    public bool $verificationSent = false;

    protected function widgetScope(): string
    {
        return '';
    }

    public function mount(): void
    {
        $this->loadAuthInfo();
    }

    public function loadAuthInfo(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/UserProfile/authentication-information');

        if (! empty($result)) {
            $this->authInfo = $result;
        }

        $this->loading = false;
    }

    public function sendVerification(): void
    {
        $result = $this->widgetPost('/UserProfile/send-verification');

        if (! empty($result) || $result !== null) {
            $this->verificationSent = true;
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-profile.authentication-info');
    }
}
