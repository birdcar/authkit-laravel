<div class="{{ $this->themeClass() }} woswidgets-admin-portal-container" style="{{ $this->themeStyles() }}">
    <livewire:workos-sso-connection-list
        :accentColor="$accentColor"
        :borderColor="$borderColor"
        :backgroundColor="$backgroundColor"
        :foregroundColor="$foregroundColor"
        :appearance="$appearance"
    />

    <livewire:workos-domain-list
        :accentColor="$accentColor"
        :borderColor="$borderColor"
        :backgroundColor="$backgroundColor"
        :foregroundColor="$foregroundColor"
        :appearance="$appearance"
    />
</div>
