<div class="{{ $this->themeClass() }} woswidgets-user-management-container" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-user-management-header">
        <h2 class="woswidgets-section-title">Members</h2>
        <livewire:workos-invite-user
            :accentColor="$accentColor"
            :borderColor="$borderColor"
            :backgroundColor="$backgroundColor"
            :foregroundColor="$foregroundColor"
            :appearance="$appearance"
        />
    </div>

    <livewire:workos-members-table
        :accentColor="$accentColor"
        :borderColor="$borderColor"
        :backgroundColor="$backgroundColor"
        :foregroundColor="$foregroundColor"
        :appearance="$appearance"
    />
</div>
