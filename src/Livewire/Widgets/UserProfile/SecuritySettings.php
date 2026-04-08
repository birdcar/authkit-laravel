<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Widgets\UserProfile;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Livewire\Concerns\WithElevatedAccess;
use WorkOS\AuthKit\Livewire\Concerns\WithUserProfileToken;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetApi;
use WorkOS\AuthKit\Livewire\Concerns\WithWidgetTheme;

class SecuritySettings extends Component
{
    use WithElevatedAccess;
    use WithUserProfileToken;
    use WithWidgetApi;
    use WithWidgetTheme;

    /** @var array<string, mixed> */
    public array $authInfo = [];

    public bool $loading = true;

    public ?string $error = null;

    public bool $hasPassword = false;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $confirmPassword = '';

    public bool $showPasswordForm = false;

    public bool $showElevatedPrompt = false;

    public string $verificationCode = '';

    public string $pendingAction = '';

    public ?string $totpUri = null;

    public ?string $totpSecret = null;

    public bool $showTotpSetup = false;

    public string $totpVerificationCode = '';

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
            $this->hasPassword = (bool) ($result['hasPassword'] ?? false);
        }

        $this->loading = false;
    }

    public function updatePassword(): void
    {
        if ($this->newPassword !== $this->confirmPassword) {
            $this->addError('confirmPassword', 'Passwords do not match.');

            return;
        }

        $result = $this->widgetPost('/UserProfile/update-password', [
            'currentPassword' => $this->currentPassword,
            'newPassword' => $this->newPassword,
        ]);

        if (! empty($result)) {
            $this->currentPassword = '';
            $this->newPassword = '';
            $this->confirmPassword = '';
            $this->showPasswordForm = false;
            $this->dispatch('password-changed');
        }
    }

    public function requestElevatedAccess(string $action): void
    {
        $this->pendingAction = $action;
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
            'create-password' => $this->createPassword(),
            'create-totp' => $this->createTotpFactor(),
            'disable-totp' => $this->disableTotpFactor(),
            default => null,
        };

        $this->pendingAction = '';
    }

    public function createPassword(): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->requestElevatedAccess('create-password');

            return;
        }

        if ($this->newPassword !== $this->confirmPassword) {
            $this->addError('confirmPassword', 'Passwords do not match.');

            return;
        }

        $result = $this->widgetPost('/UserProfile/create-password', [
            'password' => $this->newPassword,
        ], $this->elevatedHeaders());

        if (! empty($result)) {
            $this->hasPassword = true;
            $this->newPassword = '';
            $this->confirmPassword = '';
            $this->showPasswordForm = false;
            $this->clearElevatedToken();
            $this->dispatch('password-changed');
        }
    }

    public function createTotpFactor(): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->requestElevatedAccess('create-totp');

            return;
        }

        $result = $this->widgetPost('/UserProfile/create-totp-factor', [], $this->elevatedHeaders());

        if (! empty($result)) {
            $this->totpUri = (string) ($result['totpUri'] ?? '');
            $this->totpSecret = (string) ($result['secret'] ?? '');
            $this->showTotpSetup = true;
        }
    }

    public function verifyTotpFactor(): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->requestElevatedAccess('create-totp');

            return;
        }

        $result = $this->widgetPost('/UserProfile/verify-totp-factor', [
            'code' => $this->totpVerificationCode,
        ], $this->elevatedHeaders());

        if (! empty($result)) {
            $this->totpUri = null;
            $this->totpSecret = null;
            $this->totpVerificationCode = '';
            $this->showTotpSetup = false;
            $this->clearElevatedToken();
            $this->loadAuthInfo();
            $this->dispatch('totp-enabled');
        }
    }

    public function disableTotpFactor(): void
    {
        if (! $this->hasElevatedAccess()) {
            $this->requestElevatedAccess('disable-totp');

            return;
        }

        $result = $this->widgetDelete('/UserProfile/totp-factors', $this->elevatedHeaders());

        if (! empty($result) || $result !== null) {
            $this->clearElevatedToken();
            $this->loadAuthInfo();
            $this->dispatch('totp-disabled');
        }
    }

    public function render(): View
    {
        return view('workos::livewire.widgets.user-profile.security-settings');
    }
}
