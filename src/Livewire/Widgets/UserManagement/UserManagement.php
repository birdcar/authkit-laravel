<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserManagement;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class UserManagement extends Component
{
    use WithWidgetTheme;

    public function render(): View
    {
        return view('workos::livewire.widgets.user-management.user-management');
    }
}
