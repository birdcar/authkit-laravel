<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserManagement;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class InviteUser extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    public bool $open = false;

    public string $email = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $selectedRole = '';

    /** @var array<int, array<string, mixed>> */
    public array $roles = [];

    public bool $loading = false;

    public ?string $error = null;

    /** @var array<string, string[]> */
    protected $rules = [
        'email' => ['required', 'email'],
        'firstName' => ['nullable', 'string', 'max:255'],
        'lastName' => ['nullable', 'string', 'max:255'],
        'selectedRole' => ['nullable', 'string'],
    ];

    protected function widgetScope(): string
    {
        return 'widgets:users-table:manage';
    }

    public function mount(): void
    {
        $this->loadRoles();
    }

    public function loadRoles(): void
    {
        $result = $this->widgetGet('/UserManagement/roles');

        if (is_array($result)) {
            $this->roles = $result;
        }
    }

    public function openModal(): void
    {
        $this->reset(['email', 'firstName', 'lastName', 'selectedRole', 'error']);
        $this->resetValidation();
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->open = false;
    }

    public function sendInvite(): void
    {
        $this->validate();

        $this->loading = true;
        $this->error = null;

        $data = ['email' => $this->email, 'roles' => []];

        if ($this->firstName !== '') {
            $data['firstName'] = $this->firstName;
        }

        if ($this->lastName !== '') {
            $data['lastName'] = $this->lastName;
        }

        if ($this->selectedRole !== '') {
            $data['roles'] = [$this->selectedRole];
        }

        $result = $this->widgetPost('/UserManagement/invite-user', $data);

        $this->loading = false;

        if (! empty($result)) {
            $this->open = false;
            $this->dispatch('invite-sent', email: $this->email);
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-management.invite-user');
    }

    /** @return array<string, mixed> */
    protected function queryParams(): array
    {
        return [];
    }
}
