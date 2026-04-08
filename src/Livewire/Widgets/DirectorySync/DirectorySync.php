<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\DirectorySync;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class DirectorySync extends Component
{
    use WithWidgetTheme;

    public function render(): View
    {
        return view('workos::livewire.widgets.directory-sync.directory-sync');
    }
}
