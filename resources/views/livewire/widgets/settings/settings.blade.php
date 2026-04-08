<div class="{{ $this->themeClass() }} woswidgets-settings-container" style="{{ $this->themeStyles() }}">
    <livewire:workos-organization-settings
        :accentColor="$accentColor"
        :borderColor="$borderColor"
        :backgroundColor="$backgroundColor"
        :foregroundColor="$foregroundColor"
        :appearance="$appearance"
    />
</div>
