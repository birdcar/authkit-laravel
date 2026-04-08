<div class="{{ $this->themeClass() }} woswidgets-authentication-info" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <h3 class="woswidgets-section-title">Authentication</h3>

        @if($loading)
            <div class="woswidgets-skeleton">
                <div class="woswidgets-skeleton-line" style="width: 70%"></div>
                <div class="woswidgets-skeleton-line" style="width: 50%"></div>
                <div class="woswidgets-skeleton-line" style="width: 60%"></div>
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadAuthInfo" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($authInfo))
            <div class="woswidgets-empty-state">
                <p>Authentication information not available.</p>
            </div>
        @else
            <ul class="woswidgets-card-list">
                {{-- Email verification --}}
                <li class="woswidgets-card-list-item">
                    <div class="woswidgets-card-list-item-content">
                        <span class="woswidgets-card-list-item-label">Email</span>
                        <span class="woswidgets-status woswidgets-status--{{ ($authInfo['emailVerified'] ?? false) ? 'active' : 'inactive' }}">
                            {{ ($authInfo['emailVerified'] ?? false) ? 'Verified' : 'Unverified' }}
                        </span>
                    </div>
                    @if(!($authInfo['emailVerified'] ?? false))
                        <div class="woswidgets-card-list-item-action">
                            @if($verificationSent)
                                <span class="woswidgets-marker">Verification sent</span>
                            @else
                                <button
                                    wire:click="sendVerification"
                                    class="woswidgets-button woswidgets-cancel-button"
                                    wire:loading.attr="disabled"
                                    wire:target="sendVerification"
                                >
                                    Resend verification
                                </button>
                            @endif
                        </div>
                    @endif
                </li>

                {{-- MFA status --}}
                @if(isset($authInfo['mfaEnabled']))
                    <li class="woswidgets-card-list-item">
                        <div class="woswidgets-card-list-item-content">
                            <span class="woswidgets-card-list-item-label">Multi-factor authentication</span>
                            <span class="woswidgets-status woswidgets-status--{{ $authInfo['mfaEnabled'] ? 'active' : 'inactive' }}">
                                {{ $authInfo['mfaEnabled'] ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                    </li>
                @endif

                {{-- Connected accounts / OAuth providers --}}
                @if(!empty($authInfo['connectedAccounts']) && is_array($authInfo['connectedAccounts']))
                    @foreach($authInfo['connectedAccounts'] as $account)
                        <li class="woswidgets-card-list-item">
                            <div class="woswidgets-card-list-item-content">
                                <span class="woswidgets-card-list-item-label">
                                    {{ $account['provider'] ?? 'Connected account' }}
                                </span>
                                <span class="woswidgets-marker">Connected</span>
                            </div>
                            @if(!empty($account['externalId']))
                                <span class="woswidgets-card-list-item-meta">{{ $account['externalId'] }}</span>
                            @endif
                        </li>
                    @endforeach
                @endif
            </ul>

            @error('widget') <p class="woswidgets-error-message">{{ $message }}</p> @enderror
        @endif
    </div>
</div>
