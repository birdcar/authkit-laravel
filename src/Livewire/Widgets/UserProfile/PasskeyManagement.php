<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserProfile;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithElevatedAccess;
use WorkOS\AuthKit\Livewire\Concerns\WithUserProfileToken;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class PasskeyManagement extends Component
{
    use WithElevatedAccess;
    use WithUserProfileToken;
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<int, array<string, mixed>> */
    public array $passkeys = [];

    public bool $loading = true;

    public ?string $error = null;

    public bool $showElevatedPrompt = false;

    public string $verificationCode = '';

    public string $pendingAction = '';

    public ?string $pendingPasskeyId = null;

    /** @var array<string, mixed>|null */
    public ?array $registrationOptions = null;

    protected function widgetScope(): string
    {
        return '';
    }

    public function mount(): void
    {
        $this->loadPasskeys();
    }

    public function loadPasskeys(): void
    {
        $this->loading = true;
        $this->error = null;

        $result = $this->widgetGet('/UserProfile/passkeys');

        if (isset($result['data']) && is_array($result['data'])) {
            $this->passkeys = $result['data'];
        } elseif (is_array($result)) {
            $this->passkeys = $result;
        }

        $this->loading = false;
    }

    public function requestElevatedAccess(string $action, ?string $passkeyId = null): void
    {
        $this->pendingAction = $action;
        $this->pendingPasskeyId = $passkeyId;
        $this->showElevatedPrompt = true;
    }

    public function verifyAndProceed(): void
    {
        $token = $this->acquireElevatedToken($this->verificationCode);

        if ($token === null) {
            $this->addError('verificationCode', 'Verification failed. Please try again.');

            return;
        }

        $this->showElevatedPrompt = false;
        $this->verificationCode = '';

        match ($this->pendingAction) {
            'register-passkey' => $this->startPasskeyRegistration(),
            'remove-passkey' => $this->pendingPasskeyId !== null ? $this->removePasskey($this->pendingPasskeyId) : null,
            default => null,
        };

        $this->pendingPasskeyId = null;
        $this->pendingAction = '';
    }

    public function startPasskeyRegistration(): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->requestElevatedAccess('register-passkey');

            return;
        }

        $result = $this->widgetPost('/UserProfile/passkeys', [], $this->elevatedHeaders());

        if (! empty($result)) {
            $this->registrationOptions = $result;
            $this->dispatch('passkey-registration-options-ready', options: $result);
        }
    }

    /**
     * Called by Alpine.js after the browser WebAuthn navigator.credentials.create() call completes.
     *
     * @param  array<string, mixed>  $credential
     */
    public function completePasskeyRegistration(array $credential): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->addError('widget', 'Elevated access expired. Please verify again.');

            return;
        }

        $result = $this->widgetPost('/UserProfile/passkeys/verify', $credential, $this->elevatedHeaders());

        if (! empty($result)) {
            $passkeyId = (string) ($result['id'] ?? '');
            $this->registrationOptions = null;
            $this->clearElevatedToken();
            $this->loadPasskeys();
            $this->dispatch('passkey-registered', passkeyId: $passkeyId);
        }
    }

    public function removePasskey(string $passkeyId): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->requestElevatedAccess('remove-passkey', $passkeyId);

            return;
        }

        $result = $this->widgetDelete("/UserProfile/passkeys/{$passkeyId}", $this->elevatedHeaders());

        if (! empty($result) || $result !== null) {
            $this->clearElevatedToken();
            $this->loadPasskeys();
            $this->dispatch('passkey-removed', passkeyId: $passkeyId);
        }
    }

    public function cancelRegistration(): void
    {
        $this->registrationOptions = null;
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-profile.passkey-management');
    }
}
