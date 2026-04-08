<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserManagement;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class MembersTable extends Component
{
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $members = [];

    /** @var array<int, array<string, mixed>> */
    public array $roles = [];

    public bool $multipleRolesEnabled = false;

    public bool $loading = true;

    public ?string $error = null;

    public string $search = '';

    public string $roleFilter = '';

    public int $limit = 10;

    public ?string $before = null;

    public ?string $after = null;

    public bool $hasPrevious = false;

    public bool $hasNext = false;

    protected function widgetScope(): string
    {
        return 'widgets:users-table:manage';
    }

    public function mount(): void
    {
        $this->loadRolesAndConfig();
        $this->loadMembers();
    }

    public function loadRolesAndConfig(): void
    {
        $result = $this->widgetGet('/UserManagement/roles-and-config');

        if (isset($result['roles']) && is_array($result['roles'])) {
            $this->roles = $result['roles'];
        }

        if (isset($result['multipleRolesEnabled'])) {
            $this->multipleRolesEnabled = (bool) $result['multipleRolesEnabled'];
        }
    }

    public function loadMembers(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/UserManagement/members', $this->queryParams());

        if (isset($result['data']) && is_array($result['data'])) {
            $this->members = $result['data'];
        }

        if (isset($result['list_metadata']) && is_array($result['list_metadata'])) {
            $metadata = $result['list_metadata'];
            $this->hasPrevious = isset($metadata['before']) && $metadata['before'] !== null;
            $this->hasNext = isset($metadata['after']) && $metadata['after'] !== null;
            $this->before = is_string($metadata['before'] ?? null) ? $metadata['before'] : null;
            $this->after = is_string($metadata['after'] ?? null) ? $metadata['after'] : null;
        }

        $this->loading = false;
    }

    public function updatingSearch(): void
    {
        $this->resetPagination();
    }

    public function updatingRoleFilter(): void
    {
        $this->resetPagination();
    }

    public function nextPage(): void
    {
        if (! $this->hasNext || $this->after === null) {
            return;
        }

        $result = $this->widgetGet('/UserManagement/members', array_merge($this->queryParams(), [
            'after' => $this->after,
        ]));

        $this->applyMembersResult($result);
    }

    public function previousPage(): void
    {
        if (! $this->hasPrevious || $this->before === null) {
            return;
        }

        $result = $this->widgetGet('/UserManagement/members', array_merge($this->queryParams(), [
            'before' => $this->before,
        ]));

        $this->applyMembersResult($result);
    }

    public function resendInvite(string $userId): void
    {
        $result = $this->widgetPost("/UserManagement/invites/{$userId}/resend");

        if (! empty($result)) {
            $this->dispatch('invite-resent', userId: $userId);
            $this->loadMembers();
        }
    }

    public function revokeInvite(string $userId): void
    {
        $result = $this->widgetDelete("/UserManagement/invites/{$userId}");

        if (! empty($result)) {
            $this->dispatch('invite-revoked', userId: $userId);
            $this->loadMembers();
        }
    }

    #[On('member-role-updated')]
    #[On('member-removed')]
    #[On('invite-sent')]
    public function refresh(): void
    {
        $this->resetPagination();
        $this->loadMembers();
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-management.members-table');
    }

    /** @return array<string, mixed> */
    protected function queryParams(): array
    {
        $params = ['limit' => $this->limit];

        if ($this->search !== '') {
            $params['search'] = $this->search;
        }

        if ($this->roleFilter !== '') {
            $params['role'] = $this->roleFilter;
        }

        return $params;
    }

    private function resetPagination(): void
    {
        $this->before = null;
        $this->after = null;
        $this->hasPrevious = false;
        $this->hasNext = false;
    }

    /** @param array<string, mixed> $result */
    private function applyMembersResult(array $result): void
    {
        if (isset($result['data']) && is_array($result['data'])) {
            $this->members = $result['data'];
        }

        if (isset($result['list_metadata']) && is_array($result['list_metadata'])) {
            $metadata = $result['list_metadata'];
            $this->hasPrevious = isset($metadata['before']) && $metadata['before'] !== null;
            $this->hasNext = isset($metadata['after']) && $metadata['after'] !== null;
            $this->before = is_string($metadata['before'] ?? null) ? $metadata['before'] : null;
            $this->after = is_string($metadata['after'] ?? null) ? $metadata['after'] : null;
        }
    }
}
