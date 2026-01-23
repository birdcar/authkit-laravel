<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use WorkOS\AuthKit\Events\OrganizationSwitched;

class OrganizationSwitcher extends Component
{
    public ?int $currentOrganizationId = null;

    public function mount(): void
    {
        $this->currentOrganizationId = Session::get('current_organization_id');
    }

    public function switch(int $organizationId): void
    {
        $user = auth()->user();
        $organization = Organization::find($organizationId);

        if (! $organization || ! $user->organizations->contains($organization)) {
            return;
        }

        $previousId = $this->currentOrganizationId;
        $this->currentOrganizationId = $organizationId;
        Session::put('current_organization_id', $organizationId);

        event(new OrganizationSwitched(
            $user,
            $organization->workos_id
        ));

        $this->redirect(request()->header('Referer', route('dashboard')));
    }

    public function render(): View
    {
        $user = auth()->user();
        $organizations = $user?->organizations ?? collect();
        $current = $this->currentOrganizationId
            ? $organizations->firstWhere('id', $this->currentOrganizationId)
            : $organizations->first();

        // Auto-select first organization if none selected
        if (! $this->currentOrganizationId && $current) {
            Session::put('current_organization_id', $current->id);
            $this->currentOrganizationId = $current->id;
        }

        return view('livewire.organization-switcher', [
            'organizations' => $organizations,
            'current' => $current,
        ]);
    }
}
