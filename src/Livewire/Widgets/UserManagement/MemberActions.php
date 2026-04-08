<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserManagement;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class MemberActions extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    public string $userId;

    /** @var array<string, mixed> */
    public array $member = [];

    /** @var array<int, array<string, mixed>> */
    public array $roles = [];

    public bool $loading = true;

    public ?string $error = null;

    public bool $showRoleEditor = false;

    public bool $showRemoveConfirm = false;

    public string $selectedRole = '';

    protected function widgetScope(): string
    {
        return 'widgets:users-table:manage';
    }

    public function mount(string $userId, ?string $currentRole = null): void
    {
        $this->userId = $userId;
        $this->selectedRole = $currentRole ?? '';
        $this->loadRoles();
    }

    public function loadRoles(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/UserManagement/roles');

        if (is_array($result)) {
            $this->roles = $result;
        }

        $this->loading = false;
    }

    public function updateRole(): void
    {
        if ($this->selectedRole === '') {
            return;
        }

        $result = $this->widgetPost("/UserManagement/members/{$this->userId}", [
            'roles' => [$this->selectedRole],
        ]);

        if (! empty($result)) {
            $this->showRoleEditor = false;
            $this->dispatch('member-role-updated', userId: $this->userId, role: $this->selectedRole);
        }
    }

    public function removeMember(): void
    {
        $result = $this->widgetDelete("/UserManagement/members/{$this->userId}");

        if (! empty($result)) {
            $this->showRemoveConfirm = false;
            $this->dispatch('member-removed', userId: $this->userId);
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-management.member-actions');
    }

    /** @return array<string, mixed> */
    protected function queryParams(): array
    {
        return [];
    }
}
