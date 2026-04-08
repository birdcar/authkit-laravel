<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserProfile;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class UserProfile extends Component
{
    use WithWidgetTheme;

    public string $activeTab = 'profile';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-profile.user-profile');
    }
}
