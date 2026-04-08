<div class="{{ $this->themeClass() }} woswidgets-user-profile-container" style="{{ $this->themeStyles() }}">
    <nav class="woswidgets-tabs" aria-label="Profile sections">
        <button
            wire:click="setTab('profile')"
            class="woswidgets-tab {{ $activeTab === 'profile' ? 'woswidgets-tab--active' : '' }}"
            aria-selected="{{ $activeTab === 'profile' ? 'true' : 'false' }}"
        >
            Profile
        </button>
        <button
            wire:click="setTab('security')"
            class="woswidgets-tab {{ $activeTab === 'security' ? 'woswidgets-tab--active' : '' }}"
            aria-selected="{{ $activeTab === 'security' ? 'true' : 'false' }}"
        >
            Security
        </button>
        <button
            wire:click="setTab('sessions')"
            class="woswidgets-tab {{ $activeTab === 'sessions' ? 'woswidgets-tab--active' : '' }}"
            aria-selected="{{ $activeTab === 'sessions' ? 'true' : 'false' }}"
        >
            Sessions
        </button>
    </nav>

    <div class="woswidgets-tab-panel">
        @if($activeTab === 'profile')
            <livewire:workos-profile-info
                :accentColor="$accentColor"
                :borderColor="$borderColor"
                :backgroundColor="$backgroundColor"
                :foregroundColor="$foregroundColor"
                :appearance="$appearance"
            />
            <livewire:workos-authentication-info
                :accentColor="$accentColor"
                :borderColor="$borderColor"
                :backgroundColor="$backgroundColor"
                :foregroundColor="$foregroundColor"
                :appearance="$appearance"
            />
        @elseif($activeTab === 'security')
            <livewire:workos-security-settings
                :accentColor="$accentColor"
                :borderColor="$borderColor"
                :backgroundColor="$backgroundColor"
                :foregroundColor="$foregroundColor"
                :appearance="$appearance"
            />
            <livewire:workos-passkey-management
                :accentColor="$accentColor"
                :borderColor="$borderColor"
                :backgroundColor="$backgroundColor"
                :foregroundColor="$foregroundColor"
                :appearance="$appearance"
            />
        @elseif($activeTab === 'sessions')
            <livewire:workos-session-management
                :accentColor="$accentColor"
                :borderColor="$borderColor"
                :backgroundColor="$backgroundColor"
                :foregroundColor="$foregroundColor"
                :appearance="$appearance"
            />
        @endif
    </div>
</div>
