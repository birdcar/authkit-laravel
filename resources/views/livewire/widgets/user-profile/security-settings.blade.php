<div class="{{ $this->themeClass() }} woswidgets-security-settings" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <h3 class="woswidgets-section-title">Security</h3>

        @if($loading)
            <div class="woswidgets-skeleton">
                <div class="woswidgets-skeleton-line" style="width: 60%"></div>
                <div class="woswidgets-skeleton-line" style="width: 80%"></div>
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadAuthInfo" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @else
            {{-- Elevated access verification prompt --}}
            @if($showElevatedPrompt)
                <div class="woswidgets-dialog">
                    <h4 class="woswidgets-dialog-title">Verify your identity</h4>
                    <p class="woswidgets-dialog-description">Enter your password or TOTP code to continue.</p>
                    <form wire:submit="verifyAndProceed">
                        <div class="woswidgets-form-group">
                            <label for="verificationCode" class="woswidgets-label">Verification</label>
                            <input
                                id="verificationCode"
                                type="password"
                                wire:model="verificationCode"
                                class="woswidgets-text-field"
                                placeholder="Password or TOTP code"
                                autofocus
                            />
                            @error('verificationCode') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                        </div>
                        @error('widget') <p class="woswidgets-error-message">{{ $message }}</p> @enderror
                        <div class="woswidgets-action-row">
                            <button type="submit" class="woswidgets-button woswidgets-save-button" wire:loading.attr="disabled">
                                Verify
                            </button>
                            <button type="button" wire:click="$set('showElevatedPrompt', false)" class="woswidgets-button woswidgets-cancel-button">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Password section --}}
            <div class="woswidgets-section">
                <div class="woswidgets-section-header">
                    <div>
                        <h4 class="woswidgets-subsection-title">Password</h4>
                        <p class="woswidgets-section-description">
                            @if($hasPassword)
                                Update your existing password.
                            @else
                                Set a password to enable password-based login.
                            @endif
                        </p>
                    </div>
                    @if(!$showPasswordForm && !$showElevatedPrompt)
                        <button wire:click="$set('showPasswordForm', true)" class="woswidgets-button woswidgets-edit-button">
                            {{ $hasPassword ? 'Change password' : 'Set password' }}
                        </button>
                    @endif
                </div>

                @if($showPasswordForm && !$showElevatedPrompt)
                    <form wire:submit="{{ $hasPassword ? 'updatePassword' : 'createPassword' }}" class="woswidgets-form">
                        @if($hasPassword)
                            <div class="woswidgets-form-group">
                                <label for="currentPassword" class="woswidgets-label">Current password</label>
                                <input
                                    id="currentPassword"
                                    type="password"
                                    wire:model="currentPassword"
                                    class="woswidgets-text-field"
                                    placeholder="Current password"
                                    autocomplete="current-password"
                                />
                                @error('currentPassword') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                            </div>
                        @endif
                        <div class="woswidgets-form-group">
                            <label for="newPassword" class="woswidgets-label">New password</label>
                            <input
                                id="newPassword"
                                type="password"
                                wire:model="newPassword"
                                class="woswidgets-text-field"
                                placeholder="New password"
                                autocomplete="new-password"
                            />
                            @error('newPassword') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="woswidgets-form-group">
                            <label for="confirmPassword" class="woswidgets-label">Confirm password</label>
                            <input
                                id="confirmPassword"
                                type="password"
                                wire:model="confirmPassword"
                                class="woswidgets-text-field"
                                placeholder="Confirm new password"
                                autocomplete="new-password"
                            />
                            @error('confirmPassword') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                        </div>
                        @error('widget') <p class="woswidgets-error-message">{{ $message }}</p> @enderror
                        <div class="woswidgets-action-row">
                            <button type="submit" class="woswidgets-button woswidgets-save-button" wire:loading.attr="disabled">
                                {{ $hasPassword ? 'Update password' : 'Set password' }}
                            </button>
                            <button type="button" wire:click="$set('showPasswordForm', false)" class="woswidgets-button woswidgets-cancel-button">
                                Cancel
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- TOTP section --}}
            <div class="woswidgets-section">
                <div class="woswidgets-section-header">
                    <div>
                        <h4 class="woswidgets-subsection-title">Authenticator app</h4>
                        <p class="woswidgets-section-description">
                            @if(!empty($authInfo['totpEnabled']))
                                TOTP authenticator is enabled.
                            @else
                                Add an authenticator app for two-factor authentication.
                            @endif
                        </p>
                    </div>
                    @if(!$showTotpSetup && !$showElevatedPrompt)
                        @if(!empty($authInfo['totpEnabled']))
                            <button wire:click="disableTotpFactor" class="woswidgets-button woswidgets-destructive-button">
                                Disable
                            </button>
                        @else
                            <button wire:click="createTotpFactor" class="woswidgets-button woswidgets-edit-button">
                                Set up
                            </button>
                        @endif
                    @endif
                </div>

                @if($showTotpSetup && $totpUri)
                    <div class="woswidgets-totp-setup">
                        <p class="woswidgets-section-description">Scan this QR code with your authenticator app, then enter the code below.</p>
                        <div class="woswidgets-totp-qr" data-totp-uri="{{ $totpUri }}">
                            {{-- QR code rendered via JS from data-totp-uri, or show the secret as fallback --}}
                            @if($totpSecret)
                                <p class="woswidgets-totp-secret-label">Manual entry key:</p>
                                <code class="woswidgets-totp-secret">{{ $totpSecret }}</code>
                            @endif
                        </div>
                        <form wire:submit="verifyTotpFactor" class="woswidgets-form">
                            <div class="woswidgets-form-group">
                                <label for="totpVerificationCode" class="woswidgets-label">Verification code</label>
                                <input
                                    id="totpVerificationCode"
                                    type="text"
                                    wire:model="totpVerificationCode"
                                    class="woswidgets-text-field"
                                    placeholder="6-digit code"
                                    inputmode="numeric"
                                    pattern="[0-9]{6}"
                                    maxlength="6"
                                    autocomplete="one-time-code"
                                />
                                @error('totpVerificationCode') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                            </div>
                            @error('widget') <p class="woswidgets-error-message">{{ $message }}</p> @enderror
                            <div class="woswidgets-action-row">
                                <button type="submit" class="woswidgets-button woswidgets-save-button" wire:loading.attr="disabled">
                                    Verify and enable
                                </button>
                                <button type="button" wire:click="$set('showTotpSetup', false)" class="woswidgets-button woswidgets-cancel-button">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
