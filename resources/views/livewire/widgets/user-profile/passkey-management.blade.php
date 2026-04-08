<div
    class="{{ $this->themeClass() }} woswidgets-passkey-management"
    style="{{ $this->themeStyles() }}"
    x-data="{
        async registerPasskey(options) {
            try {
                // Decode base64url challenge and user ID
                options.challenge = Uint8Array.from(atob(options.challenge.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
                if (options.user && options.user.id) {
                    options.user.id = Uint8Array.from(atob(options.user.id.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
                }
                if (options.excludeCredentials) {
                    options.excludeCredentials = options.excludeCredentials.map(c => ({
                        ...c,
                        id: Uint8Array.from(atob(c.id.replace(/-/g, '+').replace(/_/g, '/')), ch => ch.charCodeAt(0)),
                    }));
                }

                const credential = await navigator.credentials.create({ publicKey: options });

                const credentialData = {
                    id: credential.id,
                    rawId: btoa(String.fromCharCode(...new Uint8Array(credential.rawId))),
                    type: credential.type,
                    response: {
                        attestationObject: btoa(String.fromCharCode(...new Uint8Array(credential.response.attestationObject))),
                        clientDataJSON: btoa(String.fromCharCode(...new Uint8Array(credential.response.clientDataJSON))),
                    },
                };

                $wire.completePasskeyRegistration(credentialData);
            } catch (err) {
                console.error('Passkey registration failed:', err);
                $wire.cancelRegistration();
            }
        }
    }"
    @passkey-registration-options-ready.window="registerPasskey($event.detail.options)"
>
    <div class="woswidgets-card">
        <div class="woswidgets-card-header">
            <h3 class="woswidgets-section-title">Passkeys</h3>
            @if(!$showElevatedPrompt)
                <button
                    wire:click="startPasskeyRegistration"
                    class="woswidgets-button woswidgets-save-button"
                    wire:loading.attr="disabled"
                    wire:target="startPasskeyRegistration"
                >
                    Add passkey
                </button>
            @endif
        </div>

        {{-- Elevated access verification prompt --}}
        @if($showElevatedPrompt)
            <div class="woswidgets-dialog">
                <h4 class="woswidgets-dialog-title">Verify your identity</h4>
                <p class="woswidgets-dialog-description">Enter your password or TOTP code to continue.</p>
                <form wire:submit="verifyAndProceed">
                    <div class="woswidgets-form-group">
                        <label for="passkeyVerificationCode" class="woswidgets-label">Verification</label>
                        <input
                            id="passkeyVerificationCode"
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

        @if($loading)
            <div class="woswidgets-skeleton">
                <div class="woswidgets-skeleton-line" style="width: 70%"></div>
                <div class="woswidgets-skeleton-line" style="width: 55%"></div>
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadPasskeys" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($passkeys))
            <div class="woswidgets-empty-state">
                <p>No passkeys registered. Add one for passwordless sign-in.</p>
            </div>
        @else
            <ul class="woswidgets-card-list">
                @foreach($passkeys as $passkey)
                    <li class="woswidgets-card-list-item">
                        <div class="woswidgets-card-list-item-content">
                            <span class="woswidgets-card-list-item-label">
                                {{ $passkey['name'] ?? 'Passkey' }}
                            </span>
                            @if(!empty($passkey['createdAt']))
                                <span class="woswidgets-card-list-item-meta">
                                    Added {{ \Carbon\Carbon::parse($passkey['createdAt'])->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                        <button
                            wire:click="removePasskey('{{ $passkey['id'] }}')"
                            class="woswidgets-button woswidgets-destructive-button"
                            wire:loading.attr="disabled"
                            wire:target="removePasskey('{{ $passkey['id'] }}')"
                            aria-label="Remove passkey {{ $passkey['name'] ?? '' }}"
                        >
                            Remove
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
