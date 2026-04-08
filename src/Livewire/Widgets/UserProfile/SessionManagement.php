<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserProfile;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithUserProfileToken;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class SessionManagement extends Component
{
    use WithUserProfileToken;
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $sessions = [];

    public bool $loading = true;

    public ?string $error = null;

    public ?string $currentSessionId = null;

    protected function widgetScope(): string
    {
        return '';
    }

    public function mount(): void
    {
        $session = workos()->validSession();

        if ($session !== null) {
            $this->currentSessionId = $session->sessionId;
        }

        $this->loadSessions();
    }

    public function loadSessions(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/UserProfile/sessions');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->sessions = $result['data'];
        } elseif (is_array($result)) {
            $this->sessions = $result;
        }

        $this->loading = false;
    }

    public function revokeSession(string $sessionId): void
    {
        $result = $this->widgetDelete("/UserProfile/sessions/revoke/{$sessionId}");

        if (! empty($result) || $result !== null) {
            $this->loadSessions();
            $this->dispatch('session-revoked', sessionId: $sessionId);
        }
    }

    public function revokeAllSessions(): void
    {
        $result = $this->widgetDelete('/UserProfile/sessions/revoke-all');

        if (! empty($result) || $result !== null) {
            $this->loadSessions();
            $this->dispatch('all-sessions-revoked');
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-profile.session-management');
    }
}
